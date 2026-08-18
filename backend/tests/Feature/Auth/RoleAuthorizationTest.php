<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group auth */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_branch_manager_cannot_list_users(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);

        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $this->actingAsRole('admin');
        $this->getJson('/api/users')->assertOk();
    }

    public function test_branch_manager_cannot_update_settings(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);

        $this->putJson('/api/settings', ['low_stock_threshold' => 3])->assertForbidden();
    }

    public function test_branch_manager_cannot_view_all_branch_reports(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);

        $this->getJson('/api/reports/all-branches')->assertForbidden();
    }

    public function test_accountant_can_view_all_branch_reports(): void
    {
        $this->actingAsRole('accountant');
        $this->getJson('/api/reports/all-branches')->assertOk();
    }

    public function test_iraq_only_book_hidden_from_non_iraq_user(): void
    {
        $qom = $this->makeBranch(['city' => 'قم', 'country' => 'ایران']);
        $this->actingAsRole('branch_manager', $qom);
        $book = $this->makeBook(['iraq_only' => true, 'title' => 'کتاب عراق']);

        $this->getJson('/api/books/' . $book->id)->assertForbidden();
        $this->getJson('/api/books')->assertOk()
            ->assertJsonMissing(['id' => $book->id]);
    }

    public function test_branch_manager_cannot_spoof_other_branch_on_invoice(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 5, 'price_toman' => 100000]);
        $this->makeInventory($b, $book, ['quantity' => 5, 'price_toman' => 100000]);

        $this->postJson('/api/invoices', [
            'branch_id' => $b->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 100000,
            ]],
        ])->assertForbidden();
    }

    public function test_pos_user_only_sees_own_branch_low_stock_alerts(): void
    {
        $mashhad = $this->makeBranch(['name' => 'دارالمناهل مشهد', 'city' => 'مشهد']);
        $iraq = $this->makeBranch(['name' => 'دارالمناهل عراق', 'city' => 'نجف', 'country' => 'عراق']);
        $warehouse = $this->makeBranch(['name' => 'انبار مرکزی', 'type' => 'warehouse']);
        $bookIraq = $this->makeBook(['title' => 'تست ۱']);
        $bookWh = $this->makeBook(['title' => 'test']);
        $bookMh = $this->makeBook(['title' => 'مشهدی']);
        $this->makeInventory($iraq, $bookIraq, ['quantity' => 4]);
        $this->makeInventory($warehouse, $bookWh, ['quantity' => 4]);
        $this->makeInventory($mashhad, $bookMh, ['quantity' => 4]);

        $this->actingAsRole('branch_manager', $mashhad);
        $pos = $this->getJson('/api/reports/notifications')->assertOk()->json('low_stock');
        $names = collect($pos)->pluck('data.branch_name')->all();
        $this->assertContains('دارالمناهل مشهد', $names);
        $this->assertNotContains('دارالمناهل عراق', $names);
        $this->assertNotContains('انبار مرکزی', $names);

        $this->actingAsRole('admin');
        $admin = $this->getJson('/api/reports/notifications')->assertOk()->json('low_stock');
        $adminNames = collect($admin)->pluck('data.branch_name')->all();
        $this->assertContains('دارالمناهل عراق', $adminNames);
        $this->assertContains('انبار مرکزی', $adminNames);
        $this->assertContains('دارالمناهل مشهد', $adminNames);
    }

    public function test_duplicate_invoice_lines_are_aggregated(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000]);

        $response = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [
                ['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000],
                ['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000],
            ],
        ]);

        $response->assertCreated();
        $this->assertCount(1, $response->json('items'));
        $this->assertEquals(3, $response->json('items.0.quantity'));
        $this->assertEquals(2, $book->fresh()->inventories()->where('branch_id', $branch->id)->value('quantity'));
    }

    public function test_forged_customer_return_without_invoice_item_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $user = $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000]);

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $invoiceId = $sale->json('id');
        $itemId = $sale->json('items.0.id');

        $other = \App\Models\Invoice::create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'invoice_number' => 'INV-OTHER',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 50000,
            'discount_amount' => 0,
            'total' => 50000,
            'type' => 'sale',
        ]);
        $otherItem = \App\Models\InvoiceItem::create([
            'invoice_id' => $other->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'unit_price' => 50000,
            'actual_price' => 50000,
            'discount' => 0,
            'list_price' => 50000,
        ]);

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoiceId,
            'refund_method' => 'cash',
            'items' => [[
                'invoice_item_id' => $otherItem->id,
                'quantity' => 1,
            ]],
        ])->assertStatus(422);

        $ok = $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoiceId,
            'refund_method' => 'cash',
            'items' => [[
                'invoice_item_id' => $itemId,
                'quantity' => 1,
            ]],
        ]);
        $ok->assertCreated();
        $this->assertEquals(100000, (float) $ok->json('refund_amount'));
    }

    public function test_invoice_uses_inventory_list_price_not_client_unit_price(): void
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
                'unit_price' => 1,
                'actual_price' => 100000,
            ]],
        ]);

        $response->assertCreated();
        $this->assertEquals(100000, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(100000, (float) $response->json('items.0.list_price'));
    }

    public function test_pos_can_intake_only_at_own_branch(): void
    {
        $mashhad = $this->makeBranch(['name' => 'دارالمناهل مشهد', 'city' => 'مشهد']);
        $warehouse = $this->makeBranch(['name' => 'انبار مرکزی', 'type' => 'warehouse', 'is_central_warehouse' => true]);
        $book = $this->makeBook();

        $this->actingAsRole('branch_manager', $mashhad);
        $this->getJson('/api/inventory/intake-info')
            ->assertOk()
            ->assertJsonPath('can_intake', true)
            ->assertJsonPath('default_intake_branch_id', $mashhad->id);

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $mashhad->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 10000,
            'selling_price' => 20000,
        ])->assertCreated();

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $warehouse->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 10000,
            'selling_price' => 20000,
        ])->assertForbidden();
    }
}
