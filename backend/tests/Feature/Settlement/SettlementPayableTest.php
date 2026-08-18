<?php

namespace Tests\Feature\Settlement;

use App\Models\ConsignmentReceiptItem;
use App\Models\SettlementAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group settlement */
class SettlementPayableTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_settle_rejects_amount_above_payable_and_records_allocations(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 10,
                'cost_price' => 100000,
                'selling_price' => 150000,
            ]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 150000]],
        ])->assertCreated();

        $this->assertEquals(2, ConsignmentReceiptItem::first()->quantity_sold);

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $supplier->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $branch->id,
            'currency' => 'toman',
        ]))->assertOk()->json();

        // 2 * 100000 * 0.9 = 180000
        $this->assertEquals(180000, (float) $preview['total_payable']);

        $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 200000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $settle = $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 180000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertEquals(1, SettlementAllocation::count());
        $this->assertEquals(180000, (float) $settle->json('amount'));

        $balance = $this->getJson('/api/suppliers/' . $supplier->id . '/balance')->assertOk()->json();
        $this->assertTrue(collect($balance['unsettled_balances'])->every(fn ($b) => (float) $b['balance'] === 0.0)
            || empty($balance['unsettled_balances']));
    }
}
