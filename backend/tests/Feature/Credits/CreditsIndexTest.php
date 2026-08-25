<?php

namespace Tests\Feature\Credits;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group credits */
class CreditsIndexTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_index_returns_credit_rows_without_line_items_and_syncs_overdue(): void
    {
        $branch = $this->makeBranch();
        $user = $this->actingAsRole('admin', $branch);

        Invoice::create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'invoice_number' => 'INV-CR-OVERDUE',
            'payment_method' => 'credit',
            'payment_status' => 'pending',
            'currency' => 'toman',
            'subtotal' => 4000000,
            'discount_amount' => 0,
            'total' => 4000000,
            'type' => 'sale',
            'customer_name' => 'test12',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        Invoice::create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'invoice_number' => 'INV-CR-SOON',
            'payment_method' => 'credit',
            'payment_status' => 'pending',
            'currency' => 'toman',
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total' => 100000,
            'type' => 'sale',
            'customer_name' => 'soon',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $payload = $this->getJson('/api/credits')->assertOk()->json();

        $this->assertCount(2, $payload['credits']);
        $this->assertArrayNotHasKey('items', $payload['credits'][0]);
        $this->assertArrayNotHasKey('user', $payload['credits'][0]);
        $this->assertSame('test12', collect($payload['credits'])->firstWhere('invoice_number', 'INV-CR-OVERDUE')['customer_name']);
        $this->assertSame('overdue', collect($payload['credits'])->firstWhere('invoice_number', 'INV-CR-OVERDUE')['payment_status']);
        $this->assertCount(1, $payload['due_soon']);
        $this->assertSame('INV-CR-SOON', $payload['due_soon'][0]['invoice_number']);
        $this->assertArrayHasKey('outstanding', $payload['credits'][0]);
    }

    public function test_collecting_credit_requires_customer_payment_then_marks_paid(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000]);
        $customer = $this->makeCustomer($branch, ['name' => 'test12', 'phone' => '09120000001']);

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => 'test12',
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $id = (int) $sale->json('id');
        $this->putJson("/api/credits/{$id}", ['payment_status' => 'paid'])->assertStatus(409);

        $this->postJson("/api/customers/{$customer->id}/payments", [
            'invoice_id' => $id,
            'amount' => 100000,
            'currency' => 'toman',
            'method' => 'cash',
            'idempotency_key' => "credits-pay-{$id}",
        ])->assertCreated();

        $this->assertSame('paid', Invoice::find($id)?->payment_status);
        $row = collect($this->getJson('/api/credits')->assertOk()->json('credits'))->firstWhere('id', $id);
        $this->assertSame('0.00', $row['outstanding']);
    }
}
