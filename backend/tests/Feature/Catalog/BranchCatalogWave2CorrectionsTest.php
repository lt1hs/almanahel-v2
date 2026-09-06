<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\DomainException;
use App\Models\Book;
use App\Models\BranchCatalogItem;
use App\Models\Gift;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StockLot;
use App\Models\SupplierAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Catalog\BranchCatalogBackfill;
use App\Services\Catalog\BranchCatalogService;
use App\Services\Catalog\CanonicalBookService;
use App\Services\Stock\StockLotService;
use App\Services\Suppliers\SupplierAccountBackfill;
use App\Support\Catalog\CatalogSource;
use App\Support\Catalog\LotStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group catalog
 */
class BranchCatalogWave2CorrectionsTest extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    // --- 1. Branch isolation / forged branch ---

    public function test_admin_books_index_requires_branch_or_aggregate(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $this->getJson('/api/books')->assertStatus(422);
        $this->getJson('/api/books?aggregate=1')->assertOk()->assertJsonPath('aggregate', true);
        $this->getJson('/api/books?branch_id='.$branch->id)->assertOk();
    }

    public function test_non_admin_books_index_scopes_inventories_to_own_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->ensureCatalog($a, $book);
        $this->ensureCatalog($b, $book);
        $supplierB = $this->makeSupplier(['name' => 'Supplier B']);
        $this->makeInventory($b, $book, ['quantity' => 5, 'supplier_id' => $supplierB->id]);

        $this->actingAsRole('branch_manager', $a);
        $payload = $this->getJson('/api/books?branch_id='.$a->id)->assertOk()->json();
        $row = collect($payload)->firstWhere('id', $book->id);
        $this->assertNotNull($row);
        $this->assertCount(0, $row['inventories'] ?? []);
    }

    public function test_forged_branch_id_on_books_index_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);

        $this->getJson('/api/books?branch_id='.$b->id)->assertForbidden();
    }

    public function test_forged_branch_on_by_branch_books_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);

        $this->getJson('/api/branches/'.$b->id.'/books')->assertForbidden();
    }

    public function test_forged_branch_on_inventory_book_branches_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->ensureCatalog($a, $book);
        $this->ensureCatalog($b, $book);
        $this->makeInventory($b, $book, ['quantity' => 3]);

        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/inventory/books/'.$book->id.'/branches?branch_id='.$b->id)
            ->assertForbidden();
    }

    public function test_admin_book_by_branches_includes_warehouse_stock_when_querying_store(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $warehouse = $this->makeBranch([
            'name' => 'انبار مرکزی',
            'city' => 'قم',
            'type' => 'warehouse',
            'is_central_warehouse' => true,
        ]);
        $book = $this->makeBook();
        $this->makeInventory($warehouse, $book, ['quantity' => 8]);

        $this->actingAsRole('admin', $qom);
        $payload = $this->getJson('/api/inventory/books/'.$book->id.'/branches?branch_id='.$qom->id)
            ->assertOk()
            ->json('inventories');

        $branchIds = collect($payload)->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $warehouse->id, $branchIds);
    }

    public function test_forged_branch_on_upsert_pricing_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->actingAsRole('branch_manager', $a);

        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $b->id,
            'book_id' => $book->id,
            'price_toman' => 50000,
        ])->assertForbidden();
    }

    public function test_branch_manager_cannot_update_canonical_book(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->actingAsRole('branch_manager', $branch);

        $this->putJson('/api/books/'.$book->id, ['title' => 'Hacked'])->assertForbidden();
    }

    public function test_low_stock_is_scoped_to_operational_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $bookA = $this->makeBook(['title' => 'A book']);
        $bookB = $this->makeBook(['title' => 'B book']);
        $this->makeInventory($a, $bookA, ['quantity' => 1]);
        $this->makeInventory($b, $bookB, ['quantity' => 1]);

        $this->actingAsRole('branch_manager', $a);
        $rows = $this->getJson('/api/books/low-stock')->assertOk()->json();
        $bookIds = collect($rows)->pluck('book_id')->all();
        $this->assertContains($bookA->id, $bookIds);
        $this->assertNotContains($bookB->id, $bookIds);
    }

    // --- 2. Live catalog provenance ---

    public function test_forged_branch_on_book_show_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->ensureCatalog($a, $book);
        $this->ensureCatalog($b, $book);

        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/books/'.$book->id.'?branch_id='.$b->id)->assertForbidden();
    }

    public function test_forged_branch_on_warehouse_inventory_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);

        $this->getJson('/api/warehouse/'.$b->id.'/inventory')->assertForbidden();
    }

    public function test_admin_book_show_requires_branch_id(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->ensureCatalog($branch, $book);
        $this->actingAsRole('admin', $branch);

        $this->getJson('/api/books/'.$book->id)->assertStatus(422);
        $this->getJson('/api/books/'.$book->id.'?branch_id='.$branch->id)->assertOk();
    }

    public function test_central_intake_marks_catalog_central(): void
    {
        $warehouse = $this->makeBranch([
            'name' => 'Central WH',
            'type' => 'warehouse',
            'is_central_warehouse' => true,
        ]);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        app(StockLotService::class)->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $warehouse->id,
            'ownership_type' => 'owned',
            'supplier_id' => $supplier->id,
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 2,
            'origin' => 'other',
            'migration_source' => 'intake',
        ]);

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $warehouse->id,
            'book_id' => $book->id,
            'source' => CatalogSource::CENTRAL,
        ]);
    }

    public function test_transfer_intake_marks_catalog_transferred(): void
    {
        $from = $this->makeBranch(['name' => 'From']);
        $to = $this->makeBranch(['name' => 'To', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->makeInventory($from, $book, ['quantity' => 5]);

        $parent = StockLot::where('branch_id', $from->id)->where('book_id', $book->id)->first();

        app(StockLotService::class)->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $to->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 2,
            'origin' => 'other',
            'parent_lot_id' => $parent->id,
            'migration_source' => 'transfer',
        ]);

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $to->id,
            'book_id' => $book->id,
            'source' => CatalogSource::TRANSFERRED,
        ]);
    }

    // --- 3. Supplier account on catalog rows ---

    public function test_intake_stamps_distinct_supplier_accounts_per_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        app(SupplierAccountBackfill::class)->run(true);

        $service = app(StockLotService::class);
        $service->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $a->id,
            'ownership_type' => 'owned',
            'supplier_id' => $supplier->id,
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 1,
            'origin' => 'other',
            'migration_source' => 'intake',
        ]);
        $service->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $b->id,
            'ownership_type' => 'owned',
            'supplier_id' => $supplier->id,
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 1,
            'origin' => 'other',
            'migration_source' => 'intake',
        ]);

        $accountA = SupplierAccount::where('branch_id', $a->id)->where('supplier_id', $supplier->id)->value('id');
        $accountB = SupplierAccount::where('branch_id', $b->id)->where('supplier_id', $supplier->id)->value('id');
        $this->assertNotSame($accountA, $accountB);

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $a->id,
            'book_id' => $book->id,
            'local_supplier_account_id' => $accountA,
        ]);
        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $b->id,
            'book_id' => $book->id,
            'local_supplier_account_id' => $accountB,
        ]);
    }

    // --- 4. Activation behavior ---

    public function test_intake_reactivates_deactivated_catalog_row(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $item = BranchCatalogItem::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LOCAL,
            'active' => false,
        ]);

        app(StockLotService::class)->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 1,
            'origin' => 'other',
            'migration_source' => 'intake',
        ]);

        $this->assertTrue($item->fresh()->active);
    }

    public function test_deactivated_catalog_row_hidden_from_pos_search(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook(['isbn' => '9786000000099']);
        BranchCatalogItem::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LOCAL,
            'active' => false,
        ]);

        $this->actingAsRole('branch_manager', $branch);
        $this->getJson('/api/books?lite=1')->assertOk()->assertJsonMissing(['id' => $book->id]);
        $this->getJson('/api/books/by-barcode/9786000000099?branch_id='.$branch->id)->assertStatus(404);
    }

    // --- 5. ISBN / catalog concurrency ---

    public function test_sequential_isbn_reuse_returns_same_canonical_book(): void
    {
        $isbn = '9786000000100';
        $service = app(CanonicalBookService::class);

        [$book1, $reused1] = $service->findOrCreate(['title' => 'T1', 'isbn' => $isbn]);
        [$book2, $reused2] = $service->findOrCreate(['title' => 'T2', 'isbn' => $isbn]);

        $this->assertSame($book1->id, $book2->id);
        $this->assertFalse($reused1);
        $this->assertTrue($reused2);
        $this->assertSame(1, Book::where('isbn', $isbn)->count());
    }

    public function test_isbn_duplicate_key_recovery_reuses_existing_book(): void
    {
        $isbn = '9786000000102';
        $existing = $this->makeBook(['isbn' => $isbn, 'title' => 'Winner']);

        $service = app(CanonicalBookService::class);
        [$book, $reused] = $service->findOrCreate(['title' => 'Late', 'isbn' => $isbn]);

        $this->assertTrue($reused);
        $this->assertSame($existing->id, $book->id);
        $this->assertSame(1, Book::where('isbn', $isbn)->count());
    }

    public function test_sequential_catalog_ensure_returns_same_row(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $catalog = app(BranchCatalogService::class);

        $first = $catalog->ensure($branch->id, $book->id, CatalogSource::LOCAL);
        $second = $catalog->ensure($branch->id, $book->id, CatalogSource::LOCAL);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BranchCatalogItem::where('branch_id', $branch->id)->where('book_id', $book->id)->count());
    }

    public function test_catalog_duplicate_key_recovery_reuses_existing_row(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $existing = BranchCatalogItem::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LOCAL,
            'active' => true,
        ]);

        $resolved = app(BranchCatalogService::class)->ensure(
            $branch->id,
            $book->id,
            CatalogSource::LOCAL,
            null,
            null,
            true,
            false
        );

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, BranchCatalogItem::where('branch_id', $branch->id)->where('book_id', $book->id)->count());
    }

    public function test_isbn_reuse_denies_iraq_only_to_unauthorized_user(): void
    {
        $qom = $this->makeBranch(['city' => 'قم']);
        $iraqBook = $this->makeBook(['isbn' => '9786000000101', 'iraq_only' => true]);
        $this->ensureCatalog($qom, $iraqBook);

        $this->actingAsRole('branch_manager', $qom);
        $this->postJson('/api/books', [
            'title' => 'Reuse attempt',
            'isbn' => '9786000000101',
            'branch_id' => $qom->id,
        ])->assertForbidden();
    }

    // --- 6. Zero-stock pricing links catalog ---

    public function test_zero_stock_pricing_links_branch_catalog_only_for_intended_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();

        $this->actingAsRole('branch_manager', $a);
        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $a->id,
            'book_id' => $book->id,
            'price_toman' => 75000,
        ])->assertOk();

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $a->id,
            'book_id' => $book->id,
            'active' => true,
        ]);
        $this->assertDatabaseMissing('branch_catalog_items', [
            'branch_id' => $b->id,
            'book_id' => $book->id,
        ]);
        $this->assertSame(0, StockLot::where('book_id', $book->id)->count());

        $this->actingAsRole('branch_manager', $b);
        $this->getJson('/api/books?lite=1')->assertOk()->assertJsonMissing(['id' => $book->id]);
    }

    // --- 7. Direct lot-status consumer tests ---

    private function seedSellablePair(int $availableQty, int $blockedQty, string $blockedStatus = LotStatus::QUARANTINED): array
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
            'qty_original' => $blockedQty,
            'qty_available' => $blockedQty,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => $blockedStatus,
            'migration_source' => 'intake',
        ]);

        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => $availableQty,
            'qty_available' => $availableQty,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => LotStatus::AVAILABLE,
            'migration_source' => 'intake',
        ]);

        app(StockLotService::class)->syncAggregateQuantity($branch->id, $book->id);

        return [$branch, $book];
    }

    public function test_sale_allocation_skips_quarantined_lots(): void
    {
        [$branch, $book] = $this->seedSellablePair(2, 5);
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-1',
            'payment_method' => 'cash',
            'currency' => 'toman',
            'total_amount' => 20000,
            'status' => 'completed',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'actual_price' => 10000,
        ]);

        app(StockLotService::class)->allocateSale($item, $branch->id, 2, 'toman');

        $this->assertSame(0, (int) StockLot::where('status', LotStatus::AVAILABLE)->sum('qty_available'));
        $this->assertSame(5, (int) StockLot::where('status', LotStatus::QUARANTINED)->sum('qty_available'));
    }

    public function test_gift_allocation_skips_blocked_lots(): void
    {
        [$branch, $book] = $this->seedSellablePair(1, 4, LotStatus::BLOCKED);
        $gift = Gift::create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create()->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'Test',
            'cost_value' => 10000,
            'currency' => 'toman',
            'is_consignment' => false,
            'gifted_at' => now(),
        ]);

        app(StockLotService::class)->allocateGift($gift);

        $this->assertSame(0, (int) StockLot::where('status', LotStatus::AVAILABLE)->sum('qty_available'));
        $this->assertSame(4, (int) StockLot::where('status', LotStatus::BLOCKED)->sum('qty_available'));
    }

    public function test_transfer_reservation_skips_non_sellable_lots(): void
    {
        [$branch, $book] = $this->seedSellablePair(1, 3);
        $to = $this->makeBranch(['name' => 'Dest', 'city' => 'مشهد']);
        $transfer = Transfer::create([
            'from_branch_id' => $branch->id,
            'to_branch_id' => $to->id,
            'status' => 'pending',
            'user_id' => User::factory()->create()->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
        ]);

        app(StockLotService::class)->reserveForTransfer($transfer, [['book_id' => $book->id, 'quantity' => 1]]);

        $this->assertSame(0, (int) StockLot::where('status', LotStatus::AVAILABLE)->sum('qty_available'));
    }

    public function test_consignment_return_skips_quarantined_lots(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->ensureCatalog($branch, $book);

        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => 3,
            'qty_available' => 3,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => LotStatus::QUARANTINED,
            'migration_source' => 'intake',
        ]);
        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'qty_original' => 1,
            'qty_available' => 1,
            'qty_reserved' => 0,
            'origin' => 'other',
            'status' => LotStatus::AVAILABLE,
            'migration_source' => 'intake',
        ]);

        app(StockLotService::class)->allocateConsignmentReturn($branch->id, $supplier->id, $book->id, 1);

        $this->assertSame(0, (int) StockLot::where('status', LotStatus::AVAILABLE)->sum('qty_available'));
        $this->assertSame(3, (int) StockLot::where('status', LotStatus::QUARANTINED)->sum('qty_available'));
    }

    public function test_aggregate_sync_counts_only_sellable_lots(): void
    {
        [$branch, $book] = $this->seedSellablePair(4, 6);
        $inventory = Inventory::where('branch_id', $branch->id)->where('book_id', $book->id)->first();
        $this->assertSame(4, (int) $inventory->quantity);
    }

    public function test_non_sellable_lots_cannot_satisfy_insufficient_operation(): void
    {
        [$branch, $book] = $this->seedSellablePair(0, 5);
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-2',
            'payment_method' => 'cash',
            'currency' => 'toman',
            'total_amount' => 10000,
            'status' => 'completed',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'actual_price' => 10000,
        ]);

        $this->expectException(DomainException::class);
        app(StockLotService::class)->allocateSale($item, $branch->id, 1, 'toman');
    }

    public function test_create_intake_lot_rejects_unknown_status(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();

        $this->expectException(DomainException::class);
        app(StockLotService::class)->createIntakeLot([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => '10000.00',
            'quantity' => 1,
            'origin' => 'other',
            'status' => 'mystery',
        ]);
    }

    // --- 8. Backfill MySQL-safe / idempotent ---

    public function test_backfill_dry_run_apply_and_idempotency(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        Inventory::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 0,
            'type' => 'owned',
        ]);

        $backfill = app(BranchCatalogBackfill::class);
        $dry = $backfill->run(false);
        $this->assertGreaterThanOrEqual(1, $dry['pairs_discovered']);
        $this->assertSame(0, $dry['created']);

        $apply1 = $backfill->run(true);
        $this->assertGreaterThanOrEqual(1, $apply1['created']);

        $apply2 = $backfill->run(true);
        $this->assertSame(0, $apply2['created']);
        $this->assertGreaterThanOrEqual(1, $apply2['skipped_existing']);
    }

    // --- Admin catalog membership enforcement ---

    public function test_admin_show_can_open_book_outside_requested_branch_catalog(): void
    {
        $branch = $this->makeBranch(['name' => 'A']);
        $other = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $linked = $this->makeBook(['title' => 'Linked']);
        $unlinked = $this->makeBook(['title' => 'Unlinked']);
        $this->ensureCatalog($branch, $linked);
        $this->ensureCatalog($other, $unlinked);

        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/books/'.$linked->id.'?branch_id='.$branch->id)->assertOk();
        $this->getJson('/api/books/'.$unlinked->id.'?branch_id='.$branch->id)->assertOk();
    }

    public function test_admin_show_iraq_only_book_with_iran_branch_id_succeeds(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $iraq = $this->makeBranch([
            'name' => 'دارالمناهل عراق',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
        ]);
        $book = $this->makeBook(['iraq_only' => true, 'title' => 'کتاب عراق']);
        $this->makeInventory($iraq, $book, ['quantity' => 12]);

        $this->actingAsRole('admin', $qom);
        $this->getJson('/api/books/'.$book->id.'?branch_id='.$qom->id)
            ->assertOk()
            ->assertJsonPath('id', $book->id)
            ->assertJsonPath('iraq_only', true);
    }

    public function test_admin_show_returns_inventories_for_all_branches(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $iraq = $this->makeBranch([
            'name' => 'دارالمناهل عراق',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
        ]);
        $book = $this->makeBook();
        $this->makeInventory($qom, $book, ['quantity' => 2]);
        $this->makeInventory($iraq, $book, ['quantity' => 9]);

        $this->actingAsRole('admin', $qom);
        $payload = $this->getJson('/api/books/'.$book->id.'?branch_id='.$qom->id)
            ->assertOk()
            ->json('inventories');

        $branchIds = collect($payload)->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $qom->id, $branchIds);
        $this->assertContains((int) $iraq->id, $branchIds);
    }

    public function test_admin_show_inactive_catalog_item_still_loads_for_edit(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        BranchCatalogItem::create([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'source' => CatalogSource::LOCAL,
            'active' => false,
        ]);

        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/books/'.$book->id.'?branch_id='.$branch->id)->assertOk();
    }

    public function test_branch_manager_show_still_requires_active_catalog_membership(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $iraq = $this->makeBranch([
            'name' => 'دارالمناهل عراق',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
        ]);
        $book = $this->makeBook();
        $this->ensureCatalog($iraq, $book);

        $this->actingAsRole('branch_manager', $qom);
        $this->getJson('/api/books/'.$book->id.'?branch_id='.$qom->id)->assertStatus(404);
    }

    public function test_admin_barcode_lookup_requires_active_catalog_membership(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook(['isbn' => '9786000000200']);
        $this->ensureCatalog($branch, $book);

        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/books/by-barcode/9786000000200?branch_id='.$branch->id)->assertOk();

        $other = $this->makeBook(['isbn' => '9786000000201']);
        $this->getJson('/api/books/by-barcode/9786000000201?branch_id='.$branch->id)->assertStatus(404);
    }

    // --- POST /books branch resolution ---

    public function test_post_books_admin_requires_branch_id(): void
    {
        $this->actingAsRole('admin');
        $this->postJson('/api/books', ['title' => 'Orphan'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'branch_required');
    }

    public function test_post_books_local_branch_uses_local_source(): void
    {
        $branch = $this->makeBranch(['city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $branch);

        $response = $this->postJson('/api/books', [
            'title' => 'POS Title',
            'branch_id' => $branch->id,
        ])->assertCreated();

        $bookId = $response->json('book.id');
        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $branch->id,
            'book_id' => $bookId,
            'source' => CatalogSource::LOCAL,
        ]);
    }

    public function test_post_books_central_warehouse_uses_central_source(): void
    {
        $warehouse = $this->makeBranch([
            'type' => 'warehouse',
            'is_central_warehouse' => true,
        ]);
        $this->actingAsRole('warehouse_staff', $warehouse);

        $response = $this->postJson('/api/books', [
            'title' => 'Warehouse Title',
            'branch_id' => $warehouse->id,
        ])->assertCreated();

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $warehouse->id,
            'book_id' => $response->json('book.id'),
            'source' => CatalogSource::CENTRAL,
        ]);
    }

    public function test_post_books_forged_branch_is_denied(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);

        $this->postJson('/api/books', [
            'title' => 'Forged',
            'branch_id' => $b->id,
        ])->assertForbidden();
    }

    public function test_post_books_accountant_is_forbidden(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('accountant', $branch);

        $this->postJson('/api/books', [
            'title' => 'No',
            'branch_id' => $branch->id,
        ])->assertForbidden();
    }

    // --- upsert-pricing catalog policy ---

    public function test_upsert_pricing_accountant_is_forbidden(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->actingAsRole('accountant', $branch);

        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'price_toman' => 50000,
        ])->assertForbidden();
    }

    public function test_upsert_pricing_rejects_iraq_only_for_unauthorized_user(): void
    {
        $qom = $this->makeBranch(['city' => 'قم']);
        $book = $this->makeBook(['iraq_only' => true]);
        $this->actingAsRole('branch_manager', $qom);

        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $qom->id,
            'book_id' => $book->id,
            'price_toman' => 50000,
        ])->assertForbidden();
    }

    public function test_upsert_pricing_forbidden_for_warehouse_staff(): void
    {
        $warehouse = $this->makeBranch([
            'type' => 'warehouse',
            'is_central_warehouse' => true,
        ]);
        $book = $this->makeBook();
        $this->actingAsRole('warehouse_staff', $warehouse);

        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $warehouse->id,
            'book_id' => $book->id,
            'price_toman' => 50000,
        ])->assertForbidden();
    }

    // --- iraq visibility must not grant operational access ---

    public function test_iraq_visibility_does_not_grant_other_branch_inventory(): void
    {
        $qom = $this->makeBranch(['name' => 'Qom', 'city' => 'قم']);
        $iraq = $this->makeBranch(['name' => 'Iraq', 'city' => 'نجف', 'country' => 'عراق', 'is_iraq_store' => true]);
        $book = $this->makeBook(['iraq_only' => true]);
        $this->ensureCatalog($iraq, $book);
        $this->makeInventory($iraq, $book, ['quantity' => 4]);

        $manager = $this->makeUser($qom, 'branch_manager', [
            'iraq_only_visible_branches' => [$iraq->id],
        ]);
        $this->actingAs($manager);

        $this->getJson('/api/warehouse/'.$iraq->id.'/inventory')->assertForbidden();
        $this->getJson('/api/branches/'.$iraq->id.'/books')->assertForbidden();
        $this->getJson('/api/inventory/books/'.$book->id.'/branches?branch_id='.$iraq->id)->assertForbidden();
    }

    // --- branch-catalog controller ---

    public function test_branch_catalog_aggregate_is_summary_only(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->ensureCatalog($a, $book);
        $this->ensureCatalog($b, $book);

        $this->actingAsRole('admin', $a);
        $payload = $this->getJson('/api/branch-catalog?aggregate=1')->assertOk()->json();

        $this->assertTrue($payload['aggregate']);
        $this->assertArrayNotHasKey('local_supplier_account_id', $payload['items'][0] ?? []);
        $this->assertArrayHasKey('active_branch_count', $payload['items'][0] ?? []);
    }

    public function test_branch_catalog_requires_catalog_read_policy(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser(null, 'branch_manager', ['branch_id' => null]);
        $this->actingAs($user);

        $this->getJson('/api/branch-catalog?branch_id='.$branch->id)->assertStatus(422);
    }
}
