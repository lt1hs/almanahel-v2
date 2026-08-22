<?php

namespace App\Services\Ledger;

use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\Gift;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Settlement;
use App\Models\StockLot;
use App\Models\WarehouseLog;
use App\Services\Receivables\InvoiceBalance;
use App\Services\Treasury\FinancialAccountResolver;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * Only FinancialPostingService posts live events. LedgerPoster is unused.
 */
class FinancePostingGateway
{
    public function __construct(
        private readonly FinancialPostingService $v2,
        private readonly FinancialAccountResolver $accounts,
        private readonly InvoiceBalance $invoices,
    ) {
    }

    public function sale(
        Invoice $invoice,
        mixed $netTotal,
        mixed $cogs,
        string $currency,
        int $branchId,
        string $paymentMethod,
        ?int $financialAccountId = null
    ): JournalEntry {
        $account = $this->accounts->requireFor(
            'sale',
            $paymentMethod,
            $branchId,
            $currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postSale($invoice, $account, $invoice->sold_at ?? now());
    }

    public function ownedCashPurchase(
        WarehouseLog $log,
        StockLot $lot,
        mixed $amount,
        string $currency,
        int $branchId,
        ?int $financialAccountId = null
    ): JournalEntry {
        $account = $this->accounts->requireFor(
            'purchase',
            'cash',
            $branchId,
            $currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postOwnedPurchase($log, $lot, $account, $log->log_date ?? now());
    }

    public function gift(Gift $gift): JournalEntry
    {
        return $this->v2->postGift($gift, $gift->gifted_at ?? now());
    }

    public function customerReturn(
        CustomerReturn $return,
        mixed $amount,
        mixed $cogs,
        string $currency,
        int $branchId,
        string $refundMethod,
        ?int $financialAccountId = null
    ): JournalEntry {
        $return = $this->invoices->ensureStamped($return);
        $cashAccount = null;
        if (Money::cmp($return->cash_refund, '0') > 0) {
            $cashAccount = $this->accounts->requireFor(
                'customer_return',
                'cash',
                $branchId,
                $currency,
                $financialAccountId,
                Auth::user()
            );
        }

        return $this->v2->postCustomerReturn($return, $cashAccount, $return->returned_at ?? now());
    }

    public function expenseCreate(Expense $expense, ?int $financialAccountId = null): JournalEntry
    {
        $account = $this->accounts->requireFor(
            'expense',
            'cash',
            (int) $expense->branch_id,
            (string) ($expense->currency ?? 'toman'),
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postExpense($expense, $account, $expense->date ?? now());
    }

    public function expenseReplace(Expense $expense, ?int $financialAccountId = null): JournalEntry
    {
        $currency = (string) ($expense->currency ?? 'toman');
        $account = $this->accounts->requireFor(
            'expense',
            'cash',
            (int) $expense->branch_id,
            $currency,
            $financialAccountId,
            Auth::user()
        );
        $active = JournalEntry::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->where('status', 'active')
            ->whereNotIn('event_type', ['expense_reversal'])
            ->orderByDesc('id')
            ->first();
        if (!$active) {
            return $this->v2->postExpense($expense, $account, $expense->date ?? now());
        }

        return $this->v2->reverseAndReplaceExpense($expense, $active, $account, now());
    }

    public function expenseReverse(Expense $expense): ?JournalEntry
    {
        $active = JournalEntry::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->where('status', 'active')
            ->whereNotIn('event_type', ['expense_reversal'])
            ->orderByDesc('id')
            ->first();
        if (!$active) {
            return null;
        }

        return $this->v2->reverse($active, $expense, 'expense_reversal', now());
    }

    public function supplierSettlement(Settlement $settlement, ?int $financialAccountId = null): JournalEntry
    {
        $account = $this->accounts->requireFor(
            'settlement',
            (string) $settlement->payment_method,
            $settlement->branch_id ? (int) $settlement->branch_id : null,
            (string) $settlement->currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postSettlement($settlement, $account, $settlement->paid_at ?? now());
    }

    public function customerPayment(CustomerPayment $payment, ?int $financialAccountId = null): JournalEntry
    {
        $account = $this->accounts->requireFor(
            'customer_payment',
            (string) $payment->method,
            (int) $payment->branch_id,
            (string) $payment->currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postCustomerPayment($payment, $account, $payment->paid_at ?? now());
    }

    public function incomingCheckCleared(Check $check, ?int $financialAccountId = null): JournalEntry
    {
        $account = $this->accounts->requireFor(
            'incoming_check_clear',
            'bank',
            (int) $check->branch_id,
            (string) $check->currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postIncomingCheckCleared($check, $account, $check->cleared_at ?? now());
    }

    public function incomingCheckBounced(Check $check): JournalEntry
    {
        return $this->v2->postIncomingCheckBounced($check, $check->bounced_at ?? now());
    }

    public function supplierCheckCleared(Settlement $settlement, ?int $financialAccountId = null): JournalEntry
    {
        $account = $this->accounts->requireFor(
            'supplier_check_clear',
            'bank',
            $settlement->branch_id ? (int) $settlement->branch_id : null,
            (string) $settlement->currency,
            $financialAccountId,
            Auth::user()
        );

        return $this->v2->postSupplierCheckCleared($settlement, $account, $settlement->cleared_at ?? now());
    }

    public function supplierCheckBounced(Settlement $settlement, string $eventType = 'supplier_check_bounced'): JournalEntry
    {
        $active = JournalEntry::query()
            ->where('source_type', Settlement::class)
            ->where('source_id', $settlement->id)
            ->where('event_type', 'settlement')
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        if (!$active) {
            throw new \App\Exceptions\DomainException('سند صدور چک تأمین‌کننده برای برگشت یافت نشد');
        }

        return $this->v2->reverse($active, $settlement, $eventType, now());
    }

    public function assertLegacyCreditStatusAllowed(string $status): void
    {
        if ($status === 'paid') {
            throw new \App\Exceptions\DomainException(
                'نمی‌توان نسیه را بدون دریافت مشتری paid کرد. از POST /customers/{id}/payments استفاده کنید.',
                409
            );
        }
    }
}
