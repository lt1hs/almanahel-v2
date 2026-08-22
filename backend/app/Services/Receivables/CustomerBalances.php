<?php

namespace App\Services\Receivables;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Invoice;
use App\Support\Money;

class CustomerBalances
{
    public function __construct(private readonly InvoiceBalance $invoices)
    {
    }

    /**
     * Per-currency operational snapshot.
     *
     * `customer_credit` / `customer_credit_outstanding` is an outstanding
     * `customer_credit_liability`. It is not redeemable until a redemption
     * workflow exists.
     *
     * @return array<string, array{
     *     accounts_receivable: string,
     *     customer_credit: string,
     *     customer_credit_outstanding: string,
     *     customer_credit_is_outstanding_liability: true,
     *     customer_credit_redeemable: false,
     *     net_balance: string,
     *     open_invoices: int,
     *     overdue_invoices: int
     * }>
     */
    public function forCustomer(Customer $customer): array
    {
        $out = [];
        foreach (['toman', 'dinar'] as $currency) {
            $ar = '0.00';
            $open = 0;
            $overdue = 0;
            $invoices = Invoice::query()
                ->where('customer_id', $customer->id)
                ->where('currency', $currency)
                ->get();
            foreach ($invoices as $invoice) {
                $outstanding = $this->invoices->outstanding($invoice);
                $ar = Money::add($ar, $outstanding);
                if (Money::cmp($outstanding, '0') > 0) {
                    $open++;
                }
                if ($this->invoices->status($invoice) === 'overdue') {
                    $overdue++;
                }
            }

            $credit = '0.00';
            $returns = CustomerReturn::query()
                ->whereHas('invoice', fn ($q) => $q->where('customer_id', $customer->id))
                ->where(function ($q) use ($currency) {
                    $q->where('currency', $currency)
                        ->orWhere(function ($inner) use ($currency) {
                            $inner->whereNull('currency')
                                ->whereHas('invoice', fn ($inv) => $inv->where('currency', $currency));
                        });
                })
                ->get();
            foreach ($returns as $return) {
                $credit = Money::add($credit, $return->customer_credit_created ?? 0);
            }

            $out[$currency] = [
                'accounts_receivable' => $ar,
                'customer_credit' => $credit,
                'customer_credit_outstanding' => $credit,
                'customer_credit_is_outstanding_liability' => true,
                'customer_credit_redeemable' => false,
                'net_balance' => Money::sub($ar, $credit),
                'open_invoices' => $open,
                'overdue_invoices' => $overdue,
            ];
        }

        return $out;
    }
}
