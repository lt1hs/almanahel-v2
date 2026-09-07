<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Models\WarehouseLog;
use App\Services\BranchShare\BranchSalesShareService;
use App\Services\Settlement\PayableSnapshot;
use App\Services\Receivables\InvoiceBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * V2 posting runtime used by FinancePostingGateway. LedgerPoster is unused.
 */
class FinancialPostingService
{
    public function __construct(
        private readonly PayableSnapshot $payableSnapshot = new PayableSnapshot(),
        private readonly ConsignmentReturnSplit $returnSplit = new ConsignmentReturnSplit(),
        private readonly InvoiceTotalGuard $invoiceTotals = new InvoiceTotalGuard(),
        private readonly AllocationIntegrity $allocationIntegrity = new AllocationIntegrity()
    ) {
    }

    public function postSale(Invoice $invoice, FinancialAccount $receiptAccount, \DateTimeInterface $occurredAt): JournalEntry
    {
        return DB::transaction(function () use ($invoice, $receiptAccount, $occurredAt) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $currency = (string) $invoice->currency;
            $branchId = (int) $invoice->branch_id;
            $this->assertTreasury($receiptAccount, $currency, $branchId, 'sale', (string) $invoice->payment_method);

            $this->allocationIntegrity->assertSale($invoice);
            $this->invoiceTotals->assert($invoice);
            $invoice->load('items');
            app(BranchSalesShareService::class)->snapshotSale($invoice);
            $allocations = SaleLotAllocation::query()
                ->whereIn('invoice_item_id', $invoice->items()->pluck('id'))
                ->with('lot')
                ->get();
            $customerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
            $net = Money::of($invoice->total);
            $lines = [
                $this->treasuryLine($receiptAccount, $currency, $branchId, debit: $net, customerId: $customerId),
            ];

            $assignedRevenue = '0.00';
            $allocCount = $allocations->count();
            $index = 0;
            foreach ($allocations as $alloc) {
                $index++;
                $item = $invoice->items->firstWhere('id', $alloc->invoice_item_id);
                $unit = Money::sub($item?->actual_price ?? 0, $item?->discount ?? 0);
                $part = Money::mul($unit, (int) $alloc->quantity);
                if ($index === $allocCount) {
                    $part = Money::sub($net, $assignedRevenue);
                } else {
                    $assignedRevenue = Money::add($assignedRevenue, $part);
                }
                $origin = $alloc->lot?->origin;
                $lines[] = $this->systemLine(
                    'sales_revenue',
                    $currency,
                    $branchId,
                    credit: $part,
                    origin: $origin,
                    lotId: (int) $alloc->stock_lot_id,
                    saleAllocId: (int) $alloc->id,
                    customerId: $customerId
                );
            }

            foreach ($allocations as $alloc) {
                $lot = $alloc->lot;
                $qty = (int) $alloc->quantity;
                $origin = $lot?->origin;
                $supplierId = $lot?->supplier_id ? (int) $lot->supplier_id : null;
                $ownership = (string) ($lot?->ownership_type ?? 'owned');
                $dims = [
                    'origin' => $origin,
                    'lotId' => (int) $alloc->stock_lot_id,
                    'saleAllocId' => (int) $alloc->id,
                    'supplierId' => $ownership === 'consignment' ? $supplierId : null,
                    'supplierAccountId' => $ownership === 'consignment' && $alloc->supplier_account_id
                        ? (int) $alloc->supplier_account_id
                        : null,
                ];

                if ($ownership === 'consignment') {
                    $cogs = $this->stampedAllocationPayable($alloc);
                    $lines[] = $this->systemLine('cogs', $currency, $branchId, ...(['debit' => $cogs] + $dims));
                    $lines[] = $this->systemLine('supplier_payable', $currency, $branchId, ...(['credit' => $cogs] + $dims));
                } else {
                    $cogs = Money::mul($alloc->unit_cost, $qty);
                    $lines[] = $this->systemLine('cogs', $currency, $branchId, ...(['debit' => $cogs] + $dims));
                    $lines[] = $this->systemLine('owned_inventory', $currency, $branchId, ...(['credit' => $cogs] + $dims));
                }
            }

            return $this->post(
                'فروش ' . $invoice->invoice_number,
                $invoice,
                $lines,
                $occurredAt,
                'sale',
                $currency
            );
        });
    }

    public function postGift(Gift $gift, \DateTimeInterface $occurredAt): JournalEntry
    {
        return DB::transaction(function () use ($gift, $occurredAt) {
            $gift = Gift::query()->whereKey($gift->id)->lockForUpdate()->firstOrFail();
            $this->allocationIntegrity->assertGift($gift);
            $currency = (string) $gift->currency;
            $branchId = (int) $gift->branch_id;
            $allocations = GiftLotAllocation::query()
                ->where('gift_id', $gift->id)
                ->lockForUpdate()
                ->with('lot')
                ->get();
            if ($allocations->isEmpty()) {
                throw new DomainException('هدیه بدون تخصیص لات قابل ثبت در دفتر نیست');
            }

            $lines = [];
            foreach ($allocations as $alloc) {
                if ((string) $alloc->currency !== $currency) {
                    throw new DomainException('یک سند حسابداری نمی‌تواند چند ارز را بدون سند تبدیل ترکیب کند');
                }
                $lot = $alloc->lot;
                $origin = $lot?->origin;
                $ownership = (string) ($alloc->ownership_type ?: $lot?->ownership_type ?: 'owned');
                $dims = [
                    'origin' => $origin,
                    'lotId' => (int) $alloc->stock_lot_id,
                    'giftAllocId' => (int) $alloc->id,
                    'supplierId' => $ownership === 'consignment' ? ($alloc->supplier_id ? (int) $alloc->supplier_id : ($lot?->supplier_id ? (int) $lot->supplier_id : null)) : null,
                    'supplierAccountId' => $ownership === 'consignment' && $alloc->supplier_account_id
                        ? (int) $alloc->supplier_account_id
                        : null,
                ];
                if ($ownership === 'consignment') {
                    $amount = $this->stampedGiftPayable($alloc);
                    $lines[] = $this->systemLine('gift_expense', $currency, $branchId, ...(['debit' => $amount] + $dims));
                    $lines[] = $this->systemLine('supplier_payable', $currency, $branchId, ...(['credit' => $amount] + $dims));
                } else {
                    $amount = Money::mul($alloc->unit_cost, (int) $alloc->quantity);
                    $lines[] = $this->systemLine('gift_expense', $currency, $branchId, ...(['debit' => $amount] + $dims));
                    $lines[] = $this->systemLine('owned_inventory', $currency, $branchId, ...(['credit' => $amount] + $dims));
                }
            }

            return $this->post('هدیه #' . $gift->id, $gift, $lines, $occurredAt, 'gift', $currency);
        });
    }

    public function postCustomerReturn(
        CustomerReturn $return,
        ?FinancialAccount $cashAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($return, $cashAccount, $occurredAt) {
            $return = CustomerReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($return->invoice_id)->lockForUpdate()->firstOrFail();
            $return = app(InvoiceBalance::class)->ensureStamped($return);
            $currency = (string) $invoice->currency;
            $branchId = (int) $return->branch_id;
            $this->allocationIntegrity->assertCustomerReturn($return);

            $rows = $return->lotAllocations()->lockForUpdate()->with(['lot', 'saleAllocation.invoiceItem'])->get();
            if ($rows->isEmpty()) {
                throw new DomainException('مرجوعی بدون تخصیص لات قابل ثبت در دفتر نیست');
            }
            app(BranchSalesShareService::class)->snapshotReturn($return);

            $customerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
            $refund = Money::of($return->refund_amount);
            $reduction = Money::of($return->receivable_reduction);
            $cashRefund = Money::of($return->cash_refund);
            $creditCreated = Money::of($return->customer_credit_created);
            if (Money::cmp(Money::add(Money::add($reduction, $cashRefund), $creditCreated), $refund) !== 0) {
                throw new DomainException('تقسیم مرجوعی مشتری با مبلغ بازپرداخت مطابقت ندارد');
            }
            if ((string) $return->currency !== $currency) {
                throw new DomainException('ارز مرجوعی با فاکتور یکسان نیست');
            }

            $lines = [];
            $assignedReturn = '0.00';
            $returnCount = $rows->count();
            $returnIndex = 0;
            foreach ($rows as $row) {
                $returnIndex++;
                $item = $row->saleAllocation?->invoiceItem;
                $unit = Money::sub($item?->actual_price ?? 0, $item?->discount ?? 0);
                $part = Money::mul($unit, (int) $row->quantity);
                if ($returnIndex === $returnCount) {
                    $part = Money::sub($refund, $assignedReturn);
                } else {
                    $assignedReturn = Money::add($assignedReturn, $part);
                }
                $lines[] = $this->systemLine(
                    'sales_returns',
                    $currency,
                    $branchId,
                    debit: $part,
                    origin: $row->origin_scope,
                    lotId: (int) $row->stock_lot_id,
                    saleAllocId: (int) $row->sale_lot_allocation_id,
                    returnAllocId: (int) $row->id,
                    customerId: $customerId
                );
            }
            if (!Money::isZero($reduction)) {
                $lines[] = $this->systemLine('accounts_receivable', $currency, $branchId, credit: $reduction, customerId: $customerId);
            }
            if (!Money::isZero($cashRefund)) {
                if (!$cashAccount) {
                    throw new DomainException('حساب صندوق برای بازپرداخت نقدی الزامی است');
                }
                $this->assertTreasury($cashAccount, $currency, $branchId, 'customer_return', 'cash');
                $lines[] = $this->treasuryLine($cashAccount, $currency, $branchId, credit: $cashRefund, customerId: $customerId);
            }
            if (!Money::isZero($creditCreated)) {
                if (!$customerId) {
                    throw new DomainException('اعتبار مشتری بدون مشتری ثبت‌شده مجاز نیست', 422);
                }
                $lines[] = $this->systemLine('customer_credit_liability', $currency, $branchId, credit: $creditCreated, customerId: $customerId);
            }

            foreach ($rows as $row) {
                if ((string) $row->currency !== $currency) {
                    throw new DomainException('یک سند حسابداری نمی‌تواند چند ارز را بدون سند تبدیل ترکیب کند');
                }
                $origin = $row->origin_scope;
                $lotId = (int) $row->stock_lot_id;
                $returnAllocId = (int) $row->id;
                $saleAllocId = (int) $row->sale_lot_allocation_id;
                $supplierId = $row->supplier_id ? (int) $row->supplier_id : null;
                $supplierAccountId = $row->supplier_account_id ? (int) $row->supplier_account_id : null;

                if ($row->ownership_type === 'consignment') {
                    $split = $this->returnSplit->persist($row);
                    $cogs = $split['publisher_payable_reversed'];
                    $lines[] = $this->systemLine(
                        'cogs',
                        $currency,
                        $branchId,
                        credit: $cogs,
                        origin: $origin,
                        lotId: $lotId,
                        saleAllocId: $saleAllocId,
                        returnAllocId: $returnAllocId,
                        supplierId: $supplierId,
                        supplierAccountId: $supplierAccountId
                    );
                    if (!Money::isZero($split['unsettled_payable_reversed'])) {
                        $lines[] = $this->systemLine(
                            'supplier_payable',
                            $currency,
                            $branchId,
                            debit: $split['unsettled_payable_reversed'],
                            origin: $origin,
                            lotId: $lotId,
                            saleAllocId: $saleAllocId,
                            returnAllocId: $returnAllocId,
                            supplierId: $supplierId,
                            supplierAccountId: $supplierAccountId
                        );
                    }
                    if (!Money::isZero($split['settled_payable_reversed'])) {
                        $lines[] = $this->systemLine(
                            'supplier_recoverable',
                            $currency,
                            $branchId,
                            debit: $split['settled_payable_reversed'],
                            origin: $origin,
                            lotId: $lotId,
                            saleAllocId: $saleAllocId,
                            returnAllocId: $returnAllocId,
                            supplierId: $supplierId,
                            supplierAccountId: $supplierAccountId
                        );
                    }
                } else {
                    $cogs = Money::mul($row->unit_cost, (int) $row->quantity);
                    $lines[] = $this->systemLine(
                        'cogs',
                        $currency,
                        $branchId,
                        credit: $cogs,
                        origin: $origin,
                        lotId: $lotId,
                        saleAllocId: $saleAllocId,
                        returnAllocId: $returnAllocId
                    );
                    $lines[] = $this->systemLine(
                        'owned_inventory',
                        $currency,
                        $branchId,
                        debit: $cogs,
                        origin: $origin,
                        lotId: $lotId,
                        saleAllocId: $saleAllocId,
                        returnAllocId: $returnAllocId
                    );
                }
            }

            $entry = $this->post('مرجوعی', $return, $lines, $occurredAt, 'customer_return', $currency);
            app(InvoiceBalance::class)->refresh($invoice);

            return $entry;
        });
    }

    public function postSettlement(Settlement $settlement, FinancialAccount $fundingAccount, \DateTimeInterface $occurredAt): JournalEntry
    {
        return DB::transaction(function () use ($settlement, $fundingAccount, $occurredAt) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $currency = (string) $settlement->currency;
            $branchId = $settlement->branch_id ? (int) $settlement->branch_id : null;
            $this->assertTreasury(
                $fundingAccount,
                $currency,
                $branchId,
                'settlement',
                (string) $settlement->payment_method
            );
            $this->allocationIntegrity->assertSettlement($settlement);

            $allocations = SettlementAllocation::query()
                ->where('settlement_id', $settlement->id)
                ->lockForUpdate()
                ->get();
            $allocated = '0.00';
            foreach ($allocations as $alloc) {
                if ((string) $alloc->currency !== $currency) {
                    throw new DomainException('یک سند حسابداری نمی‌تواند چند ارز را بدون سند تبدیل ترکیب کند');
                }
                $allocated = Money::add($allocated, $alloc->amount);
            }
            if ($allocations->isEmpty() || Money::cmp($allocated, $settlement->amount) !== 0) {
                throw new DomainException('جمع تخصیص تسویه با مبلغ تسویه برابر نیست');
            }

            $supplierId = (int) $settlement->supplier_id;
            $supplierAccountId = $settlement->supplier_account_id ? (int) $settlement->supplier_account_id : null;
            $lines = [];
            foreach ($allocations as $alloc) {
                $amount = Money::of($alloc->amount);
                $lineAccountId = $alloc->supplier_account_id ? (int) $alloc->supplier_account_id : $supplierAccountId;
                $lines[] = $this->systemLine(
                    'supplier_payable',
                    $currency,
                    $branchId,
                    debit: $amount,
                    supplierId: $supplierId,
                    supplierAccountId: $lineAccountId,
                    settlementAllocId: (int) $alloc->id
                );
                $lines[] = $this->treasuryLine(
                    $fundingAccount,
                    $currency,
                    $branchId,
                    credit: $amount,
                    supplierId: $supplierId,
                    supplierAccountId: $lineAccountId,
                    settlementAllocId: (int) $alloc->id
                );
            }

            return $this->post('تسویه تأمین‌کننده', $settlement, $lines, $occurredAt, 'settlement', $currency);
        });
    }

    public function postExpense(Expense $expense, FinancialAccount $fundingAccount, \DateTimeInterface $occurredAt, bool $replacement = false, ?int $supersedesEntryId = null): JournalEntry
    {
        return DB::transaction(function () use ($expense, $fundingAccount, $occurredAt, $replacement, $supersedesEntryId) {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $currency = (string) ($expense->currency ?? 'toman');
            $branchId = (int) $expense->branch_id;
            $this->assertTreasury($fundingAccount, $currency, $branchId, 'expense', 'cash');
            $amount = Money::of($expense->amount);
            if (Money::isZero($amount)) {
                throw new DomainException('سند حسابداری نمی‌تواند خالی باشد');
            }
            $lines = [
                $this->systemLine('operating_expense', $currency, $branchId, debit: $amount),
                $this->treasuryLine($fundingAccount, $currency, $branchId, credit: $amount),
            ];

            return $this->post(
                'هزینه',
                $expense,
                $lines,
                $occurredAt,
                'expense',
                $currency,
                replacement: $replacement,
                supersedesEntryId: $supersedesEntryId
            );
        });
    }

    public function postOwnedPurchase(
        WarehouseLog $log,
        StockLot $lot,
        FinancialAccount $cashAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($log, $lot, $cashAccount, $occurredAt) {
            $log = WarehouseLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            $lot = StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if ((string) $lot->ownership_type === 'consignment') {
                throw new DomainException('خرید نقدی مالکیت دارالمناهل نمی‌تواند لات امانی باشد');
            }
            if ((int) $log->quantity !== (int) $lot->qty_original) {
                throw new DomainException('مقدار سند انبار با لات خرید یکسان نیست');
            }
            $currency = (string) $lot->currency;
            $branchId = (int) $log->branch_id;
            if ((int) $lot->branch_id !== $branchId) {
                throw new DomainException('شعبه لات خرید با سند انبار یکسان نیست');
            }
            $this->assertTreasury($cashAccount, $currency, $branchId, 'purchase', 'cash');
            $amount = Money::mul($lot->unit_cost, (int) $lot->qty_original);
            if (Money::isZero($amount)) {
                throw new DomainException('سند حسابداری نمی‌تواند خالی باشد');
            }
            $supplierId = $lot->supplier_id ? (int) $lot->supplier_id : null;
            $lines = [
                $this->systemLine(
                    'owned_inventory',
                    $currency,
                    $branchId,
                    debit: $amount,
                    origin: $lot->origin,
                    lotId: (int) $lot->id,
                    supplierId: $supplierId
                ),
                $this->treasuryLine($cashAccount, $currency, $branchId, credit: $amount, supplierId: $supplierId),
            ];

            return $this->post('خرید نقدی موجودی', $log, $lines, $occurredAt, 'purchase', $currency);
        });
    }

    public function postCustomerPayment(
        CustomerPayment $payment,
        FinancialAccount $receiptAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($payment, $receiptAccount, $occurredAt) {
            $payment = CustomerPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $currency = (string) $payment->currency;
            $branchId = (int) $payment->branch_id;
            $this->assertTreasury($receiptAccount, $currency, $branchId, 'customer_payment', (string) $payment->method);
            $amount = Money::of($payment->amount);
            $customerId = (int) $payment->customer_id;
            $lines = [
                $this->treasuryLine($receiptAccount, $currency, $branchId, debit: $amount, customerId: $customerId),
                $this->systemLine('accounts_receivable', $currency, $branchId, credit: $amount, customerId: $customerId),
            ];

            return $this->post('دریافت از مشتری', $payment, $lines, $occurredAt, 'customer_payment', $currency);
        });
    }

    public function postIncomingCheckCleared(
        Check $check,
        FinancialAccount $bankAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($check, $bankAccount, $occurredAt) {
            $check = Check::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();
            $currency = (string) $check->currency;
            $branchId = (int) $check->branch_id;
            $this->assertTreasury($bankAccount, $currency, $branchId, 'incoming_check_clear', 'bank');
            $amount = Money::of($check->amount);
            $customerId = $check->invoice?->customer_id ? (int) $check->invoice->customer_id : null;
            $checkId = (int) $check->id;
            $lines = [
                $this->treasuryLine($bankAccount, $currency, $branchId, debit: $amount, customerId: $customerId, checkId: $checkId),
                $this->systemLine('checks_receivable', $currency, $branchId, credit: $amount, customerId: $customerId, checkId: $checkId),
            ];

            return $this->post('وصول چک مشتری', $check, $lines, $occurredAt, 'check_cleared', $currency);
        });
    }

    public function postIncomingCheckBounced(Check $check, \DateTimeInterface $occurredAt): JournalEntry
    {
        return DB::transaction(function () use ($check, $occurredAt) {
            $check = Check::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();
            $currency = (string) $check->currency;
            $branchId = (int) $check->branch_id;
            $amount = Money::of($check->amount);
            $customerId = $check->invoice?->customer_id ? (int) $check->invoice->customer_id : null;
            $checkId = (int) $check->id;
            $lines = [
                $this->systemLine('accounts_receivable', $currency, $branchId, debit: $amount, customerId: $customerId, checkId: $checkId),
                $this->systemLine('checks_receivable', $currency, $branchId, credit: $amount, customerId: $customerId, checkId: $checkId),
            ];

            return $this->post('برگشت چک مشتری', $check, $lines, $occurredAt, 'check_bounced', $currency);
        });
    }

    public function postSupplierCheckCleared(
        Settlement $settlement,
        FinancialAccount $bankAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($settlement, $bankAccount, $occurredAt) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ((string) $settlement->payment_method !== 'check') {
                throw new DomainException('این تسویه با چک صادر نشده است');
            }
            $currency = (string) $settlement->currency;
            $branchId = $settlement->branch_id ? (int) $settlement->branch_id : null;
            $this->assertTreasury($bankAccount, $currency, $branchId, 'supplier_check_clear', 'bank');
            $amount = Money::of($settlement->amount);
            $supplierId = (int) $settlement->supplier_id;
            $supplierAccountId = $settlement->supplier_account_id ? (int) $settlement->supplier_account_id : null;
            $lines = [
                $this->systemLine('checks_payable', $currency, $branchId, debit: $amount, supplierId: $supplierId, supplierAccountId: $supplierAccountId),
                $this->treasuryLine($bankAccount, $currency, $branchId, credit: $amount, supplierId: $supplierId, supplierAccountId: $supplierAccountId),
            ];

            return $this->post('وصول چک تأمین‌کننده', $settlement, $lines, $occurredAt, 'supplier_check_cleared', $currency);
        });
    }

    public function reverseAndReplaceExpense(
        Expense $expense,
        JournalEntry $active,
        FinancialAccount $fundingAccount,
        \DateTimeInterface $occurredAt
    ): JournalEntry {
        return DB::transaction(function () use ($expense, $active, $fundingAccount, $occurredAt) {
            $this->reverse($active, $expense, 'expense_reversal', $occurredAt);
            $expense = $expense->fresh();

            return $this->postExpense($expense, $fundingAccount, $occurredAt, replacement: true, supersedesEntryId: (int) $active->id);
        });
    }

    public function reverse(
        JournalEntry $original,
        Model $source,
        string $eventType,
        \DateTimeInterface $occurredAt,
        bool $useOriginalOccurredAt = false
    ): JournalEntry {
        return DB::transaction(function () use ($original, $source, $eventType, $occurredAt, $useOriginalOccurredAt) {
            $original = JournalEntry::query()->whereKey($original->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($original->status !== 'active') {
                throw new DomainException('فقط سند فعال قابل برگشت است');
            }
            $when = $useOriginalOccurredAt ? ($original->occurred_at ?? $occurredAt) : $occurredAt;
            $lines = [];
            foreach ($original->lines as $line) {
                $lines[] = $this->lineFromModel($line, swap: true);
            }

            $reversal = $this->post(
                'برگشت: ' . $original->memo,
                $source,
                $lines,
                $when,
                $eventType,
                (string) $original->currency,
                reversesEntryId: (int) $original->id,
                asReversal: true
            );
            $original->update([
                'status' => 'reversed',
                'reversed_at' => now(),
            ]);

            return $reversal->fresh('lines');
        });
    }

    private function stampedAllocationPayable(SaleLotAllocation $alloc): string
    {
        if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
            throw new DomainException('بدهی امانی مُهر نشده است');
        }

        return $this->payableSnapshot->fromStampedRow([
            'publisher_payable' => $alloc->publisher_payable,
        ]);
    }

    private function stampedGiftPayable(GiftLotAllocation $alloc): string
    {
        if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
            throw new DomainException('بدهی امانی مُهر نشده است');
        }

        return $this->payableSnapshot->fromStampedRow([
            'publisher_payable' => $alloc->publisher_payable,
        ]);
    }

    private function assertTreasury(
        FinancialAccount $account,
        string $currency,
        ?int $branchId,
        string $eventType,
        string $paymentMethod
    ): void {
        TreasuryScope::assert($account, $currency, $branchId, $eventType, $paymentMethod);
    }

    /**
     * @return array<string, mixed>
     */
    private function treasuryLine(
        FinancialAccount $account,
        string $currency,
        ?int $branchId,
        mixed $debit = '0',
        mixed $credit = '0',
        ?int $customerId = null,
        ?int $supplierId = null,
        ?int $supplierAccountId = null,
        ?int $settlementAllocId = null,
        ?int $checkId = null
    ): array {
        return [
            'ledger_account_id' => $account->ledger_account_id,
            'financial_account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $currency,
            'branch_id' => $branchId ?? $account->branch_id,
            'supplier_id' => $supplierId,
            'supplier_account_id' => $supplierAccountId,
            'customer_id' => $customerId,
            'origin_scope' => null,
            'stock_lot_id' => null,
            'sale_lot_allocation_id' => null,
            'gift_lot_allocation_id' => null,
            'customer_return_lot_allocation_id' => null,
            'settlement_allocation_id' => $settlementAllocId,
            'check_id' => $checkId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemLine(
        string $code,
        string $currency,
        ?int $branchId,
        mixed $debit = '0',
        mixed $credit = '0',
        ?string $origin = null,
        ?int $lotId = null,
        ?int $saleAllocId = null,
        ?int $giftAllocId = null,
        ?int $returnAllocId = null,
        ?int $settlementAllocId = null,
        ?int $supplierId = null,
        ?int $supplierAccountId = null,
        ?int $customerId = null,
        ?int $checkId = null
    ): array {
        $account = SystemAccounts::get($code, $currency);

        return [
            'ledger_account_id' => $account->id,
            'financial_account_id' => null,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $currency,
            'branch_id' => $branchId,
            'supplier_id' => $supplierId,
            'supplier_account_id' => $supplierAccountId,
            'customer_id' => $customerId,
            'origin_scope' => $origin,
            'stock_lot_id' => $lotId,
            'sale_lot_allocation_id' => $saleAllocId,
            'gift_lot_allocation_id' => $giftAllocId,
            'customer_return_lot_allocation_id' => $returnAllocId,
            'settlement_allocation_id' => $settlementAllocId,
            'check_id' => $checkId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFromModel(JournalLine $line, bool $swap): array
    {
        return [
            'ledger_account_id' => $line->ledger_account_id,
            'financial_account_id' => $line->financial_account_id,
            'debit' => $swap ? $line->credit : $line->debit,
            'credit' => $swap ? $line->debit : $line->credit,
            'currency' => $line->currency,
            'branch_id' => $line->branch_id,
            'supplier_id' => $line->supplier_id,
            'supplier_account_id' => $line->supplier_account_id,
            'customer_id' => $line->customer_id,
            'origin_scope' => $line->origin_scope,
            'stock_lot_id' => $line->stock_lot_id,
            'sale_lot_allocation_id' => $line->sale_lot_allocation_id,
            'gift_lot_allocation_id' => $line->gift_lot_allocation_id,
            'customer_return_lot_allocation_id' => $line->customer_return_lot_allocation_id,
            'settlement_allocation_id' => $line->settlement_allocation_id,
            'check_id' => $line->check_id ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(
        string $memo,
        Model $source,
        array $lines,
        \DateTimeInterface $occurredAt,
        string $eventType,
        string $currency,
        bool $replacement = false,
        ?int $reversesEntryId = null,
        ?int $supersedesEntryId = null,
        bool $asReversal = false
    ): JournalEntry {
        if ($reversesEntryId !== null && !$asReversal) {
            throw new DomainException('شناسه برگشت فقط از مسیر برگشت سند مجاز است');
        }
        if (!$replacement && $supersedesEntryId !== null) {
            throw new DomainException('سند عادی نمی‌تواند جایگزین سند دیگر باشد');
        }
        if ($asReversal && ($replacement || $supersedesEntryId !== null)) {
            throw new DomainException('سند برگشت نمی‌تواند جایگزینی باشد');
        }

        $existing = JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('event_type', $eventType)
            ->lockForUpdate()
            ->get();

        $nextVersion = (int) ($existing->max('version') ?: 0) + 1;
        $activeNonReversal = $existing->filter(
            fn (JournalEntry $row) => $row->status === 'active' && !JournalChainInspector::isReversal($row)
        );

        if ($asReversal) {
            if (!$reversesEntryId) {
                throw new DomainException('سند برگشت بدون سند مبدأ است');
            }
            $target = JournalEntry::query()->whereKey($reversesEntryId)->lockForUpdate()->first();
            if (!$target) {
                throw new DomainException('سند مبدأ برگشت یافت نشد');
            }
            if ($target->status !== 'active') {
                throw new DomainException('فقط سند فعال قابل برگشت است');
            }
            if ($target->source_type !== $source::class || (int) $target->source_id !== (int) $source->getKey()) {
                throw new DomainException('هویت مبدأ سند برگشت یکسان نیست');
            }
            if ((string) $target->currency !== $currency) {
                throw new DomainException('ارز سند برگشت با سند مبدأ یکسان نیست');
            }
            if (JournalEntry::query()->where('reverses_entry_id', $target->id)->exists()) {
                throw new DomainException('این سند قبلاً برگشت شده است', 409);
            }
        } elseif ($replacement) {
            if (!$supersedesEntryId) {
                throw new DomainException('سند جایگزین باید سند قبلی را مشخص کند');
            }
            $target = JournalEntry::query()->whereKey($supersedesEntryId)->lockForUpdate()->first();
            if (!$target) {
                throw new DomainException('سند جایگزین‌شونده یافت نشد');
            }
            if ($target->source_type !== $source::class
                || (int) $target->source_id !== (int) $source->getKey()
                || $target->event_type !== $eventType) {
                throw new DomainException('سند جایگزین به رویداد دیگری اشاره می‌کند');
            }
            if ($target->status !== 'reversed') {
                throw new DomainException('سند جایگزین‌شونده باید قبلاً برگشت شده باشد');
            }
            if ((int) $target->version !== $nextVersion - 1) {
                throw new DomainException('نسخه سند جایگزین باید دقیقاً نسخه بعدی باشد');
            }
            if ($activeNonReversal->isNotEmpty()) {
                throw new DomainException('برای این رویداد سند فعال وجود دارد', 409);
            }
        } else {
            if ($activeNonReversal->isNotEmpty()) {
                throw new DomainException('برای این رویداد سند فعال وجود دارد', 409);
            }
        }

        $normalized = $this->normalizeLines($lines, $currency);

        try {
            $entry = JournalEntry::create([
                'occurred_at' => $occurredAt,
                'memo' => $memo,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'posted_by' => Auth::id(),
                'event_type' => $eventType,
                'currency' => $currency,
                'status' => 'active',
                'version' => $nextVersion,
                'reverses_entry_id' => $reversesEntryId,
                'supersedes_entry_id' => $supersedesEntryId,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('نسخه سند حسابداری تداخل دارد', 409);
        }

        foreach ($normalized as $line) {
            JournalLine::create($line + ['journal_entry_id' => $entry->id]);
        }

        return $entry->load('lines');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $lines, string $journalCurrency): array
    {
        $normalized = [];
        $debitTotal = '0.00';
        $creditTotal = '0.00';
        foreach ($lines as $line) {
            $currency = (string) ($line['currency'] ?? $journalCurrency);
            if ($currency !== $journalCurrency) {
                throw new DomainException('یک سند حسابداری نمی‌تواند چند ارز را بدون سند تبدیل ترکیب کند');
            }
            $debit = Money::of($line['debit'] ?? 0);
            $credit = Money::of($line['credit'] ?? 0);
            if (Money::isNegative($debit) || Money::isNegative($credit)) {
                throw new DomainException('مبالغ دفتر کل نمی‌توانند منفی باشند');
            }
            if (Money::isZero($debit) && Money::isZero($credit)) {
                continue;
            }
            $financialId = $line['financial_account_id'] ?? null;
            if ($financialId) {
                $account = FinancialAccount::query()->whereKey($financialId)->lockForUpdate()->first();
                if (!$account) {
                    throw new DomainException('حساب مالی یافت نشد');
                }
                if ((int) $account->ledger_account_id !== (int) ($line['ledger_account_id'] ?? 0)) {
                    throw new DomainException('حساب دفتر سطر خزانه با حساب مالی یکسان نیست');
                }
                if ($account->currency !== $currency) {
                    throw new DomainException('ارز حساب مالی با سند یکسان نیست');
                }
            }
            if (empty($line['ledger_account_id'])) {
                throw new DomainException('سطر سند بدون حساب دفتر است');
            }

            $normalized[] = [
                'ledger_account_id' => (int) $line['ledger_account_id'],
                'financial_account_id' => $financialId ? (int) $financialId : null,
                'currency' => $currency,
                'debit' => $debit,
                'credit' => $credit,
            'branch_id' => $line['branch_id'] ?? null,
            'supplier_id' => $line['supplier_id'] ?? null,
            'supplier_account_id' => $this->resolveLineSupplierAccount($line),
            'customer_id' => $line['customer_id'] ?? null,
                'origin_scope' => $line['origin_scope'] ?? null,
                'stock_lot_id' => $line['stock_lot_id'] ?? null,
                'sale_lot_allocation_id' => $line['sale_lot_allocation_id'] ?? null,
                'gift_lot_allocation_id' => $line['gift_lot_allocation_id'] ?? null,
                'customer_return_lot_allocation_id' => $line['customer_return_lot_allocation_id'] ?? null,
                'settlement_allocation_id' => $line['settlement_allocation_id'] ?? null,
                'check_id' => $line['check_id'] ?? null,
            ];
            $debitTotal = Money::add($debitTotal, $debit);
            $creditTotal = Money::add($creditTotal, $credit);
        }

        if ($normalized === []) {
            throw new DomainException('سند حسابداری نمی‌تواند خالی باشد');
        }
        if (Money::cmp($debitTotal, $creditTotal) !== 0) {
            throw new DomainException("سند حسابداری برای {$journalCurrency} متعادل نیست");
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveLineSupplierAccount(array $line): ?int
    {
        if (!empty($line['supplier_account_id'])) {
            return (int) $line['supplier_account_id'];
        }
        $supplierId = $line['supplier_id'] ?? null;
        $branchId = $line['branch_id'] ?? null;
        if (!$supplierId || !$branchId) {
            return null;
        }

        return app(\App\Services\Suppliers\SupplierAccountResolver::class)
            ->findIdByPair((int) $branchId, (int) $supplierId);
    }
}
