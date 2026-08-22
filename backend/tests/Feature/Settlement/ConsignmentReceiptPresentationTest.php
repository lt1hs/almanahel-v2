<?php

namespace Tests\Feature\Settlement;

use App\Models\ConsignmentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class ConsignmentReceiptPresentationTest extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    public function test_receiving_stock_is_inventory_value_but_not_supplier_payable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        $receiptId = $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 50,
                'cost_price' => 500000,
                'selling_price' => 900000,
            ]],
        ])->assertCreated()->json('id');

        $index = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk();
        $index->assertJsonPath('data.0.id', $receiptId)
            ->assertJsonPath('data.0.payable_status', 'not_due')
            ->assertJsonPath('data.0.inventory_value', '25000000.00')
            ->assertJsonPath('data.0.payable_generated', '0.00')
            ->assertJsonPath('data.0.payable_settled', '0.00')
            ->assertJsonPath('data.0.payable_outstanding', '0.00')
            ->assertJsonPath('data.0.quantity_received', 50)
            ->assertJsonPath('data.0.quantity_in_stock', 50)
            ->assertJsonPath('summary.receipts_count', 1)
            ->assertJsonPath('summary.not_due_count', 1)
            ->assertJsonPath('summary.currencies.toman.inventory_value', '25000000.00')
            ->assertJsonPath('summary.currencies.toman.payable_outstanding', '0.00')
            ->assertJsonPath('summary.currencies.dinar.payable_outstanding', '0.00');

        $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=unsettled')
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=not_due')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_sale_creates_full_cost_payable_without_changing_receipt_inventory_value(): void
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
                'quantity' => 50,
                'cost_price' => 500000,
                'selling_price' => 900000,
            ]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 900000,
            ]],
        ])->assertCreated();

        $receipt = ConsignmentReceipt::firstOrFail();
        $detail = $this->getJson('/api/consignments/'.$receipt->id)->assertOk();
        $detail->assertJsonPath('payable_status', 'unsettled')
            ->assertJsonPath('inventory_value', '25000000.00')
            ->assertJsonPath('payable_generated', '500000.00')
            ->assertJsonPath('payable_settled', '0.00')
            ->assertJsonPath('payable_outstanding', '500000.00')
            ->assertJsonPath('quantity_sold', 1)
            ->assertJsonPath('quantity_in_stock', 49);
    }
}
