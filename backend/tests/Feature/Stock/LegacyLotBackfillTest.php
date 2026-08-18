<?php

namespace Tests\Feature\Stock;

use App\Models\Inventory;
use App\Models\StockLot;
use App\Services\Stock\LegacyLotBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group stock */
class LegacyLotBackfillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_preserves_owned_and_consignment_duplicate_rows(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 3,
            'type' => 'owned', 'cost_price_toman' => 50000, 'price_toman' => 80000,
        ]);
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 4,
            'type' => 'consignment', 'supplier_id' => $supplier->id,
            'cost_price_toman' => 60000, 'price_toman' => 90000,
        ]);

        $report = app(LegacyLotBackfill::class)->run(false);
        $this->assertSame(2, (int) $report['created_lots']);
        $lots = StockLot::where('book_id', $book->id)->get();
        $this->assertCount(2, $lots);
        $this->assertTrue($lots->contains(fn ($l) => $l->ownership_type === 'owned' && (int) $l->qty_available === 3));
        $this->assertTrue($lots->contains(fn ($l) => $l->ownership_type === 'consignment' && (int) $l->supplier_id === $supplier->id));
        $this->assertEquals(2, DB::table('legacy_inventory_snapshots')->count());
        $this->assertEquals(1, Inventory::whereNull('superseded_by_inventory_id')->where('book_id', $book->id)->count());
    }

    public function test_two_suppliers_and_two_currencies_become_separate_lots(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $s1 = $this->makeSupplier(['name' => 'A']);
        $s2 = $this->makeSupplier(['name' => 'B']);
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 2,
            'type' => 'consignment', 'supplier_id' => $s1->id, 'cost_price_toman' => 10000,
        ]);
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 5,
            'type' => 'consignment', 'supplier_id' => $s2->id, 'cost_price_dinar' => 2000, 'cost_price_toman' => 0,
        ]);

        app(LegacyLotBackfill::class)->run(false);
        $lots = StockLot::where('book_id', $book->id)->get();
        $this->assertCount(2, $lots);
        $this->assertEqualsCanonicalizing(['toman', 'dinar'], $lots->pluck('currency')->all());
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $lots->pluck('supplier_id')->all());
    }

    public function test_partially_sold_receipt_matching_and_uncertain_remainder(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $receipt = $this->makeConsignmentReceipt($branch, $supplier, $book, [
            'quantity_received' => 10,
            'quantity_sold' => 4,
            'cost_price' => 70000,
        ]);
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 6,
            'type' => 'consignment', 'supplier_id' => $supplier->id, 'cost_price_toman' => 70000,
        ]);

        app(LegacyLotBackfill::class)->run(false);
        $lot = StockLot::where('book_id', $book->id)->first();
        $this->assertNotNull($lot);
        $this->assertEquals($receipt->items->first()->id, $lot->consignment_receipt_item_id);
        $this->assertFalse((bool) $lot->legacy_uncertain);
        $this->assertEquals(6, (int) $lot->qty_available);
    }

    public function test_duplicate_costs_and_idempotent_rerun(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 2,
            'type' => 'owned', 'cost_price_toman' => 10000,
        ]);
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 3,
            'type' => 'owned', 'cost_price_toman' => 15000,
        ]);

        $first = app(LegacyLotBackfill::class)->run(false);
        $second = app(LegacyLotBackfill::class)->run(false);
        $this->assertSame(2, (int) $first['created_lots']);
        $this->assertSame(0, (int) $second['created_lots']);
        $this->assertEquals(2, StockLot::where('book_id', $book->id)->count());
        $this->assertEquals(2, DB::table('legacy_inventory_snapshots')->count());
        $costs = StockLot::where('book_id', $book->id)->pluck('unit_cost')->map(fn ($c) => (float) $c)->all();
        $this->assertEqualsCanonicalizing([10000.0, 15000.0], $costs);
    }

    public function test_dry_run_does_not_write(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        Inventory::create([
            'branch_id' => $branch->id, 'book_id' => $book->id, 'quantity' => 2,
            'type' => 'owned', 'cost_price_toman' => 10000,
        ]);
        $this->artisan('stock:backfill-lots', ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals(0, StockLot::count());
        $this->assertEquals(0, DB::table('legacy_inventory_snapshots')->count());
    }
}
