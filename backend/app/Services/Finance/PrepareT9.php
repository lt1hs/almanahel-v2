<?php

namespace App\Services\Finance;

use App\Models\ConsignmentReceipt;
use App\Models\CustomerReturn;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Services\Settlement\PayableSnapshot;
use App\Support\ConsignmentFinance;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrepareT9
{
    /**
     * @return array<string, mixed>
     */
    public function run(bool $apply): array
    {
        $report = [
            'apply' => $apply,
            'dates' => ['invoices' => 0, 'returns' => 0, 'settlements' => 0],
            'receipts_stamped' => 0,
            'lots_stamped' => 0,
            'sale_allocations_stamped' => 0,
            'gift_allocations_stamped' => 0,
            'ambiguous' => [],
            'writes' => 0,
        ];

        $work = function () use ($apply, &$report) {
            if (!$this->schemaReady($report)) {
                return;
            }
            $this->stampDates($apply, $report);
            $this->stampReceiptsAndLots($apply, $report);
            $this->stampSaleAllocations($apply, $report);
            $this->stampGiftAllocations($apply, $report);
            $this->inspectSettlements($report);
            $this->inspectReturns($report);
        };

        if ($apply) {
            DB::transaction($work);
        } else {
            $work();
        }

        $report['blocker_count'] = count($report['ambiguous']);

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function schemaReady(array &$report): bool
    {
        $required = [
            'invoices' => ['sold_at'],
            'customer_returns' => ['returned_at'],
            'settlements' => ['paid_at'],
            'stock_lots' => ['payable_basis', 'payable_rate'],
            'sale_lot_allocations' => ['publisher_payable', 'payable_basis'],
            'consignment_receipts' => ['payable_basis'],
        ];
        $ok = true;
        foreach ($required as $table => $cols) {
            if (!Schema::hasTable($table)) {
                $report['ambiguous'][] = "missing table {$table} (2026_08_18 migrations not applied)";
                $ok = false;
                continue;
            }
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) {
                    $report['ambiguous'][] = "missing column {$table}.{$col}";
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /** @param array<string, mixed> $report */
    private function stampDates(bool $apply, array &$report): void
    {
        $invoices = Invoice::query()->whereNull('sold_at')->get();
        $report['dates']['invoices'] = $invoices->count();
        if ($apply) {
            foreach ($invoices as $invoice) {
                $invoice->forceFill(['sold_at' => $invoice->created_at])->save();
                $report['writes']++;
            }
        }

        $returns = CustomerReturn::query()->whereNull('returned_at')->get();
        $report['dates']['returns'] = $returns->count();
        if ($apply) {
            foreach ($returns as $return) {
                $return->forceFill(['returned_at' => $return->created_at])->save();
                $report['writes']++;
            }
        }

        $settlements = Settlement::query()->whereNull('paid_at')->get();
        $report['dates']['settlements'] = $settlements->count();
        if ($apply) {
            foreach ($settlements as $settlement) {
                $settlement->forceFill(['paid_at' => $settlement->created_at])->save();
                $report['writes']++;
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function stampReceiptsAndLots(bool $apply, array &$report): void
    {
        $receipts = ConsignmentReceipt::query()->whereNull('payable_basis')->get();
        foreach ($receipts as $receipt) {
            $report['receipts_stamped']++;
            if ($apply) {
                $receipt->forceFill([
                    'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
                    'payable_rate' => '1.0000',
                    'payable_rule_source' => 'intake',
                    'payable_rule_stamped_at' => $receipt->received_at ?? $receipt->created_at,
                ])->save();
                $report['writes']++;
            }
        }

        $lots = StockLot::query()
            ->where('ownership_type', 'consignment')
            ->where(function ($q) {
                $q->whereNull('payable_basis')->orWhereNull('payable_rate');
            })
            ->get();
        foreach ($lots as $lot) {
            if (!$lot->supplier_id || !$lot->currency) {
                $report['ambiguous'][] = 'unstamped consignment lot missing supplier/currency: ' . $lot->id;
                continue;
            }
            $report['lots_stamped']++;
            if ($apply) {
                $lot->forceFill([
                    'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
                    'payable_rate' => '1.0000',
                    'payable_rule_source' => 'intake',
                    'payable_rule_stamped_at' => $lot->created_at,
                ])->save();
                $report['writes']++;
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function stampSaleAllocations(bool $apply, array &$report): void
    {
        $snapshot = new PayableSnapshot();
        SaleLotAllocation::query()->with('lot')->orderBy('id')->chunkById(100, function ($rows) use ($apply, &$report, $snapshot) {
            foreach ($rows as $alloc) {
                $lot = $alloc->lot;
                if (!$lot || $lot->ownership_type !== 'consignment') {
                    continue;
                }
                if ($alloc->publisher_payable !== null && $alloc->payable_basis !== null) {
                    continue;
                }
                if (!$lot->supplier_id || $alloc->currency !== $lot->currency) {
                    $report['ambiguous'][] = 'sale allocation mapping ambiguous: ' . $alloc->id;
                    continue;
                }
                $qty = (int) $alloc->quantity;
                $gross = Money::mul($alloc->unit_cost, $qty);
                $settled = Money::of(SettlementAllocation::query()
                    ->where('sale_lot_allocation_id', $alloc->id)
                    ->sum('amount'));
                $legacyOwed = ConsignmentFinance::publisherShare($gross);
                $fullySettled = Money::cmp($settled, $legacyOwed) >= 0 && Money::cmp($legacyOwed, '0') > 0
                    && Money::cmp($settled, $gross) <= 0;
                if (Money::cmp($settled, $gross) > 0) {
                    $report['ambiguous'][] = 'sale allocation settled above gross cost: ' . $alloc->id;
                    continue;
                }
                if ($fullySettled) {
                    $fields = $snapshot->inferLegacy($alloc->unit_cost, $qty, $settled);
                } else {
                    $fields = $snapshot->snapshotFull($alloc->unit_cost, $qty);
                }
                $report['sale_allocations_stamped']++;
                if ($apply) {
                    $alloc->forceFill([
                        'payable_basis' => $fields['payable_basis'],
                        'payable_rate' => $fields['payable_rate'],
                        'gross_cost' => $fields['gross_cost'],
                        'publisher_payable' => $fields['publisher_payable'],
                        'rule_source' => $fields['rule_source'],
                        'rule_stamped_at' => now(),
                    ])->save();
                    $report['writes']++;
                }
            }
        });
    }

    /** @param array<string, mixed> $report */
    private function stampGiftAllocations(bool $apply, array &$report): void
    {
        $snapshot = new PayableSnapshot();
        GiftLotAllocation::query()
            ->where('ownership_type', 'consignment')
            ->where(function ($q) {
                $q->whereNull('publisher_payable')->orWhereNull('payable_basis');
            })
            ->get()
            ->each(function ($alloc) use ($apply, &$report, $snapshot) {
                $qty = (int) $alloc->quantity;
                $gross = Money::mul($alloc->unit_cost, $qty);
                $settled = Money::of(SettlementAllocation::query()
                    ->where('gift_lot_allocation_id', $alloc->id)
                    ->sum('amount'));
                if (Money::cmp($settled, $gross) > 0) {
                    $report['ambiguous'][] = 'gift allocation settled above gross cost: ' . $alloc->id;
                    return;
                }
                $legacyOwed = ConsignmentFinance::publisherShare($gross);
                $fullySettled = Money::cmp($settled, $legacyOwed) >= 0 && Money::cmp($legacyOwed, '0') > 0;
                $fields = $fullySettled
                    ? $snapshot->inferLegacy($alloc->unit_cost, $qty, $settled)
                    : $snapshot->snapshotFull($alloc->unit_cost, $qty);
                $report['gift_allocations_stamped']++;
                if ($apply) {
                    $alloc->forceFill([
                        'payable_basis' => $fields['payable_basis'],
                        'payable_rate' => $fields['payable_rate'],
                        'gross_cost' => $fields['gross_cost'],
                        'publisher_payable' => $fields['publisher_payable'],
                        'rule_source' => $fields['rule_source'],
                        'rule_stamped_at' => now(),
                    ])->save();
                    $report['writes']++;
                }
            });
    }

    /** @param array<string, mixed> $report */
    private function inspectSettlements(array &$report): void
    {
        foreach (Settlement::query()->with('allocations')->get() as $settlement) {
            $sum = '0.00';
            foreach ($settlement->allocations as $row) {
                $sum = Money::add($sum, $row->amount);
                if (!$row->sale_lot_allocation_id && !$row->gift_lot_allocation_id) {
                    $report['ambiguous'][] = 'settlement allocation missing payable mapping: ' . $row->id;
                }
            }
            if ($settlement->allocations->isEmpty() && Money::cmp($settlement->amount, '0') > 0) {
                $report['ambiguous'][] = 'settlement without allocations: ' . $settlement->id;
            } elseif ($settlement->allocations->isNotEmpty() && Money::cmp($sum, $settlement->amount) !== 0) {
                $report['ambiguous'][] = 'settlement allocation sum mismatch: ' . $settlement->id;
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function inspectReturns(array &$report): void
    {
        $withReturned = SaleLotAllocation::query()->where('quantity_returned', '>', 0)->get();
        foreach ($withReturned as $alloc) {
            $qty = (int) \App\Models\CustomerReturnLotAllocation::query()
                ->where('sale_lot_allocation_id', $alloc->id)
                ->sum('quantity');
            if ($qty !== (int) $alloc->quantity_returned) {
                $report['ambiguous'][] = 'customer return lot rows missing for sale allocation: ' . $alloc->id;
            }
        }
    }
}
