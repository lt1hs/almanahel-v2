<?php

namespace App\Services\Receivables;

use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\Invoice;
use App\Services\Ledger\CheckLifecycle;
use App\Support\Money;
use Carbon\Carbon;

class InvoiceBalance
{
    public function original(Invoice $invoice): string
    {
        return Money::of($invoice->total);
    }

    public function receivableReductions(Invoice $invoice): string
    {
        return $this->receivableReductionsAsOf($invoice, now());
    }

    public function receivableReductionsAsOf(Invoice $invoice, Carbon $asOf): string
    {
        $sum = '0.00';
        foreach (CustomerReturn::query()->where('invoice_id', $invoice->id)->get() as $return) {
            if ($return->receivable_reduction === null) {
                continue;
            }
            if ($return->returned_at === null || $return->returned_at->gt($asOf)) {
                continue;
            }
            $sum = Money::add($sum, $return->receivable_reduction);
        }

        return $sum;
    }

    public function payments(Invoice $invoice): string
    {
        return $this->paymentsAsOf($invoice, now());
    }

    public function paymentsAsOf(Invoice $invoice, Carbon $asOf): string
    {
        $sum = '0.00';
        foreach (CustomerPayment::query()->where('invoice_id', $invoice->id)->get() as $payment) {
            if ($payment->paid_at === null || $payment->paid_at->gt($asOf)) {
                continue;
            }
            $sum = Money::add($sum, $payment->amount);
        }

        return $sum;
    }

    public function incomingCheck(Invoice $invoice): ?Check
    {
        if ($invoice->relationLoaded('check')) {
            return $invoice->check;
        }

        return Check::query()->where('invoice_id', $invoice->id)->first();
    }

    public function carriesOpenReceivable(Invoice $invoice): bool
    {
        return $this->carriesOpenReceivableAsOf($invoice, now());
    }

    public function carriesOpenReceivableAsOf(Invoice $invoice, Carbon $asOf): bool
    {
        if ($invoice->sold_at === null || $invoice->sold_at->gt($asOf)) {
            return false;
        }
        $method = (string) $invoice->payment_method;
        if (in_array($method, ['cash', 'card'], true)) {
            return false;
        }
        if ($method === 'credit') {
            return true;
        }
        if ($method === 'check') {
            $check = $this->incomingCheck($invoice);
            if ($check === null) {
                return false;
            }
            $check->setRelation('invoice', $invoice);

            return CheckLifecycle::incomingStatusAsOf($check, $asOf) === 'bounced';
        }

        return false;
    }

    public function outstanding(Invoice $invoice): string
    {
        return $this->outstandingAsOf($invoice, now());
    }

    public function outstandingAsOf(Invoice $invoice, Carbon $asOf): string
    {
        if (!$this->carriesOpenReceivableAsOf($invoice, $asOf)) {
            return '0.00';
        }

        return Money::max('0', Money::sub(
            Money::sub($this->original($invoice), $this->receivableReductionsAsOf($invoice, $asOf)),
            $this->paymentsAsOf($invoice, $asOf)
        ));
    }

    public function status(Invoice $invoice): string
    {
        return $this->statusAsOf($invoice, now());
    }

    public function statusAsOf(Invoice $invoice, Carbon $asOf): string
    {
        if ($invoice->sold_at === null || $invoice->sold_at->gt($asOf)) {
            return 'not_issued';
        }
        $method = (string) $invoice->payment_method;
        if (in_array($method, ['cash', 'card'], true)) {
            return 'paid';
        }

        if ($method === 'check') {
            $check = $this->incomingCheck($invoice);
            if ($check !== null) {
                $check->setRelation('invoice', $invoice);
            }
            $checkStatus = $check ? CheckLifecycle::incomingStatusAsOf($check, $asOf) : null;
            if ($checkStatus === 'pending') {
                return 'pending';
            }
            if ($checkStatus === 'cleared') {
                return 'paid';
            }
            if ($checkStatus === null) {
                return 'not_issued';
            }
        }

        return $this->receivableStatusAsOf($invoice, $asOf);
    }

    public function refresh(Invoice $invoice): Invoice
    {
        $invoice->update(['payment_status' => $this->status($invoice)]);

        return $invoice->fresh();
    }

    /**
     * @return array{receivable_reduction: string, cash_refund: string, customer_credit_created: string, currency: string}
     */
    public function planReturn(Invoice $invoice, mixed $returnAmount, string $refundMethod): array
    {
        $amount = Money::of($returnAmount);
        $open = $this->outstanding($invoice);
        $receivableReduction = Money::min($amount, $open);
        $residual = Money::sub($amount, $receivableReduction);
        $cashRefund = '0.00';
        $customerCredit = '0.00';
        if (!Money::isZero($residual)) {
            if ($refundMethod === 'cash') {
                $cashRefund = $residual;
            } elseif ($refundMethod === 'credit') {
                $customerCredit = $residual;
            } else {
                throw new \App\Exceptions\DomainException('روش بازپرداخت نامعتبر است', 422);
            }
        }

        return [
            'receivable_reduction' => $receivableReduction,
            'cash_refund' => $cashRefund,
            'customer_credit_created' => $customerCredit,
            'currency' => (string) $invoice->currency,
        ];
    }

    public function ensureStamped(CustomerReturn $return): CustomerReturn
    {
        if ($return->receivable_reduction !== null && $return->currency) {
            return $return;
        }

        $invoice = Invoice::query()->whereKey($return->invoice_id)->lockForUpdate()->firstOrFail();
        $plan = $this->planReturn($invoice, $return->refund_amount, (string) $return->refund_method);
        $return->forceFill($plan)->save();

        return $return->fresh();
    }

    private function receivableStatusAsOf(Invoice $invoice, Carbon $asOf): string
    {
        $original = $this->original($invoice);
        $outstanding = $this->outstandingAsOf($invoice, $asOf);
        $due = $invoice->due_date;
        $pastDue = $due && $due->toDateString() < $asOf->toDateString();

        if (Money::isZero($outstanding)) {
            return 'paid';
        }
        if ($pastDue) {
            return 'overdue';
        }
        if (Money::cmp($outstanding, $original) === 0) {
            return 'pending';
        }

        return 'partially_paid';
    }
}
