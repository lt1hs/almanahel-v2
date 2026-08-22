<?php

namespace Tests\Feature\Characterization;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * Snapshots of current (unsafe/legacy) finance API fields before T9 wiring.
 *
 * @group characterization
 */
class FinanceBaselineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_dashboard_exposes_sell_side_inventory_value_keys(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'quantity' => 2,
            'price_toman' => 100000,
            'price_dinar' => 0,
        ]);

        $response = $this->getJson('/api/reports/dashboard');
        $response->assertOk();
        $response->assertJsonStructure([
            'today_sales_toman',
            'today_sales_dinar',
            'inventory_value_toman',
            'inventory_value_dinar',
        ]);
        $this->assertEquals(140000, (float) $response->json('inventory_value_toman'));
    }

    public function test_all_branches_payload_keys_include_pending_credit_sum(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $response = $this->getJson('/api/reports/all-branches');
        $response->assertOk();
        $row = $response->json('0');
        $this->assertIsArray($row);
        foreach ([
            'revenue_toman', 'revenue_dinar',
            'cogs_toman', 'cogs_dinar',
            'expenses_toman', 'expenses_dinar',
            'gift_costs_toman', 'gift_costs_dinar',
            'net_profit_toman', 'net_profit_dinar',
            'pending_credit_toman', 'pending_credit_dinar',
        ] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertArrayNotHasKey('pending_credit', $row);
    }

    public function test_iraq_profit_includes_origin_objects_and_legacy_aliases(): void
    {
        $iraq = $this->makeBranch([
            'name' => 'نجف',
            'city' => 'نجف',
            'country' => 'عراق',
            'type' => 'store',
            'is_iraq_store' => true,
        ]);
        $this->actingAsRole('admin', $iraq);

        $response = $this->getJson('/api/reports/iraq-profit');
        $response->assertOk();
        $response->assertJsonStructure([
            'iraq_only_revenue',
            'distributed_revenue',
            'total_iraq_revenue',
            'revenue',
            'cogs',
            'expenses',
            'net_profit',
            'iraq_local' => ['revenue', 'cogs', 'net_profit'],
            'qom_distributed' => ['revenue', 'cogs', 'net_profit'],
            'combined',
            'period',
        ]);
    }

    public function test_top_books_payload_has_no_currency_field(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $response = $this->getJson('/api/reports/top-books');
        $response->assertOk();
        $this->assertTrue(collect($response->json())->every(fn ($row) => isset($row['currency'])));
    }

    public function test_settlement_preview_items_use_open_qty_not_title(): void
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

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $supplier->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $branch->id,
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->assertArrayHasKey('items', $preview);
        $this->assertArrayHasKey('total_payable', $preview);
        $this->assertArrayHasKey('commission_rate', $preview);
        $this->assertEquals(200000, (float) $preview['total_payable']);
        $this->assertNotEmpty($preview['items']);
        $line = $preview['items'][0];
        $this->assertArrayHasKey('kind', $line);
        $this->assertArrayHasKey('open_qty', $line);
        $this->assertArrayHasKey('open_amount', $line);
        $this->assertArrayHasKey('unit_cost', $line);
        $this->assertArrayNotHasKey('title', $line);
        $this->assertArrayNotHasKey('qty_sold', $line);
        $this->assertArrayNotHasKey('publisher_share', $line);
    }

    /**
     * LEGACY UNSAFE: PUT /credits marks the invoice paid with no payment allocation.
     * Phase 3 must replace this characterization with a contract that requires an actual payment.
     */
    public function test_credit_status_endpoint_rejects_paid_without_payment_allocation(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000]);
        $customer = \App\Models\Customer::create(['name' => 'مشتری آزمایشی', 'branch_id' => $branch->id]);

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری آزمایشی',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $id = $sale->json('id');
        $this->putJson("/api/credits/{$id}", ['payment_status' => 'paid'])->assertStatus(409);
        $this->assertSame('pending', Invoice::find($id)?->payment_status);
        $this->assertSame(0, CustomerPayment::count());
    }
}
