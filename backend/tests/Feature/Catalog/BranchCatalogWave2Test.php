<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\BranchCatalogItem;
use App\Models\StockLot;
use App\Services\Catalog\BranchCatalogBackfill;
use App\Services\Catalog\BranchCatalogService;
use App\Services\Stock\StockLotService;
use App\Support\Catalog\CatalogSource;
use App\Support\Catalog\LotStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group catalog
 */
class BranchCatalogWave2Test extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    public function test_schema_has_branch_catalog_and_lot_status(): void
    {
        $this->assertTrue(\Schema::hasTable('branch_catalog_items'));
        $this->assertTrue(\Schema::hasColumn('stock_lots', 'status'));
    }

    public function test_local_book_invisible_to_other_branch(): void
    {
        $a = $this->makeBranch(['name' => 'Branch A']);
        $b = $this->makeBranch(['name' => 'Branch B', 'city' => 'مشهد']);
        $book = $this->makeBook(['isbn' => '9786000000001']);
        $this->ensureCatalog($a, $book, CatalogSource::LOCAL);

        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/books?lite=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $book->id]);

        $this->actingAsRole('branch_manager', $b);
        $this->getJson('/api/books?lite=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $book->id]);
    }

    public function test_isbn_reuse_adds_catalog_only_for_creating_branch(): void
    {
        $a = $this->makeBranch(['name' => 'Branch A']);
        $b = $this->makeBranch(['name' => 'Branch B', 'city' => 'مشهد']);
        $isbn = '9786000000002';

        $this->actingAsRole('branch_manager', $a);
        $first = $this->postJson('/api/books', [
            'title' => 'Title A',
            'isbn' => $isbn,
            'branch_id' => $a->id,
        ])->assertCreated()->json();

        $bookId = $first['book']['id'] ?? $first['id'];

        $this->actingAsRole('branch_manager', $b);
        $second = $this->postJson('/api/books', [
            'title' => 'Title B',
            'isbn' => $isbn,
            'branch_id' => $b->id,
        ])->assertOk()->json();

        $this->assertSame($bookId, $second['book']['id']);
        $this->assertTrue($second['reused_canonical']);
        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $a->id,
            'book_id' => $bookId,
            'source' => CatalogSource::LOCAL,
        ]);
        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $b->id,
            'book_id' => $bookId,
            'source' => CatalogSource::LOCAL,
        ]);
        $this->assertSame(1, Book::where('isbn', $isbn)->count());
    }

    public function test_by_barcode_requires_branch_catalog_membership(): void
    {
        $a = $this->makeBranch(['name' => 'Branch A']);
        $b = $this->makeBranch(['name' => 'Branch B', 'city' => 'مشهد']);
        $book = $this->makeBook(['isbn' => '9786000000003']);
        $this->ensureCatalog($a, $book);

        $this->actingAsRole('branch_manager', $b);
        $this->getJson('/api/books/by-barcode/9786000000003?branch_id='.$b->id)
            ->assertStatus(404);

        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/books/by-barcode/9786000000003?branch_id='.$a->id)
            ->assertOk()
            ->assertJsonPath('id', $book->id);
    }

    public function test_fifo_skips_quarantined_lots(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->ensureCatalog($branch, $book);

        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => 5,
            'qty_available' => 5,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => LotStatus::QUARANTINED,
            'migration_source' => 'intake',
        ]);

        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => 3,
            'qty_available' => 3,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => LotStatus::AVAILABLE,
            'migration_source' => 'intake',
        ]);

        $service = app(StockLotService::class);
        $ref = new \App\Models\StockAdjustment([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity_delta' => -4,
            'reason' => 'test',
        ]);

        $ref->save();

        $service->adjust($branch->id, $book->id, -3, 'test', $ref);

        $this->assertSame(0, (int) StockLot::where('branch_id', $branch->id)
            ->where('book_id', $book->id)
            ->where('status', LotStatus::AVAILABLE)
            ->value('qty_available'));
        $this->assertSame(5, (int) StockLot::where('status', LotStatus::QUARANTINED)->value('qty_available'));
    }

    public function test_backfill_uses_legacy_unknown_without_transfer_proof(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();

        \App\Models\Inventory::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 0,
            'type' => 'owned',
        ]);

        $report = app(BranchCatalogBackfill::class)->run(true);

        $this->assertGreaterThanOrEqual(1, $report['created']);
        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LEGACY_UNKNOWN,
        ]);
    }

    public function test_backfill_marks_transferred_when_transfer_chain_exists(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();

        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => 2,
            'qty_available' => 2,
            'qty_reserved' => 0,
            'origin' => 'other',
            'parent_lot_id' => null,
            'migration_source' => 'transfer',
            'status' => LotStatus::AVAILABLE,
        ]);

        app(BranchCatalogBackfill::class)->run(true);

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::TRANSFERRED,
        ]);
    }

    public function test_intake_creates_local_catalog_row(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        app(StockLotService::class)->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'supplier_id' => $supplier->id,
            'currency' => 'toman',
            'unit_cost' => '12000.00',
            'quantity' => 2,
            'origin' => 'other',
            'migration_source' => 'intake',
        ]);

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LOCAL,
        ]);
    }

    public function test_admin_can_list_legacy_unknown_catalog_items(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        BranchCatalogItem::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LEGACY_UNKNOWN,
            'active' => true,
        ]);

        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/branch-catalog?branch_id='.$branch->id.'&source=legacy_unknown')
            ->assertOk()
            ->assertJsonFragment(['book_id' => $book->id, 'source' => CatalogSource::LEGACY_UNKNOWN]);
    }

    public function test_infer_backfill_source_never_guesses_central_from_branch_type(): void
    {
        $warehouse = $this->makeBranch([
            'name' => 'Central WH',
            'type' => 'warehouse',
            'is_central_warehouse' => true,
            'is_intake_hub' => true,
        ]);
        $book = $this->makeBook();

        \App\Models\Inventory::create([
            'branch_id' => $warehouse->id,
            'book_id' => $book->id,
            'quantity' => 0,
            'type' => 'owned',
        ]);

        $source = app(BranchCatalogService::class)->inferBackfillSource($warehouse->id, $book->id);
        $this->assertSame(CatalogSource::LEGACY_UNKNOWN, $source);
    }
}
