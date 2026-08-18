<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\ConsignmentFinance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class LedgerPoster
{
    public function account(string $code, string $name, string $type, ?int $branchId = null, ?string $currency = null): LedgerAccount
    {
        $fullCode = $code;
        if ($branchId) {
            $fullCode .= '.b' . $branchId;
        }
        if ($currency) {
            $fullCode .= '.' . $currency;
        }

        return LedgerAccount::firstOrCreate(
            ['code' => $fullCode],
            [
                'name' => $name,
                'type' => $type,
                'branch_id' => $branchId,
                'currency' => $currency,
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<int, array{account: LedgerAccount|string, debit?: mixed, credit?: mixed, currency: string, name?: string, type?: string, branch_id?: ?int}>  $lines
     */
    public function post(
        string $memo,
        Model $source,
        array $lines,
        ?\DateTimeInterface $occurredAt = null,
        string $eventType = 'posted',
        ?string $journalCurrency = null
    ): JournalEntry {
        if (Schema::hasTable('journal_entries')) {
            $existing = JournalEntry::where('source_type', $source::class)
                ->where('source_id', $source->getKey())
                ->where('event_type', $eventType)
                ->first();
            if ($existing) {
                return $existing->load('lines');
            }
        }

        $normalized = [];
        foreach ($lines as $line) {
            $currency = $line['currency'];
            $debit = Money::of($line['debit'] ?? 0);
            $credit = Money::of($line['credit'] ?? 0);
            if (Money::isNegative($debit) || Money::isNegative($credit)) {
                throw new DomainException('مبالغ دفتر کل نمی‌توانند منفی باشند');
            }
            if (Money::isZero($debit) && Money::isZero($credit)) {
                continue;
            }
            $account = $line['account'] instanceof LedgerAccount
                ? $line['account']
                : $this->account(
                    (string) $line['account'],
                    (string) ($line['name'] ?? $line['account']),
                    (string) ($line['type'] ?? 'asset'),
                    $line['branch_id'] ?? null,
                    $currency
                );

            $normalized[] = [
                'account' => $account,
                'currency' => $currency,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        $currencies = collect($normalized)->pluck('currency')->unique();
        if ($currencies->count() > 1) {
            throw new DomainException('یک سند حسابداری نمی‌تواند چند ارز را بدون سند تبدیل ترکیب کند');
        }

        $byCurrency = [];
        foreach ($normalized as $line) {
            $byCurrency[$line['currency']]['debit'] = Money::add($byCurrency[$line['currency']]['debit'] ?? '0', $line['debit']);
            $byCurrency[$line['currency']]['credit'] = Money::add($byCurrency[$line['currency']]['credit'] ?? '0', $line['credit']);
        }
        foreach ($byCurrency as $currency => $totals) {
            if (Money::cmp($totals['debit'], $totals['credit']) !== 0) {
                throw new DomainException("سند حسابداری برای {$currency} متعادل نیست");
            }
        }

        $entry = JournalEntry::create([
            'occurred_at' => $occurredAt ?? now(),
            'memo' => $memo,
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'posted_by' => Auth::id(),
            'event_type' => $eventType,
            'currency' => $journalCurrency ?? $currencies->first(),
        ]);

        foreach ($normalized as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'ledger_account_id' => $line['account']->id,
                'currency' => $line['currency'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ]);
        }

        return $entry->load('lines');
    }

    public function reverse(JournalEntry $original, Model $source, string $eventType = 'reversal'): JournalEntry
    {
        $lines = [];
        foreach ($original->lines as $line) {
            $lines[] = [
                'account' => $line->account ?? $this->accountById($line->ledger_account_id),
                'debit' => $line->credit,
                'credit' => $line->debit,
                'currency' => $line->currency,
            ];
        }

        $entry = $this->post(
            'برگشت: ' . $original->memo,
            $source,
            $lines,
            now(),
            $eventType,
            $original->currency
        );
        $entry->update(['reverses_entry_id' => $original->id]);

        return $entry;
    }

    public function postSale(Model $invoice, mixed $netRevenue, mixed $cogs, string $currency, int $branchId, string $paymentMethod): JournalEntry
    {
        $lines = [
            [
                'account' => $this->revenueAccount($currency),
                'credit' => $netRevenue,
                'currency' => $currency,
            ],
        ];

        if (in_array($paymentMethod, ['cash', 'card'], true)) {
            $lines[] = [
                'account' => $this->cashAccount($branchId, $currency),
                'debit' => $netRevenue,
                'currency' => $currency,
            ];
        } elseif ($paymentMethod === 'check') {
            $lines[] = [
                'account' => $this->account('checks_receivable', 'چک‌های دریافتنی', 'asset', $branchId, $currency),
                'debit' => $netRevenue,
                'currency' => $currency,
            ];
        } else {
            $lines[] = [
                'account' => $this->account('accounts_receivable', 'حساب‌های دریافتنی', 'asset', $branchId, $currency),
                'debit' => $netRevenue,
                'currency' => $currency,
            ];
        }

        if (Money::cmp($cogs, '0') > 0) {
            $lines[] = [
                'account' => $this->account('cogs', 'بهای تمام‌شده', 'expense', null, $currency),
                'debit' => $cogs,
                'currency' => $currency,
            ];
            $lines[] = [
                'account' => $this->account('inventory_asset', 'موجودی کالا', 'asset', null, $currency),
                'credit' => $cogs,
                'currency' => $currency,
            ];
        }

        return $this->post("فروش {$invoice->invoice_number}", $invoice, $lines, $invoice->created_at, 'sale', $currency);
    }

    public function postExpense(Model $expense, string $eventType = 'expense'): JournalEntry
    {
        $amount = Money::of($expense->amount);
        $currency = $expense->currency ?? 'toman';
        $branchId = (int) $expense->branch_id;

        return $this->post('هزینه', $expense, [
            [
                'account' => $this->account('expenses', 'هزینه‌ها', 'expense', $branchId, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->cashAccount($branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], $expense->expense_date ?? $expense->date ?? $expense->created_at, $eventType, $currency);
    }

    public function postPurchase(Model $source, mixed $amount, string $currency, int $branchId): JournalEntry
    {
        return $this->post('خرید نقدی موجودی', $source, [
            [
                'account' => $this->account('inventory_asset', 'موجودی کالا', 'asset', null, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->cashAccount($branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], null, 'purchase', $currency);
    }

    public function postCustomerReturn(Model $return, mixed $amount, mixed $cogs, string $currency, int $branchId, string $refundMethod): JournalEntry
    {
        $lines = [
            [
                'account' => $this->revenueAccount($currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $refundMethod === 'cash'
                    ? $this->cashAccount($branchId, $currency)
                    : $this->account('accounts_receivable', 'حساب‌های دریافتنی', 'asset', $branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ];
        if (Money::cmp($cogs, '0') > 0) {
            $lines[] = [
                'account' => $this->account('inventory_asset', 'موجودی کالا', 'asset', null, $currency),
                'debit' => $cogs,
                'currency' => $currency,
            ];
            $lines[] = [
                'account' => $this->account('cogs', 'بهای تمام‌شده', 'expense', null, $currency),
                'credit' => $cogs,
                'currency' => $currency,
            ];
        }

        return $this->post("مرجوعی {$return->return_number}", $return, $lines, null, 'customer_return', $currency);
    }

    public function postGift(Gift $gift): JournalEntry
    {
        $allocations = GiftLotAllocation::where('gift_id', $gift->id)->get();
        if ($allocations->isEmpty()) {
            $amount = Money::of($gift->cost_value);
            $currency = $gift->currency;
            $lines = [
                [
                    'account' => $this->account('gifts_expense', 'هزینه هدایا', 'expense', (int) $gift->branch_id, $currency),
                    'debit' => $amount,
                    'currency' => $currency,
                ],
                [
                    'account' => $gift->is_consignment
                        ? $this->account('supplier_payable', 'بدهی تأمین‌کننده', 'liability', null, $currency)
                        : $this->account('inventory_asset', 'موجودی کالا', 'asset', null, $currency),
                    'credit' => $amount,
                    'currency' => $currency,
                ],
            ];

            return $this->post("هدیه #{$gift->id}", $gift, $lines, $gift->gifted_at, 'gift', $currency);
        }

        $currency = $allocations->pluck('currency')->unique();
        if ($currency->count() > 1) {
            throw new DomainException('هدیه چندارزی مجاز نیست');
        }
        $cur = $currency->first();
        $owned = '0.00';
        $consign = '0.00';
        foreach ($allocations as $alloc) {
            $line = Money::mul($alloc->unit_cost, $alloc->quantity);
            if ($alloc->ownership_type === 'consignment') {
                $consign = Money::add($consign, ConsignmentFinance::publisherShare($line));
            } else {
                $owned = Money::add($owned, $line);
            }
        }
        $expense = Money::add($owned, $consign);
        $lines = [
            [
                'account' => $this->account('gifts_expense', 'هزینه هدایا', 'expense', (int) $gift->branch_id, $cur),
                'debit' => $expense,
                'currency' => $cur,
            ],
        ];
        if (Money::cmp($owned, '0') > 0) {
            $lines[] = [
                'account' => $this->account('inventory_asset', 'موجودی کالا', 'asset', null, $cur),
                'credit' => $owned,
                'currency' => $cur,
            ];
        }
        if (Money::cmp($consign, '0') > 0) {
            $lines[] = [
                'account' => $this->account('supplier_payable', 'بدهی تأمین‌کننده', 'liability', null, $cur),
                'credit' => $consign,
                'currency' => $cur,
            ];
        }

        return $this->post("هدیه #{$gift->id}", $gift, $lines, $gift->gifted_at, 'gift', $cur);
    }

    public function postSettlement(Model $settlement): JournalEntry
    {
        $amount = Money::of($settlement->amount);
        $currency = $settlement->currency;
        $branchId = (int) ($settlement->branch_id ?? 0) ?: null;

        return $this->post("تسویه تأمین‌کننده #{$settlement->id}", $settlement, [
            [
                'account' => $this->account('supplier_payable', 'بدهی تأمین‌کننده', 'liability', null, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $branchId
                    ? $this->cashAccount($branchId, $currency)
                    : $this->account('settlement_payments', 'پرداخت تسویه', 'asset', null, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], null, 'settlement', $currency);
    }

    public function postSupplierReturn(Model $return, mixed $amount, string $currency): JournalEntry
    {
        return $this->post("مرجوعی امانی {$return->return_number}", $return, [
            [
                'account' => $this->account('supplier_payable', 'بدهی تأمین‌کننده', 'liability', null, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->account('consignment_stock', 'کالای امانی', 'asset', null, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], null, 'supplier_return', $currency);
    }

    public function postCustomerPayment(Model $payment): JournalEntry
    {
        $amount = Money::of($payment->amount);
        $currency = $payment->currency;
        $branchId = (int) $payment->branch_id;

        return $this->post('دریافت از مشتری', $payment, [
            [
                'account' => $this->cashAccount($branchId, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->account('accounts_receivable', 'حساب‌های دریافتنی', 'asset', $branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], $payment->paid_at, 'customer_payment', $currency);
    }

    public function postCheckCleared(Model $check): JournalEntry
    {
        $amount = Money::of($check->amount);
        $currency = $check->currency;
        $branchId = (int) $check->branch_id;

        return $this->post("وصول چک {$check->check_number}", $check, [
            [
                'account' => $this->cashAccount($branchId, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->account('checks_receivable', 'چک‌های دریافتنی', 'asset', $branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], null, 'check_cleared', $currency);
    }

    public function postCheckBounced(Model $check): JournalEntry
    {
        $amount = Money::of($check->amount);
        $currency = $check->currency;
        $branchId = (int) $check->branch_id;

        return $this->post("برگشت چک {$check->check_number}", $check, [
            [
                'account' => $this->account('accounts_receivable', 'حساب‌های دریافتنی', 'asset', $branchId, $currency),
                'debit' => $amount,
                'currency' => $currency,
            ],
            [
                'account' => $this->account('checks_receivable', 'چک‌های دریافتنی', 'asset', $branchId, $currency),
                'credit' => $amount,
                'currency' => $currency,
            ],
        ], null, 'check_bounced', $currency);
    }

    private function cashAccount(int $branchId, string $currency): LedgerAccount
    {
        return $this->account('cash', 'صندوق/بانک', 'asset', $branchId, $currency);
    }

    private function revenueAccount(string $currency): LedgerAccount
    {
        return $this->account('sales_revenue', 'درآمد فروش', 'revenue', null, $currency);
    }

    private function accountById(int $id): LedgerAccount
    {
        return LedgerAccount::findOrFail($id);
    }
}
