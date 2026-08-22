<?php

namespace Tests\Feature\Settlement;

use App\Models\StockLot;
use App\Services\Settlement\PayableSnapshot;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group settlement */
class PayableSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_new_full_cost_allocation_two_times_one_hundred_is_two_hundred(): void
    {
        $service = new PayableSnapshot();
        $gross = Money::mul('100', 2);
        $payable = $service->compute($gross, PayableSnapshot::BASIS_FULL_UNIT_COST, '1');
        $this->assertSame('200.00', $payable);
        $this->assertSame('200.00', Money::of($payable));
    }

    public function test_legacy_settled_allocation_retains_actual_historical_payable(): void
    {
        $service = new PayableSnapshot();
        $this->assertSame('90.00', $service->fromStampedRow([
            'publisher_payable' => '90.00',
            'payable_basis' => PayableSnapshot::BASIS_PERCENTAGE_OF_UNIT_COST,
            'payable_rate' => '0.9',
            'rule_source' => 'legacy_inferred',
        ]));
    }

    public function test_unsold_quantity_on_same_receipt_uses_full_cost_from_lot(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $lot = StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => '100.00',
            'qty_original' => 5,
            'qty_available' => 3,
            'origin' => 'other',
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
            'payable_rule_source' => 'legacy_restate',
        ]);

        $snap = (new PayableSnapshot())->fromStampedLot($lot, 3);
        $this->assertSame('300.00', $snap['publisher_payable']);
        $this->assertSame(PayableSnapshot::BASIS_FULL_UNIT_COST, $snap['payable_basis']);
    }

    public function test_partial_old_settlement_remaining_is_full_minus_paid(): void
    {
        $service = new PayableSnapshot();
        $remaining = $service->remaining('200.00', '90.00');
        $this->assertSame('110.00', $remaining);
    }

    public function test_changing_global_commission_does_not_affect_stamped_rows(): void
    {
        config(['almanahel.consignment_commission_rate' => 0.5]);
        Cache::put('almanahel.consignment_commission_rate', 0.5);

        $service = new PayableSnapshot();
        $this->assertSame('200.00', $service->fromStampedRow(['publisher_payable' => '200.00']));
        $this->assertSame('200.00', $service->compute('200', PayableSnapshot::BASIS_FULL_UNIT_COST, '1'));
    }

    public function test_stamped_row_does_not_read_cache_or_config(): void
    {
        Cache::put('almanahel.consignment_commission_rate', 0.99);
        config(['almanahel.consignment_commission_rate' => 0.99]);

        $before = Cache::get('almanahel.consignment_commission_rate');
        $payable = (new PayableSnapshot())->fromStampedRow(['publisher_payable' => '200.00']);
        $this->assertSame('200.00', $payable);
        $this->assertEquals($before, Cache::get('almanahel.consignment_commission_rate'));
    }

    public function test_toman_and_dinar_remain_separate(): void
    {
        $service = new PayableSnapshot();
        $toman = $service->compute('100', PayableSnapshot::BASIS_FULL_UNIT_COST, '1');
        $dinar = $service->compute('50', PayableSnapshot::BASIS_FULL_UNIT_COST, '1');
        $this->assertSame('100.00', $toman);
        $this->assertSame('50.00', $dinar);
        $this->assertNotSame($toman, Money::add($toman, $dinar));
    }

    public function test_unstamped_legacy_lot_fails_closed_when_treated_as_stamped(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $lot = StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => '100.00',
            'qty_original' => 1,
            'qty_available' => 1,
            'origin' => 'other',
        ]);

        $this->expectException(\App\Exceptions\DomainException::class);
        (new PayableSnapshot())->fromStampedLot($lot, 1);
    }

    public function test_for_new_intake_uses_explicit_full_cost_default(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $lot = StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => '100.00',
            'qty_original' => 2,
            'qty_available' => 2,
            'origin' => 'other',
        ]);

        $snap = (new PayableSnapshot())->forNewIntake($lot, 2);
        $this->assertSame('200.00', $snap['publisher_payable']);
        $this->assertSame('intake', $snap['rule_source']);
    }
}
