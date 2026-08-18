<?php

namespace Tests\Feature\Characterization;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * Updated after Phase 1 intentional contract hardening.
 *
 * @group characterization
 */
class CurrentContractsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_invoice_create_uses_server_list_price(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000]);

        $response = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'unit_price' => 999,
                'actual_price' => 100000,
                'discount' => 0,
            ]],
        ]);

        $response->assertCreated();
        $this->assertEquals(100000, (float) $response->json('total'));
        $this->assertEquals(100000, (float) $response->json('items.0.list_price'));
    }

    public function test_iraq_profit_payload_keys(): void
    {
        $iraq = $this->makeBranch([
            'name' => 'نجف',
            'city' => 'نجف',
            'country' => 'عراق',
            'type' => 'store',
        ]);
        $this->actingAsRole('admin', $iraq);

        $response = $this->getJson('/api/reports/iraq-profit');
        $response->assertOk();
        $response->assertJsonStructure([
            'iraq_only_revenue',
            'distributed_revenue',
            'total_iraq_revenue',
            'revenue',
            'expenses',
            'net_profit',
            'sales_count',
            'period',
        ]);
    }

    public function test_customer_return_requires_invoice_item_and_server_refund(): void
    {
        $branch = $this->makeBranch();
        $user = $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 70000,
            'selling_price' => 100000,
        ])->assertCreated();

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 100000,
            ]],
        ])->assertCreated();

        $invoiceId = $sale->json('id');
        $itemId = $sale->json('items.0.id');

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoiceId,
            'refund_method' => 'cash',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'unit_price' => 1,
            ]],
        ])->assertStatus(422);

        $response = $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoiceId,
            'refund_method' => 'cash',
            'items' => [[
                'invoice_item_id' => $itemId,
                'quantity' => 1,
            ]],
        ]);

        $response->assertCreated();
        $this->assertEquals(100000, (float) $response->json('refund_amount'));
    }

    public function test_settlement_preview_includes_commission_rate_key(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'type' => 'consignment',
            'supplier_id' => $supplier->id,
            'quantity' => 10,
            'cost_price_toman' => 70000,
        ]);
        $this->makeConsignmentReceipt($branch, $supplier, $book);

        $response = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $supplier->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $branch->id,
        ]));

        $response->assertOk();
        $this->assertArrayHasKey('commission_rate', $response->json());
    }
}
