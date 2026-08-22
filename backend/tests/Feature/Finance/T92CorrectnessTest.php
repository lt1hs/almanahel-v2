<?php

namespace Tests\Feature\Finance;

use App\Exceptions\DomainException;
use App\Models\Check;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Gift;
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
 * @group t92
 * @group finance
 * @group ledger
 */
class T92CorrectnessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_credit_invoice_without_customer_id_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'customer_name' => 'بدون شناسه',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertStatus(422);

        $this->assertSame(0, Invoice::count());
    }

    public function test_linked_credit_invoice_can_be_partially_and_fully_paid(): void
    {
        $ctx = $this->creditSale(100000);
        $customer = $ctx['customer'];
        $invoiceId = $ctx['invoice']->id;

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 40000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();
        $this->assertSame('partially_paid', Invoice::find($invoiceId)->payment_status);

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 60000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();
        $this->assertSame('paid', Invoice::find($invoiceId)->payment_status);
        $this->assertTrue(Money::isZero(app(\App\Services\Receivables\InvoiceBalance::class)->outstanding(Invoice::find($invoiceId))));
    }

    public function test_unallocated_and_cash_invoice_payments_are_rejected(): void
    {
        $ctx = $this->creditSale(100000);
        $cash = $this->cashSale(100000, $ctx['branch'], $ctx['book']);

        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'amount' => 10000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'invoice_id' => $cash->id,
            'amount' => 10000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertStatus(422);

        $other = $this->makeCustomer($ctx['branch'], ['name' => 'دیگر']);
        $this->postJson('/api/customers/'.$other->id.'/payments', [
            'invoice_id' => $ctx['invoice']->id,
            'amount' => 10000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'invoice_id' => $ctx['invoice']->id,
            'amount' => 10000,
            'currency' => 'dinar',
            'method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'invoice_id' => $ctx['invoice']->id,
            'amount' => 200000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_incoming_check_cleared_and_bounced_refresh_invoice_status(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 4, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $cleared = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'CL-1',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->assertSame('pending', Invoice::find($cleared->json('id'))->payment_status);
        $checkA = Check::where('invoice_id', $cleared->json('id'))->first();
        $this->putJson('/api/checks/'.$checkA->id, ['status' => 'cleared'])->assertOk();
        $this->assertSame('paid', Invoice::find($cleared->json('id'))->payment_status);
        $this->putJson('/api/checks/'.$checkA->id, ['status' => 'cleared'])->assertOk();
        $this->putJson('/api/checks/'.$checkA->id, ['status' => 'bounced'])->assertStatus(409);

        $bounced = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'BO-1',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $checkB = Check::where('invoice_id', $bounced->json('id'))->first();
        $this->putJson('/api/checks/'.$checkB->id, ['status' => 'bounced'])->assertOk();
        $this->assertSame('pending', Invoice::find($bounced->json('id'))->payment_status);
        $ar = SystemAccounts::get('accounts_receivable', 'toman')->id;
        $cr = SystemAccounts::get('checks_receivable', 'toman')->id;
        $entry = JournalEntry::where('event_type', 'check_bounced')->latest('id')->first();
        $this->assertSame('100000.00', $this->sum($entry->id, $ar, 'debit'));
        $this->assertSame('100000.00', $this->sum($entry->id, $cr, 'credit'));
    }

    public function test_return_split_unpaid_credit(): void
    {
        $ctx = $this->creditSale(20000, 5);
        $itemId = $ctx['invoice']->items()->first()->id;
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $ctx['invoice']->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 2]],
        ])->assertCreated();
        $row = CustomerReturn::first();
        $this->assertSame('40000.00', Money::of($row->receivable_reduction));
        $this->assertTrue(Money::isZero($row->cash_refund));
        $this->assertTrue(Money::isZero($row->customer_credit_created));
        $entry = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertSame('40000.00', $this->sum($entry->id, SystemAccounts::get('sales_returns', 'toman')->id, 'debit'));
        $this->assertSame('40000.00', $this->sum($entry->id, SystemAccounts::get('accounts_receivable', 'toman')->id, 'credit'));
    }

    public function test_return_split_partially_paid_credit_residual_cash_or_credit(): void
    {
        $ctx = $this->creditSale(50000, 2);
        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'invoice_id' => $ctx['invoice']->id,
            'amount' => 70000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();

        $itemId = $ctx['invoice']->items()->first()->id;
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $ctx['invoice']->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();
        $row = CustomerReturn::first();
        $this->assertSame('30000.00', Money::of($row->receivable_reduction));
        $this->assertSame('20000.00', Money::of($row->cash_refund));
        $entry = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertSame('50000.00', $this->sum($entry->id, SystemAccounts::get('sales_returns', 'toman')->id, 'debit'));
        $this->assertSame('30000.00', $this->sum($entry->id, SystemAccounts::get('accounts_receivable', 'toman')->id, 'credit'));
        $cashId = \App\Models\FinancialAccount::query()
            ->where('branch_id', $ctx['branch']->id)
            ->where('type', 'cash_drawer')
            ->where('currency', 'toman')
            ->value('ledger_account_id');
        $this->assertSame('20000.00', $this->sum($entry->id, (int) $cashId, 'credit'));
    }

    public function test_return_split_partially_paid_credit_residual_store_credit(): void
    {
        $ctx = $this->creditSale(50000, 2);
        $this->postJson('/api/customers/'.$ctx['customer']->id.'/payments', [
            'invoice_id' => $ctx['invoice']->id,
            'amount' => 70000,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();
        $itemId = $ctx['invoice']->items()->first()->id;
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $ctx['invoice']->id,
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();
        $row = CustomerReturn::first();
        $this->assertSame('30000.00', Money::of($row->receivable_reduction));
        $this->assertSame('20000.00', Money::of($row->customer_credit_created));
        $this->assertTrue(Money::isZero($row->cash_refund));
        $entry = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertSame('20000.00', $this->sum($entry->id, SystemAccounts::get('customer_credit_liability', 'toman')->id, 'credit'));
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('accounts_receivable', 'toman')->id)
            ->where('credit', '70000.00')
            ->count());
    }

    public function test_cash_sale_return_cash_and_credit(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 4, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $itemId = $sale->json('items.0.id');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();
        $entry = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertTrue(Money::isZero(CustomerReturn::first()->receivable_reduction));
        $this->assertSame('100000.00', $this->sum($entry->id, SystemAccounts::get('sales_returns', 'toman')->id, 'debit'));
        $cashId = \App\Models\FinancialAccount::query()
            ->where('branch_id', $branch->id)->where('type', 'cash_drawer')->where('currency', 'toman')
            ->value('ledger_account_id');
        $this->assertSame('100000.00', $this->sum($entry->id, (int) $cashId, 'credit'));

        $sale2 = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $this->makeCustomer($branch)->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale2->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $sale2->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $entry2 = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertSame('100000.00', $this->sum($entry2->id, SystemAccounts::get('customer_credit_liability', 'toman')->id, 'credit'));
    }

    public function test_pending_check_return_does_not_reduce_ar(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'P-1',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $row = CustomerReturn::first();
        $this->assertTrue(Money::isZero($row->receivable_reduction));
        $this->assertSame('100000.00', Money::of($row->cash_refund));
        $this->assertSame('pending', Check::first()->status);
        $this->assertSame('100000.00', Money::of(Check::first()->amount));
        $entry = JournalEntry::where('event_type', 'customer_return')->latest('id')->first();
        $this->assertSame('0.00', $this->sum($entry->id, SystemAccounts::get('accounts_receivable', 'toman')->id, 'credit'));
    }

    public function test_bounced_check_return_may_reduce_ar(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'B-1',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->putJson('/api/checks/'.Check::first()->id, ['status' => 'bounced'])->assertOk();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $row = CustomerReturn::first();
        $this->assertSame('100000.00', Money::of($row->receivable_reduction));
        $this->assertTrue(Money::isZero($row->cash_refund));
        $this->assertSame('paid', Invoice::find($sale->json('id'))->payment_status);
    }

    public function test_return_posting_failure_rolls_back_rows_and_stock(): void
    {
        $ctx = $this->creditSale(100000);
        $qtyBefore = (int) StockLot::sum('qty_available');
        $this->app->forgetInstance(\App\Services\Ledger\FinancePostingGateway::class);
        $this->mock(FinancialPostingService::class, function ($mock) {
            $mock->shouldReceive('postCustomerReturn')->andThrow(new DomainException('fail'));
        });
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $ctx['invoice']->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $ctx['invoice']->items()->first()->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, CustomerReturn::count());
        $this->assertSame(0, JournalEntry::where('event_type', 'customer_return')->count());
        $this->assertSame($qtyBefore, (int) StockLot::sum('qty_available'));
    }

    public function test_customer_balances_ignore_cash_and_keep_currencies_separate(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'quantity' => 6,
            'price_toman' => 100000,
            'price_dinar' => 2000,
            'cost_price_toman' => 70000,
            'cost_price_dinar' => 1400,
        ]);
        $customer = $this->makeCustomer($branch);
        $cash = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'customer_id' => $customer->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(5)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $show = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('balances');
        $this->assertSame('100000.00', Money::of($show['toman']['accounts_receivable']));
        $this->assertSame('0.00', Money::of($show['dinar']['accounts_receivable']));
        $this->assertSame(1, $show['toman']['open_invoices']);
        $this->assertSame(0, $show['dinar']['open_invoices']);
        $this->assertSame('100000.00', Money::of($show['toman']['net_balance']));
        $this->assertSame('0.00', Money::of($show['dinar']['net_balance']));

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $cash->json('id'),
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $cash->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();
        $after = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('balances');
        $this->assertSame('100000.00', Money::of($after['toman']['accounts_receivable']));
        $this->assertSame('100000.00', Money::of($after['toman']['customer_credit']));
        $this->assertTrue(Money::isZero($after['toman']['net_balance']));
        $this->assertSame('0.00', Money::of($after['dinar']['customer_credit']));
    }

    public function test_archived_or_other_branch_customer_cannot_be_used_for_credit(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $other = $this->makeCustomer($b, ['name' => 'شعبه دیگر']);
        $archived = $this->makeCustomer($a, ['name' => 'بایگانی', 'archived_at' => now()]);

        $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'customer_id' => $other->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDay()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertStatus(422);

        $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'customer_id' => $archived->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDay()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertStatus(422);
    }

    /**
     * @return array{branch: \App\Models\Branch, book: \App\Models\Book, customer: Customer, invoice: Invoice}
     */
    private function creditSale(int $unitPrice, int $qty = 1): array
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 10, 'price_toman' => $unitPrice, 'cost_price_toman' => 70000]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => $qty, 'actual_price' => $unitPrice]],
        ])->assertCreated();

        return [
            'branch' => $branch,
            'book' => $book,
            'customer' => $customer,
            'invoice' => Invoice::find($sale->json('id')),
        ];
    }

    private function cashSale(int $unitPrice, $branch, $book): Invoice
    {
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => $unitPrice]],
        ])->assertCreated();

        return Invoice::find($sale->json('id'));
    }

    private function sum(int $entryId, int $accountId, string $side): string
    {
        return Money::of(JournalLine::where('journal_entry_id', $entryId)
            ->where('ledger_account_id', $accountId)->sum($side));
    }
}
