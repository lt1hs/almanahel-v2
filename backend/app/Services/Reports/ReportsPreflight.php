<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\Gift;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Settlement;
use App\Models\WarehouseLog;
use App\Services\Ledger\SystemAccounts;
use App\Services\Receivables\InvoiceBalance;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;

class ReportsPreflight
{
    public function __construct(
        private readonly LedgerReportService $reports = new LedgerReportService(),
        private readonly PostedJournals $journals = new PostedJournals(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function issues(): array
    {
        return array_merge(
            $this->unbalancedJournals(),
            $this->missingJournals(),
            $this->invalidCurrency(),
            $this->missingBranchDimensions(),
            $this->missingPartyDimensions(),
            $this->anonymousCustomerCredit(),
            $this->inventoryDifferences(),
            $this->arDifferences(),
            $this->apDifferences(),
            $this->checkDifferences(),
            $this->invalidIraqOrigins(),
            $this->historicalAsOfSanity(),
        );
    }

    /**
     * @return array{ok: bool, issues: list<string>}
     */
    public function report(): array
    {
        $issues = $this->issues();

        return [
            'ok' => $issues === [],
            'issues' => $issues,
        ];
    }

    /** @return list<string> */
    private function unbalancedJournals(): array
    {
        $issues = [];
        JournalEntry::query()->orderBy('id')->chunkById(100, function ($entries) use (&$issues) {
            foreach ($entries as $entry) {
                if (in_array((string) $entry->status, PostedJournals::EXCLUDED_STATUSES, true)) {
                    continue;
                }
                $debit = '0.00';
                $credit = '0.00';
                foreach (JournalLine::where('journal_entry_id', $entry->id)->get() as $line) {
                    $debit = Money::add($debit, $line->debit);
                    $credit = Money::add($credit, $line->credit);
                    if ($line->currency !== $entry->currency) {
                        $issues[] = "journal {$entry->id} mixed currency on line {$line->id}";
                    }
                }
                if (Money::cmp($debit, $credit) !== 0) {
                    $issues[] = "unbalanced journal {$entry->id}";
                }
            }
        });

        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    private function missingJournals(): array
    {
        $issues = [];
        $missing = function (string $label, $query, string $class, string $event) use (&$issues) {
            foreach ($query->orderBy('id')->get(['id']) as $row) {
                $exists = JournalEntry::query()
                    ->where('source_type', $class)
                    ->where('source_id', $row->id)
                    ->where('event_type', $event)
                    ->exists();
                if (!$exists) {
                    $issues[] = "missing journal for {$label} {$row->id}";
                }
            }
        };
        $missing('invoice', Invoice::query()->where(function ($q) {
            $q->whereNull('type')->orWhere('type', 'sale');
        }), Invoice::class, 'sale');
        $missing('expense', Expense::query()->whereNull('archived_at'), Expense::class, 'expense');
        $missing('gift', Gift::query(), Gift::class, 'gift');
        $missing('customer_return', CustomerReturn::query(), CustomerReturn::class, 'customer_return');
        $missing('customer_payment', CustomerPayment::query(), CustomerPayment::class, 'customer_payment');
        $missing('settlement', Settlement::query(), Settlement::class, 'settlement');
        $missing(
            'purchase',
            WarehouseLog::query()->whereNotNull('stock_lot_id')->where('reason', 'received_from_supplier'),
            WarehouseLog::class,
            'purchase'
        );
        foreach (Check::query()->where('status', 'cleared')->get(['id']) as $check) {
            if (!JournalEntry::query()->where('source_type', Check::class)->where('source_id', $check->id)->where('event_type', 'check_cleared')->exists()) {
                $issues[] = "missing journal for check_cleared {$check->id}";
            }
        }
        foreach (Check::query()->where('status', 'bounced')->get(['id']) as $check) {
            if (!JournalEntry::query()->where('source_type', Check::class)->where('source_id', $check->id)->where('event_type', 'check_bounced')->exists()) {
                $issues[] = "missing journal for check_bounced {$check->id}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function invalidCurrency(): array
    {
        $issues = [];
        $bad = JournalLine::query()
            ->where(function ($q) {
                $q->whereNull('currency')->orWhereNotIn('currency', ['toman', 'dinar']);
            })
            ->count();
        if ($bad > 0) {
            $issues[] = "invalid or missing currency on journal lines: {$bad}";
        }
        $entryBad = JournalEntry::query()
            ->where(function ($q) {
                $q->whereNull('currency')->orWhereNotIn('currency', ['toman', 'dinar']);
            })
            ->count();
        if ($entryBad > 0) {
            $issues[] = "invalid or missing currency on journal entries: {$entryBad}";
        }

        return $issues;
    }

    /** @return list<string> */
    private function missingBranchDimensions(): array
    {
        $corporateTypes = ['bank', 'checks_payable'];
        $missing = JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->whereNull('journal_lines.branch_id')
            ->where(function ($q) use ($corporateTypes) {
                $q->whereNotIn('ledger_accounts.type', ['asset', 'liability'])
                    ->orWhere(function ($inner) use ($corporateTypes) {
                        $inner->whereNotNull('journal_lines.financial_account_id');
                    });
            })
            ->count();
        $pnlMissing = JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->whereNull('journal_lines.branch_id')
            ->whereIn('ledger_accounts.type', ['revenue', 'expense'])
            ->count();
        $issues = [];
        if ($pnlMissing > 0) {
            $issues[] = "missing branch_id on P&L journal lines: {$pnlMissing}";
        }

        return $issues;
    }

    /** @return list<string> */
    private function missingPartyDimensions(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $ar = SystemAccounts::get('accounts_receivable', $currency)->id;
            $sp = SystemAccounts::get('supplier_payable', $currency)->id;
            $missingAr = JournalLine::query()->where('ledger_account_id', $ar)->whereNull('customer_id')->count();
            $missingSp = JournalLine::query()->where('ledger_account_id', $sp)->whereNull('supplier_id')->count();
            if ($missingAr > 0) {
                $issues[] = "accounts_receivable lines missing customer_id ({$currency}): {$missingAr}";
            }
            if ($missingSp > 0) {
                $issues[] = "supplier_payable lines missing supplier_id ({$currency}): {$missingSp}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function anonymousCustomerCredit(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $id = SystemAccounts::get('customer_credit_liability', $currency)->id;
            $n = JournalLine::query()->where('ledger_account_id', $id)->whereNull('customer_id')->count();
            if ($n > 0) {
                $issues[] = "anonymous customer_credit_liability lines ({$currency}): {$n}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function inventoryDifferences(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $inv = $this->reports->inventoryValue(null, $currency);
            if (!$inv['reconciled']) {
                $issues[] = "owned inventory ledger vs lots ({$currency}) difference {$inv['difference']}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function arDifferences(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $from = '1970-01-01';
            $to = now()->toDateString();
            $ar = $this->reports->receivables(null, $from, $to, $currency);
            if (!$ar['reconciled']) {
                $issues[] = "AR operational vs ledger ({$currency}) difference {$ar['difference']}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function apDifferences(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $ap = $this->reports->payables(null, '1970-01-01', now()->toDateString(), $currency);
            if (!$ap['reconciled']) {
                $issues[] = "supplier payable operational vs ledger ({$currency}) difference {$ap['difference']}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function checkDifferences(): array
    {
        $issues = [];
        foreach (['toman', 'dinar'] as $currency) {
            $checks = $this->reports->checks(null, '1970-01-01', now()->toDateString(), $currency);
            if (Money::cmp($checks['incoming_difference'], '0') !== 0) {
                $issues[] = "incoming checks operational vs ledger ({$currency}) difference {$checks['incoming_difference']}";
            }
            if (Money::cmp($checks['outgoing_difference'], '0') !== 0) {
                $issues[] = "outgoing checks operational vs ledger ({$currency}) difference {$checks['outgoing_difference']}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function invalidIraqOrigins(): array
    {
        $iraqIds = Branch::query()
            ->where(function ($q) {
                $q->where('country', 'عراق')->orWhere('is_iraq_store', true);
            })
            ->pluck('id')
            ->all();
        if ($iraqIds === []) {
            return [];
        }
        $codes = [];
        foreach (['toman', 'dinar'] as $currency) {
            foreach (['sales_revenue', 'sales_returns', 'cogs', 'gift_expense'] as $code) {
                $codes[] = SystemAccounts::get($code, $currency)->id;
            }
        }
        $n = JournalLine::query()
            ->whereIn('branch_id', $iraqIds)
            ->whereIn('ledger_account_id', $codes)
            ->where(function ($q) {
                $q->whereNull('origin_scope')
                    ->orWhereNotIn('origin_scope', ['iraq_local', 'qom_distributed']);
            })
            ->count();
        if ($n > 0) {
            return ["invalid origin dimensions for Iraq sale/gift/return lines: {$n}"];
        }

        return [];
    }

    /** @return list<string> */
    private function historicalAsOfSanity(): array
    {
        $issues = [];
        $balances = app(InvoiceBalance::class);
        foreach (CustomerPayment::query()->with('invoice')->get() as $payment) {
            $invoice = $payment->invoice;
            if (!$invoice || $invoice->sold_at === null || $payment->paid_at === null) {
                continue;
            }
            if ($payment->paid_at->lte($invoice->sold_at)) {
                continue;
            }
            if ((string) $invoice->payment_method !== 'credit') {
                continue;
            }
            $asOf = $payment->paid_at->copy()->subSecond();
            $out = $balances->outstandingAsOf($invoice, $asOf);
            if (Money::isZero($out)) {
                $issues[] = "historical AR as-of failed for invoice {$invoice->id} before payment {$payment->id}";
            }
        }
        foreach (Settlement::query()->where('payment_method', 'check')->get() as $settlement) {
            if ($settlement->paid_at === null) {
                continue;
            }
            if ($settlement->cleared_at === null && $settlement->bounced_at === null && $settlement->cancelled_at === null) {
                continue;
            }
            $event = collect([$settlement->cleared_at, $settlement->bounced_at, $settlement->cancelled_at])
                ->filter()
                ->sort()
                ->first();
            if (!$event || $event->lte($settlement->paid_at)) {
                continue;
            }
            $asOf = $event->copy()->subSecond();
            $status = \App\Services\Ledger\CheckLifecycle::supplierStatusAsOf($settlement, $asOf);
            if ($status !== 'pending') {
                $issues[] = "historical supplier check as-of failed for settlement {$settlement->id}";
            }
        }

        return $issues;
    }
}
