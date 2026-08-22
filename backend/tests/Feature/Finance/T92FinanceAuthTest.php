<?php

namespace Tests\Feature\Finance;

use App\Models\Check;
use App\Models\Gift;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t9
 * @group t92
 * @group finance
 */
class T92FinanceAuthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_warehouse_staff_is_forbidden_on_finance_mutations(): void
    {
        $branch = $this->makeBranch(['is_central_warehouse' => true]);
        $admin = $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 8, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = $this->makeCustomer($branch);
        $credit = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $checkSale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'W-1',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $gift = $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'کتابخانه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated();
        $supplier = $this->makeSupplier();

        $this->actingAsRole('warehouse_staff', $branch);

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertForbidden();

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $credit->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => Invoice::find($credit->json('id'))->items()->first()->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->postJson('/api/returns/consignment', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000]],
        ])->assertForbidden();

        $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'هدیه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->putJson('/api/gifts/'.$gift->json('id').'/status', [
            'accounting_status' => 'settled',
        ])->assertForbidden();

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 10000,
            'selling_price' => 20000,
        ])->assertForbidden();

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'invoice_id' => $credit->json('id'),
            'amount' => 10000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertForbidden();

        $this->putJson('/api/checks/'.Check::first()->id, ['status' => 'cleared'])->assertForbidden();

        $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 10000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertForbidden();

        $this->assertSame($admin->id, Invoice::find($credit->json('id'))->user_id);
        $this->assertSame('pending', Invoice::find($checkSale->json('id'))->payment_status);
        $this->assertSame('pending', Gift::find($gift->json('id'))->accounting_status);
    }

    public function test_iraq_visibility_does_not_grant_cross_branch_mutation(): void
    {
        $home = $this->makeBranch(['name' => 'مشهد', 'city' => 'مشهد']);
        $iraq = $this->makeBranch(['name' => 'نجف', 'city' => 'نجف', 'country' => 'عراق']);
        $this->actingAsRole('admin', $home);
        $book = $this->makeBook();
        $this->makeInventory($iraq, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->makeInventory($home, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $this->actingAsRole('branch_manager', $home, ['iraq_only_visible_branches' => [$iraq->id]]);
        $this->getJson('/api/invoices?branch_id='.$iraq->id)->assertOk();
        $this->postJson('/api/invoices', [
            'branch_id' => $iraq->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertForbidden();
        $this->postJson('/api/gifts', [
            'branch_id' => $iraq->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'هدیه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_branch_manager_can_mutate_own_branch_and_accountant_can_cross_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->makeInventory($b, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $this->actingAsRole('branch_manager', $a);
        $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $b->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertForbidden();

        $this->actingAsRole('accountant', $a);
        $this->postJson('/api/invoices', [
            'branch_id' => $b->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
    }
}
