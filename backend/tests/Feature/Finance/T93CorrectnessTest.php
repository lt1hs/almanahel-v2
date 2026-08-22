<?php

namespace Tests\Feature\Finance;

use App\Exceptions\DomainException;
use App\Models\Check;
use App\Models\CustomerReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockLot;
use App\Services\Ledger\FinancialPostingService;
use App\Services\Ledger\SystemAccounts;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t9
 * @group t93
 * @group finance
 * @group ledger
 */
class T93CorrectnessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_partial_and_full_credit_return_refresh_invoice_status(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->assertSame('pending', $sale->json('payment_status'));

        $partial = $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame('partially_paid', $partial->json('invoice.payment_status'));
        $this->assertSame('partially_paid', Invoice::find($sale->json('id'))->payment_status);

        $full = $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame('paid', $full->json('invoice.payment_status'));
        $this->assertSame('paid', Invoice::find($sale->json('id'))->payment_status);
    }

    public function test_bounced_check_partial_return_sets_receivable_status(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 4, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'B-93',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->putJson('/api/checks/'.Check::first()->id, ['status' => 'bounced'])->assertOk();
        $this->assertSame('pending', Invoice::find($sale->json('id'))->payment_status);

        $ret = $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame('partially_paid', $ret->json('invoice.payment_status'));
    }

    public function test_return_posting_failure_leaves_payment_status_unchanged(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->assertSame('pending', Invoice::find($sale->json('id'))->payment_status);

        $this->app->forgetInstance(\App\Services\Ledger\FinancePostingGateway::class);
        $this->mock(FinancialPostingService::class, function ($mock) {
            $mock->shouldReceive('postCustomerReturn')->andThrow(new DomainException('fail'));
        });
        $qtyBefore = (int) StockLot::sum('qty_available');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame('pending', Invoice::find($sale->json('id'))->payment_status);
        $this->assertSame(0, CustomerReturn::count());
        $this->assertSame($qtyBefore, (int) StockLot::sum('qty_available'));
    }

    public function test_anonymous_cash_credit_refund_is_rejected_without_mutations(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $qtyBefore = (int) StockLot::sum('qty_available');

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, CustomerReturn::count());
        $this->assertSame(0, JournalEntry::where('event_type', 'customer_return')->count());
        $this->assertSame($qtyBefore, (int) StockLot::sum('qty_available'));
        $this->assertSame(1, (int) $book->fresh()->inventories()->where('branch_id', $branch->id)->value('quantity'));
    }

    public function test_linked_cash_credit_refund_posts_liability_with_customer_id(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();

        $liabilityId = SystemAccounts::get('customer_credit_liability', 'toman')->id;
        $line = JournalLine::query()
            ->where('ledger_account_id', $liabilityId)
            ->where('credit', '100000.00')
            ->first();
        $this->assertNotNull($line);
        $this->assertSame($customer->id, (int) $line->customer_id);
        $this->assertSame(0, JournalLine::query()
            ->where('ledger_account_id', $liabilityId)
            ->whereNull('customer_id')
            ->count());
    }

    public function test_toman_and_dinar_customer_credits_stay_separate(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'supports_dinar' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'quantity' => 2,
            'price_toman' => 100000,
            'price_dinar' => 2000,
            'cost_price_toman' => 70000,
        ]);
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'dinar',
            'cost_price' => 1400,
            'selling_price' => 2000,
        ])->assertCreated();
        $customer = $this->makeCustomer($branch);
        $toman = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $dinar = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'currency' => 'dinar',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 2000]],
        ])->assertCreated();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $toman->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $toman->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $dinar->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $dinar->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();

        $balances = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('balances');
        $this->assertSame('100000.00', Money::of($balances['toman']['customer_credit_outstanding']));
        $this->assertSame('2000.00', Money::of($balances['dinar']['customer_credit_outstanding']));
        $this->assertFalse($balances['toman']['customer_credit_redeemable']);
        $this->assertTrue($balances['toman']['customer_credit_is_outstanding_liability']);
        $this->assertNotSame(
            Money::add($balances['toman']['customer_credit'], $balances['dinar']['customer_credit']),
            $balances['toman']['customer_credit']
        );
    }

    public function test_ten_percent_discount_is_proportional_and_return_uses_net(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $bookA = $this->makeBook(['title' => 'A']);
        $bookB = $this->makeBook(['title' => 'B']);
        $this->makeInventory($branch, $bookA, ['quantity' => 5, 'price_toman' => 100, 'cost_price_toman' => 40]);
        $this->makeInventory($branch, $bookB, ['quantity' => 5, 'price_toman' => 900, 'cost_price_toman' => 400]);

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [
                ['book_id' => $bookA->id, 'quantity' => 1, 'actual_price' => 100, 'discount' => Money::percentOf(100, 10)],
                ['book_id' => $bookB->id, 'quantity' => 1, 'actual_price' => 900, 'discount' => Money::percentOf(900, 10)],
            ],
        ])->assertCreated();

        $items = collect($sale->json('items'));
        $lineA = $items->firstWhere('book_id', $bookA->id);
        $lineB = $items->firstWhere('book_id', $bookB->id);
        $this->assertSame('10.00', Money::of($lineA['discount']));
        $this->assertSame('90.00', Money::of($lineB['discount']));
        $this->assertSame('90.00', Money::sub($lineA['actual_price'], $lineA['discount']));
        $this->assertSame('810.00', Money::sub($lineB['actual_price'], $lineB['discount']));
        $this->assertSame('900.00', Money::of($sale->json('total')));

        $retA = $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $lineA['id'], 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame('90.00', Money::of($retA->json('refund_amount')));

        $retB = $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $lineB['id'], 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame('810.00', Money::of($retB->json('refund_amount')));
    }

    public function test_quantity_and_above_list_use_actual_unit_price_percent(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100, 'cost_price_toman' => 40]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 3,
                'actual_price' => 120,
                'discount' => Money::percentOf(120, 10),
                'override_reason' => 'above-list',
            ]],
        ])->assertCreated();
        $this->assertSame('12.00', Money::of($sale->json('items.0.discount')));
        $this->assertSame('324.00', Money::of($sale->json('total')));
    }

    public function test_duplicate_book_lines_keep_distinct_prices_and_stock_is_aggregated(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $ok = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [
                ['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000, 'discount' => 0],
                ['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 80000, 'discount' => 0],
            ],
        ])->assertCreated();
        $this->assertCount(2, $ok->json('items'));
        $this->assertEquals(2, $book->fresh()->inventories()->where('branch_id', $branch->id)->value('quantity'));

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [
                ['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000],
                ['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000],
            ],
        ])->assertStatus(422);
    }

    public function test_invoice_controller_has_no_float_discount_aggregation(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Api/InvoiceController.php'));
        $this->assertStringNotContainsString("(float) (\$item['discount']", $src);
        $this->assertStringNotContainsString('round($agg', $src);
        $this->assertSame('10.00', Money::percentOf(100, 10));
        $this->assertSame('90.00', Money::percentOf(900, 10));
    }
}
