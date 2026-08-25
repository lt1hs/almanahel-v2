<?php

namespace Tests\Feature\Customers;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group customers */
class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_index_includes_invoice_count(): void
    {
        $branch = $this->makeBranch();
        $user = $this->actingAsRole('admin', $branch);

        $withInvoices = $this->makeCustomer($branch, ['phone' => '09121111111']);
        $without = $this->makeCustomer($branch, ['phone' => '09122222222']);

        Invoice::create([
            'branch_id' => $branch->id,
            'customer_id' => $withInvoices->id,
            'user_id' => $user->id,
            'invoice_number' => 'INV-DIR-1',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total' => 100000,
            'type' => 'sale',
        ]);
        Invoice::create([
            'branch_id' => $branch->id,
            'customer_id' => $withInvoices->id,
            'user_id' => $user->id,
            'invoice_number' => 'INV-DIR-2',
            'payment_method' => 'credit',
            'payment_status' => 'pending',
            'currency' => 'toman',
            'subtotal' => 50000,
            'discount_amount' => 0,
            'total' => 50000,
            'type' => 'sale',
        ]);

        $rows = collect($this->getJson('/api/customers')->assertOk()->json('data'));

        $this->assertSame(2, (int) $rows->firstWhere('id', $withInvoices->id)['invoices_count']);
        $this->assertSame(0, (int) $rows->firstWhere('id', $without->id)['invoices_count']);
    }
}
