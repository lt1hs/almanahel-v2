<?php

namespace Tests\Feature\Finance;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ledger\SystemAccounts;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t10
 * @group finance
 * @group ledger
 */
class T10LedgerReportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_reversed_replacement_expense_nets_to_replacement_amount(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $created = $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'amount' => 100,
            'currency' => 'toman',
            'category' => 'حمل',
            'date' => now()->toDateString(),
        ])->assertCreated()->json();
        $this->putJson('/api/expenses/'.$created['id'], ['amount' => 80])->assertOk();

        $activeOnly = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'active')
            ->where('journal_lines.ledger_account_id', SystemAccounts::get('operating_expense', 'toman')->id)
            ->selectRaw('sum(journal_lines.debit) - sum(journal_lines.credit) as net')
            ->value('net');
        $this->assertSame('-20.00', Money::of($activeOnly));

        $pnl = $this->getJson('/api/finance/pnl?currency=toman&date_from=1970-01-01&date_to=2099-12-31&branch_id='.$branch->id)
            ->assertOk()->json();
        $this->assertSame('80.00', $pnl['operating_expenses']);
        $this->assertGreaterThanOrEqual(2, JournalEntry::whereIn('event_type', ['expense', 'expense_reversal'])->count());
    }

    public function test_cash_sale_expense_and_refund_reconcile_treasury(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 40,
            'selling_price' => 100,
        ])->assertCreated();
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100]],
        ])->assertCreated()->json();
        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'amount' => 10,
            'currency' => 'toman',
            'category' => 'حمل',
            'date' => now()->toDateString(),
        ])->assertCreated();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $treasury = $this->getJson('/api/finance/treasury?date_from=1970-01-01&date_to=2099-12-31&branch_id='.$branch->id)
            ->assertOk()->json();
        $cash = collect($treasury['accounts'])->first(
            fn ($row) => $row['type'] === 'cash_drawer' && $row['currency'] === 'toman' && (int) $row['branch_id'] === (int) $branch->id
        );
        $this->assertNotNull($cash);
        $this->assertSame('-50.00', $cash['closing_balance']);
        $this->assertSame($cash['closing_balance'], $cash['balance']);
    }

    public function test_credit_sale_payment_and_return_reconcile_ar(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100, 'cost_price_toman' => 70]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100]],
        ])->assertCreated()->json();
        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'invoice_id' => $sale['id'],
            'amount' => 30,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $ar = $this->getJson('/api/finance/receivables?currency=toman&date_from=1970-01-01&date_to=2099-12-31&branch_id='.$branch->id)
            ->assertOk()->json();
        $this->assertTrue($ar['reconciled']);
        $this->assertSame('0.00', $ar['ledger_total']);
        $this->assertSame($customer->name, $ar['customers'][0]['customer_name']);
    }

    public function test_consignment_gift_settlement_and_supplier_check_reconcile_ap(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true]);
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
                'quantity' => 5,
                'cost_price' => 50,
                'selling_price' => 80,
            ]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 80]],
        ])->assertCreated();
        $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'مهمان',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated();
        $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 100,
            'currency' => 'toman',
            'payment_method' => 'check',
            'check_number' => 'CHK-1',
        ])->assertCreated();

        $ap = $this->getJson('/api/finance/payables?currency=toman&date_from=1970-01-01&date_to=2099-12-31&branch_id='.$branch->id)
            ->assertOk()->json();
        $this->assertTrue($ap['reconciled'], json_encode($ap));
        $this->assertSame($supplier->name, $ap['suppliers'][0]['supplier_name']);
        $this->assertSame($branch->name, $ap['suppliers'][0]['branch_name']);
        $checks = $this->getJson('/api/finance/checks?currency=toman&date_from=1970-01-01&date_to=2099-12-31&branch_id='.$branch->id)
            ->assertOk()->json();
        $this->assertSame('100.00', $checks['ledger_checks_payable']);
        $this->assertSame('100.00', $checks['outgoing']['pending']);
        $this->assertSame('0.00', $checks['outgoing_difference']);
    }

    public function test_owned_purchase_sale_and_return_reconcile_inventory(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 40,
            'selling_price' => 90,
        ])->assertCreated();
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 90]],
        ])->assertCreated()->json();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $inv = $this->getJson('/api/finance/inventory-value?currency=toman&branch_id='.$branch->id)
            ->assertOk()->json();
        $this->assertTrue($inv['reconciled'], json_encode($inv));
        $this->assertSame('80.00', $inv['ledger_owned_inventory']);
        $this->assertSame('80.00', $inv['stock_lot_owned_inventory']);
        $this->assertSame($book->title, $inv['owned_lots'][0]['book_title']);
        $this->assertSame($branch->name, $inv['owned_lots'][0]['branch_name']);
        $this->assertSame([], $inv['consignment_memorandum']);
    }

    public function test_customer_credit_liability_is_customer_scoped_and_currency_separated(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'supports_dinar' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 4, 'price_toman' => 100, 'cost_price_toman' => 70]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'customer_id' => $customer->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100]],
        ])->assertCreated()->json();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'credit',
            'customer_id' => $customer->id,
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $ar = $this->getJson('/api/finance/receivables?currency=toman&date_from=1970-01-01&date_to=2099-12-31')
            ->assertOk()->json();
        $row = collect($ar['customers'])->firstWhere('customer_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertSame('100.00', $row['customer_credit_liability']);
        $dinar = $this->getJson('/api/finance/receivables?currency=dinar&date_from=1970-01-01&date_to=2099-12-31')
            ->assertOk()->json();
        $this->assertSame('0.00', $dinar['ledger_total']);
        $anon = JournalLine::query()
            ->where('ledger_account_id', SystemAccounts::get('customer_credit_liability', 'toman')->id)
            ->whereNull('customer_id')
            ->count();
        $this->assertSame(0, $anon);
    }

    public function test_archived_branch_historical_reports_remain_available(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'name' => 'شعبه بایگانی']);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 1, 'price_toman' => 50, 'cost_price_toman' => 20]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 50]],
        ])->assertCreated();
        $branch->update(['status' => 'inactive']);

        $rows = $this->getJson('/api/reports/all-branches')->assertOk()->json();
        $hit = collect($rows)->first(fn ($row) => (int) $row['branch']['id'] === (int) $branch->id);
        $this->assertNotNull($hit);
        $this->assertSame('50.00', $hit['revenue_toman']);
        $this->getJson('/api/branches/'.$branch->id.'/profit')->assertOk()
            ->assertJsonPath('net_profit_toman', '30.00');
    }

    public function test_role_isolation_for_financial_reports(): void
    {
        $a = $this->makeBranch(['supports_toman' => true, 'name' => 'الف']);
        $b = $this->makeBranch(['supports_toman' => true, 'name' => 'ب']);
        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/finance/pnl?branch_id='.$b->id.'&currency=toman&date_from=1970-01-01&date_to=2099-12-31')
            ->assertForbidden();
        $own = $this->getJson('/api/finance/pnl?currency=toman&date_from=1970-01-01&date_to=2099-12-31')
            ->assertOk()->json();
        $this->assertSame($a->id, $own['branch_id']);

        $this->actingAsRole('warehouse_staff', $a);
        $this->getJson('/api/finance/pnl?currency=toman&date_from=1970-01-01&date_to=2099-12-31')
            ->assertForbidden();

        $this->actingAsRole('accountant', $a);
        $this->getJson('/api/finance/pnl?currency=toman&date_from=1970-01-01&date_to=2099-12-31')->assertOk();
        $this->getJson('/api/reports/all-branches')->assertOk();
        $this->actingAsRole('admin', $a);
        $this->getJson('/api/finance/treasury?date_from=1970-01-01&date_to=2099-12-31')->assertOk()
            ->assertJsonFragment(['branch_id' => null]);
    }

    public function test_every_finance_api_monetary_amount_is_a_decimal_string(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 1, 'price_toman' => 80, 'cost_price_toman' => 40]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 80]],
        ])->assertCreated();
        $q = 'date_from=1970-01-01&date_to=2099-12-31&currency=toman&branch_id='.$branch->id;
        foreach ([
            '/api/finance/pnl?'.$q,
            '/api/finance/treasury?'.$q,
            '/api/finance/trial-balance?'.$q,
            '/api/finance/financial-position?'.$q,
            '/api/finance/receivables?'.$q,
            '/api/finance/payables?'.$q,
            '/api/finance/checks?'.$q,
            '/api/finance/inventory-value?'.$q,
        ] as $url) {
            $json = $this->getJson($url)->assertOk()->json();
            $this->assertMonetaryStrings($json);
        }
        $this->assertMonetaryStrings($this->getJson('/api/reports/all-branches')->assertOk()->json());
        $tb = $this->getJson('/api/finance/trial-balance?'.$q)->assertOk()->json();
        $this->assertSame($tb['total_debits'], $tb['total_credits']);
        $this->assertTrue($tb['balanced']);
    }

    public function test_reports_preflight_passes_on_clean_posted_sale(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 40,
            'selling_price' => 90,
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 90]],
        ])->assertCreated();
        $this->artisan('finance:reports-preflight', ['--format' => 'json'])->assertSuccessful();
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/finance/pnl?currency=toman&date_from=2026-08-02&date_to=2026-08-01')
            ->assertStatus(422);
    }

    private function assertMonetaryStrings(mixed $node, string $path = ''): void
    {
        $skip = [
            'id', 'branch_id', 'book_id', 'customer_id', 'supplier_id', 'financial_account_id',
            'ledger_account_id', 'stock_lot_id', 'sales_count', 'open_invoices', 'overdue_invoices',
            'today_invoice_count', 'month', 'total_sold', 'total_stock', 'total_titles', 'total_books',
            'total_suppliers', 'total_branches', 'low_stock_count', 'out_of_stock_count', 'pending_checks',
            'invoice_id',
        ];
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_int($key) || in_array((string) $key, $skip, true)) {
                    $this->assertMonetaryStrings($value, $path.'.'.$key);
                    continue;
                }
                if (is_bool($value) || $value === null || is_array($value)) {
                    $this->assertMonetaryStrings($value, $path.'.'.$key);
                    continue;
                }
                if (is_float($value)) {
                    $this->fail("float at {$path}.{$key}: {$value}");
                }
                if (is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value)) {
                    continue;
                }
                $this->assertMonetaryStrings($value, $path.'.'.$key);
            }
        }
    }
}
