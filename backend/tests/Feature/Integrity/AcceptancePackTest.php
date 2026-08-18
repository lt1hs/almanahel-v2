<?php

namespace Tests\Feature\Integrity;

use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\GiftLotAllocation;
use App\Models\StockLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group integrity */
class AcceptancePackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_customer_partial_payment_and_idempotency(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000]);
        $customer = Customer::create(['name' => 'علی', 'phone' => '0912', 'branch_id' => $branch->id]);

        $invoice = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => 'علی',
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000]],
        ])->assertCreated();

        $pay = [
            'amount' => 50000,
            'currency' => 'toman',
            'method' => 'cash',
            'invoice_id' => $invoice->json('id'),
            'idempotency_key' => 'pay-1',
        ];
        $this->postJson('/api/customers/'.$customer->id.'/payments', $pay)->assertCreated();
        $this->postJson('/api/customers/'.$customer->id.'/payments', $pay)->assertOk();
        $this->assertEquals(1, $customer->payments()->count());

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'amount' => 200000,
            'currency' => 'toman',
            'method' => 'cash',
            'invoice_id' => $invoice->json('id'),
        ])->assertStatus(422);
    }

    public function test_gift_splits_across_owned_and_consignment_lots(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 40000,
            'selling_price' => 90000,
        ])->assertCreated();
        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'cost_price' => 50000, 'selling_price' => 90000]],
        ])->assertCreated();

        $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'recipient_name' => 'کتابخانه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated();

        $this->assertEquals(2, GiftLotAllocation::count());
        $this->assertEquals(1, (int) StockLot::where('book_id', $book->id)->sum('qty_available'));
    }

    public function test_notification_branch_privacy_and_zero_stock(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 0]);
        AppNotification::create([
            'dedupe_key' => 'x-b',
            'type' => 'low_stock',
            'title' => 'secret',
            'branch_id' => $b->id,
        ]);
        $this->artisan('alerts:generate')->assertSuccessful();
        $this->actingAsRole('branch_manager', $a);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $unread = $this->getJson('/api/notifications/unread-count')->assertOk()->json('unread');
        $this->assertSame(0, (int) $unread);

        $list = $this->getJson('/api/notifications')->assertOk()->json();
        $ids = collect($list['data'] ?? $list)->pluck('branch_id')->filter()->all();
        $this->assertNotContains($b->id, $ids);
    }

    public function test_read_and_dismiss_are_per_user(): void
    {
        $branch = $this->makeBranch(['name' => 'مشهد', 'city' => 'مشهد']);
        $book = $this->makeBook(['title' => 'test']);
        $this->makeInventory($branch, $book, ['quantity' => 1]);
        $note = AppNotification::create([
            'dedupe_key' => 'shared-low',
            'type' => 'low_stock',
            'title' => 'موجودی کم',
            'body' => 'test',
            'branch_id' => $branch->id,
        ]);

        $this->actingAsRole('admin');
        $this->postJson('/api/notifications/'.$note->id.'/read')->assertOk();
        $adminRow = collect($this->getJson('/api/notifications')->json('data'))->firstWhere('id', $note->id);
        $this->assertNotNull($adminRow['read_at'] ?? null);

        $this->actingAsRole('branch_manager', $branch);
        $posRow = collect($this->getJson('/api/notifications')->json('data'))->firstWhere('id', $note->id);
        $this->assertNotNull($posRow);
        $this->assertNull($posRow['read_at'] ?? null);

        $this->postJson('/api/notifications/'.$note->id.'/dismiss')->assertOk();
        $hidden = collect($this->getJson('/api/notifications')->json('data'))->pluck('id');
        $this->assertFalse($hidden->contains($note->id));
        $history = collect($this->getJson('/api/notifications?history=1')->json('data'))->pluck('id');
        $this->assertTrue($history->contains($note->id));
    }

    public function test_arbitrary_new_branch_intake_transfer_sale(): void
    {
        $this->actingAsRole('admin');
        $hub = $this->postJson('/api/branches', [
            'name' => 'Erbil Hub',
            'city' => 'Erbil',
            'country' => 'Iraq',
            'type' => 'store',
            'is_intake_hub' => true,
            'can_receive_inventory' => true,
            'can_sell' => true,
            'can_transfer' => true,
            'supports_toman' => true,
        ])->assertCreated()->json();
        $store = $this->postJson('/api/branches', [
            'name' => 'Sulaymaniyah Shop',
            'city' => 'Sulaymaniyah',
            'country' => 'Iraq',
            'type' => 'store',
            'can_sell' => true,
            'can_transfer' => true,
            'supports_toman' => true,
        ])->assertCreated()->json();
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $hub['id'],
            'book_id' => $book->id,
            'quantity' => 3,
            'currency' => 'toman',
            'cost_price' => 10000,
            'selling_price' => 20000,
        ])->assertCreated();
        $transfer = $this->postJson('/api/transfers', [
            'from_branch_id' => $hub['id'],
            'to_branch_id' => $store['id'],
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
        ])->assertCreated()->json();
        $this->putJson('/api/transfers/'.$transfer['id'].'/status', ['status' => 'received'])->assertOk();
        $this->postJson('/api/invoices', [
            'branch_id' => $store['id'],
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 20000]],
        ])->assertCreated();
        $this->getJson('/api/reports/all-branches')->assertOk();
    }

    public function test_warehouse_staff_cannot_access_expenses(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('warehouse_staff', $branch);
        $this->getJson('/api/expenses')->assertForbidden();
    }
}
