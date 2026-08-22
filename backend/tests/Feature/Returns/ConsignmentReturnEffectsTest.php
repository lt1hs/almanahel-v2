<?php

namespace Tests\Feature\Returns;

use App\Models\ConsignmentReceipt;
use App\Services\Suppliers\SupplierAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group returns
 * @group finance
 * @group suppliers
 */
class ConsignmentReturnEffectsTest extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    public function test_customer_return_before_settlement_reduces_open_payable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 15000]],
        ])->assertCreated()->json();

        $before = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame(20000.0, (float) $before['remaining_payable']);

        $itemId = $invoice['items'][0]['id'];
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $after = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame(10000.0, (float) $after['remaining_payable']);
        $this->assertSame(1, (int) $after['customer_returned_quantity']);
    }

    public function test_customer_return_after_full_settlement_creates_supplier_recoverable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 15000]],
        ])->assertCreated()->json();

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '20000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $invoice['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();

        $row = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame(0.0, (float) $row['remaining_payable']);
        $this->assertSame(10000.0, (float) $row['supplier_recoverable']);
    }

    public function test_supplier_return_of_unsold_stock_reduces_inventory_not_sale_payable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 15000]],
        ])->assertCreated();

        $eligible = $this->getJson('/api/returns/consignment/eligible?'.http_build_query([
            'branch_id' => $branch->id,
            'supplier_account_id' => $account->id,
        ]))->assertOk()->json('data');
        $this->assertNotEmpty($eligible);
        $lot = $eligible[0];
        $this->assertSame(3, (int) $lot['returnable_quantity']);

        $before = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $payableBefore = (float) $before['remaining_payable'];

        $this->postJson('/api/returns/consignment', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'idempotency_key' => 'crr-1',
            'items' => [['stock_lot_id' => $lot['stock_lot_id'], 'quantity' => 2]],
        ])->assertCreated();

        // Idempotent replay
        $this->postJson('/api/returns/consignment', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'idempotency_key' => 'crr-1',
            'items' => [['stock_lot_id' => $lot['stock_lot_id'], 'quantity' => 2]],
        ])->assertOk();

        $after = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame($payableBefore, (float) $after['remaining_payable']);
        $this->assertSame(2, (int) $after['returned_to_supplier_quantity']);
        $this->assertSame(1, (int) $after['remaining_unsold_quantity']);

        $this->postJson('/api/returns/consignment', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'items' => [['stock_lot_id' => $lot['stock_lot_id'], 'quantity' => 99]],
        ])->assertStatus(422);
    }
}
