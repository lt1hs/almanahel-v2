<?php

namespace App\Services\Suppliers;

use App\Models\ConsignmentReceipt;
use App\Models\Settlement;
use App\Models\SupplierAccount;
use App\Services\Ledger\SystemAccounts;
use App\Services\Reports\PostedJournals;
use App\Services\Settlement\SnapshotPayable;
use App\Support\Money;

class SupplierAccountBalance
{
    public function __construct(
        private readonly PostedJournals $journals,
        private readonly SnapshotPayable $snapshots,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forAccount(SupplierAccount $account, ?string $from, ?string $to): array
    {
        $period = $this->journals->period($from, $to);
        $currencies = [];
        foreach (['toman', 'dinar'] as $currency) {
            $currencies[] = $this->currencySlice($account, $currency, $period);
        }

        return [
            'supplier_account_id' => $account->id,
            'supplier_id' => $account->supplier_id,
            'branch_id' => $account->branch_id,
            'from' => $period['from'],
            'to' => $period['to'],
            'currencies' => $currencies,
        ];
    }

    /**
     * @param  array{from: string, to: string, start: \Carbon\Carbon, end: \Carbon\Carbon}  $period
     * @return array<string, mixed>
     */
    private function currencySlice(SupplierAccount $account, string $currency, array $period): array
    {
        $payablePeriod = $this->ledgerTotals($account, $currency, 'supplier_payable', $period['start'], $period['end']);
        $recoverablePeriod = $this->ledgerTotals($account, $currency, 'supplier_recoverable', $period['start'], $period['end']);
        $checksPeriod = $this->ledgerTotals($account, $currency, 'checks_payable', $period['start'], $period['end']);

        $payableAsOf = $this->ledgerTotals($account, $currency, 'supplier_payable', null, $period['end'], 'through');
        $recoverableAsOf = $this->ledgerTotals($account, $currency, 'supplier_recoverable', null, $period['end'], 'through');
        $checksAsOf = $this->ledgerTotals($account, $currency, 'checks_payable', null, $period['end'], 'through');

        $settlementsPaid = '0.00';
        Settlement::query()
            ->where('supplier_account_id', $account->id)
            ->where('currency', $currency)
            ->whereDate('paid_at', '>=', $period['from'])
            ->whereDate('paid_at', '<=', $period['to'])
            ->pluck('amount')
            ->each(function ($amount) use (&$settlementsPaid) {
                $settlementsPaid = Money::add($settlementsPaid, $amount);
            });

        $operationalOutstanding = '0.00';
        ConsignmentReceipt::query()
            ->with('items')
            ->where('supplier_account_id', $account->id)
            ->where('currency', $currency)
            ->get()
            ->each(function (ConsignmentReceipt $receipt) use (&$operationalOutstanding) {
                $operationalOutstanding = Money::add($operationalOutstanding, $this->snapshots->receiptOutstanding($receipt));
            });

        return [
            'currency' => $currency,
            'as_of' => [
                'supplier_payable' => $this->liabilityNet($payableAsOf),
                'supplier_recoverable' => $this->assetNet($recoverableAsOf),
                'checks_payable' => $this->liabilityNet($checksAsOf),
            ],
            'period_activity' => [
                'payable_debits' => Money::of($payablePeriod['debit']),
                'payable_credits' => Money::of($payablePeriod['credit']),
                'recoverable_debits' => Money::of($recoverablePeriod['debit']),
                'recoverable_credits' => Money::of($recoverablePeriod['credit']),
                'checks_debits' => Money::of($checksPeriod['debit']),
                'checks_credits' => Money::of($checksPeriod['credit']),
                'settlements_paid' => $settlementsPaid,
            ],
            'current_operational_snapshot' => [
                'outstanding_payable' => $operationalOutstanding,
                'as_of_label' => 'current',
            ],
        ];
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function ledgerTotals(
        SupplierAccount $account,
        string $currency,
        string $systemCode,
        ?\Carbon\Carbon $from,
        ?\Carbon\Carbon $to,
        string $mode = 'between'
    ): array {
        $ledgerId = SystemAccounts::get($systemCode, $currency)->id;
        $q = $this->journals->baseQuery()
            ->where('journal_lines.supplier_account_id', $account->id)
            ->where('journal_lines.branch_id', $account->branch_id)
            ->where('journal_lines.currency', $currency)
            ->where('journal_lines.ledger_account_id', $ledgerId);

        if ($mode === 'through') {
            $this->journals->applyOccurredAt($q, null, $to, 'through');
        } else {
            $this->journals->applyOccurredAt($q, $from, $to);
        }

        $row = $q->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')->first();

        return [
            'debit' => Money::of($row->debit ?? 0),
            'credit' => Money::of($row->credit ?? 0),
        ];
    }

    /** @param  array{debit: string, credit: string}  $totals */
    private function liabilityNet(array $totals): string
    {
        return Money::sub($totals['credit'], $totals['debit']);
    }

    /** @param  array{debit: string, credit: string}  $totals */
    private function assetNet(array $totals): string
    {
        return Money::sub($totals['debit'], $totals['credit']);
    }
}
