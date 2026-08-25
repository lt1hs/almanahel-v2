<?php

namespace Tests\Feature\Auth;

use App\Models\Inventory;
use App\Models\WarehouseLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group auth */
class WarehouseBranchIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_warehouse_staff_cannot_mutate_another_branch_inventory_via_logs(): void
    {
        [$store, $warehouse, $book] = $this->seedStoreAndWarehouse();
        $this->actingAsRole('warehouse_staff', $warehouse);

        $before = (int) Inventory::where('branch_id', $store->id)->where('book_id', $book->id)->value('quantity');

        $this->postJson('/api/warehouse/logs', $this->logPayload($store->id, $book->id, [
            'quantity' => 777,
        ]))->assertForbidden();

        $after = (int) Inventory::where('branch_id', $store->id)->where('book_id', $book->id)->value('quantity');
        $this->assertSame($before, $after);
        $this->assertSame(0, WarehouseLog::where('branch_id', $store->id)->where('quantity', 777)->count());
    }

    public function test_warehouse_staff_can_mutate_own_warehouse_branch(): void
    {
        [$store, $warehouse, $book] = $this->seedStoreAndWarehouse();
        $this->makeInventory($warehouse, $book, ['quantity' => 10]);
        $this->actingAsRole('warehouse_staff', $warehouse);

        $this->postJson('/api/warehouse/logs', $this->logPayload($warehouse->id, $book->id, [
            'quantity' => 2,
        ]))->assertCreated();

        $this->assertSame(1, WarehouseLog::where('branch_id', $warehouse->id)->where('quantity', 2)->count());
        $this->assertSame(0, WarehouseLog::where('branch_id', $store->id)->count());
    }

    public function test_branch_manager_cannot_read_or_edit_another_branch_warehouse_logs(): void
    {
        [$store, $warehouse, $book] = $this->seedStoreAndWarehouse();
        $this->actingAsRole('admin', $store);
        $created = $this->postJson('/api/warehouse/logs', $this->logPayload($store->id, $book->id))->assertCreated();
        $logId = (int) $created->json('id');
        $beforeQty = (int) Inventory::where('branch_id', $store->id)->where('book_id', $book->id)->value('quantity');

        $other = $this->makeBranch(['name' => 'مشهد', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $other);

        $this->getJson('/api/warehouse/logs?branch_id='.$store->id)->assertForbidden();
        $this->getJson('/api/warehouse/logs/'.$logId)->assertForbidden();
        $this->getJson('/api/warehouse/'.$store->id.'/stats')->assertForbidden();
        $this->putJson('/api/warehouse/logs/'.$logId, ['quantity' => 1])->assertForbidden();

        $listed = $this->getJson('/api/warehouse/logs')->assertOk()->json('data');
        $ids = collect($listed)->pluck('id')->all();
        $this->assertNotContains($logId, $ids);

        $afterQty = (int) Inventory::where('branch_id', $store->id)->where('book_id', $book->id)->value('quantity');
        $this->assertSame($beforeQty, $afterQty);
    }

    public function test_accountant_cannot_mutate_warehouse_stock(): void
    {
        [$store, $warehouse, $book] = $this->seedStoreAndWarehouse();
        $this->actingAsRole('accountant', $store);

        $this->postJson('/api/warehouse/logs', $this->logPayload($store->id, $book->id))->assertForbidden();
    }

    public function test_warehouse_staff_cannot_create_customers(): void
    {
        $warehouse = $this->makeBranch(['name' => 'انبار', 'type' => 'warehouse', 'is_central_warehouse' => true]);
        $this->actingAsRole('warehouse_staff', $warehouse);

        $this->postJson('/api/customers', [
            'name' => 'QA-TEST-CUSTOMER',
            'branch_id' => $warehouse->id,
        ])->assertForbidden();
    }

    public function test_expense_rejects_far_future_date_with_validation_error(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);

        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'amount' => 1,
            'currency' => 'toman',
            'category' => 'other',
            'date' => '2099-01-01',
        ])->assertStatus(422);
    }

    /**
     * @return array{0: \App\Models\Branch, 1: \App\Models\Branch, 2: \App\Models\Book}
     */
    private function seedStoreAndWarehouse(): array
    {
        $store = $this->makeBranch(['name' => 'قم', 'city' => 'قم', 'is_intake_hub' => true]);
        $warehouse = $this->makeBranch([
            'name' => 'انبار مرکزی',
            'type' => 'warehouse',
            'is_central_warehouse' => true,
        ]);
        $book = $this->makeBook();
        $this->makeInventory($store, $book, ['quantity' => 60]);

        return [$store, $warehouse, $book];
    }

    private function logPayload(int $branchId, int $bookId, array $over = []): array
    {
        return array_merge([
            'branch_id' => $branchId,
            'book_id' => $bookId,
            'direction' => 'in',
            'quantity' => 3,
            'reason' => 'adjustment',
            'handler_name' => 'QA',
            'log_date' => now()->toDateString(),
        ], $over);
    }
}
