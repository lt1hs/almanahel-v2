<?php

namespace Tests\Feature\Finance;

use App\Models\Check;
use App\Models\InvoiceItem;
use App\Models\Settlement;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t10
 * @group t101
 * @group finance
 * @group ledger
 */
class T101HistoricalReportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_july_credit_sale_august_payment_does_not_rewrite_july_ar(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 150, 'cost_price_toman' => 90]);
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => '2026-08-15',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 150]],
        ])->assertCreated()->json();

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'invoice_id' => $sale['id'],
            'amount' => 150,
            'currency' => 'toman',
            'method' => 'cash',
        ])->assertCreated();

        $july = $this->finance('receivables', $branch->id, '2026-07-01', '2026-07-31');
        $august = $this->finance('receivables', $branch->id, '2026-08-01', '2026-08-31');

        $this->assertSame('150.00', $july['closing']['ledger']);
        $this->assertSame('150.00', $july['operational_as_of']);
        $this->assertTrue($july['reconciled']);
        $this->assertSame('0.00', $august['closing']['ledger']);
        $this->assertSame('0.00', $august['operational_as_of']);
        $this->assertSame('pending', $july['customers'][0]['invoices'][0]['historical_status']);
        $this->assertSame('paid', $july['customers'][0]['invoices'][0]['current_status']);
    }

    public function test_july_consignment_sale_august_settlement_does_not_rewrite_july_ap(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
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
                'quantity' => 3,
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

        $open = $this->finance('payables', $branch->id, '2026-07-01', '2026-07-31');
        $this->assertTrue(Money::cmp($open['operational_as_of'], '0') > 0, json_encode($open));
        $amount = $open['operational_as_of'];

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'amount' => $amount,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $july = $this->finance('payables', $branch->id, '2026-07-01', '2026-07-31');
        $august = $this->finance('payables', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertSame($amount, $july['closing']['ledger']);
        $this->assertSame($amount, $july['operational_as_of']);
        $this->assertTrue($july['reconciled'], json_encode($july));
        $this->assertSame('0.00', $august['closing']['ledger']);
        $this->assertSame('0.00', $august['operational_as_of']);
    }

    public function test_pending_incoming_check_in_july_clears_in_august(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 200, 'cost_price_toman' => 80]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'IN-JUL',
            'payer_name' => 'پرداخت‌کننده',
            'due_date' => '2026-08-10',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 200]],
        ])->assertCreated()->json();
        $checkId = Check::where('invoice_id', $sale['id'])->value('id');

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->putJson('/api/checks/'.$checkId, ['status' => 'cleared'])->assertOk();

        $july = $this->finance('checks', $branch->id, '2026-07-01', '2026-07-31');
        $august = $this->finance('checks', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertSame('200.00', $july['incoming']['pending']);
        $this->assertSame('200.00', $july['ledger_checks_receivable']);
        $this->assertSame('0.00', $august['incoming']['pending']);
        $this->assertSame('200.00', $august['incoming']['cleared']);
        $this->assertSame('0.00', $august['ledger_checks_receivable']);
        $this->assertSame('cleared', Check::find($checkId)->status);
        $this->assertSame('200.00', $august['incoming_current']['cleared']);
        $position = $this->finance('financial-position', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertTrue(Money::cmp($position['assets']['banks'], '0') > 0 || Money::cmp($position['assets']['cash_drawers'], '0') > 0, json_encode($position['assets']));
        $this->assertTrue($position['balanced'], json_encode($position));
    }

    public function test_supplier_check_issued_in_july_reconstructs_clear_and_bounce_at_month_end(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
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
                'quantity' => 8,
                'cost_price' => 50,
                'selling_price' => 80,
            ]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 4, 'actual_price' => 80]],
        ])->assertCreated();
        $open = $this->finance('payables', $branch->id, '2026-07-01', '2026-07-31')['operational_as_of'];
        $half = Money::of(bcdiv($open, '2', 2));
        $cleared = $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'amount' => $half,
            'currency' => 'toman',
            'payment_method' => 'check',
            'check_number' => 'OUT-CLR',
        ])->assertCreated()->json('id');
        $bounced = $this->postJson('/api/consignments/settle', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'amount' => $half,
            'currency' => 'toman',
            'payment_method' => 'check',
            'check_number' => 'OUT-BNC',
        ])->assertCreated()->json('id');

        $july = $this->finance('checks', $branch->id, '2026-07-01', '2026-07-31');
        $this->assertSame(Money::add($half, $half), $july['outgoing']['pending']);
        $this->assertSame($july['outgoing']['pending'], $july['ledger_checks_payable']);

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->putJson('/api/consignments/settlements/'.$cleared.'/check', ['check_status' => 'cleared'])->assertOk();
        $this->putJson('/api/consignments/settlements/'.$bounced.'/check', ['check_status' => 'bounced'])->assertOk();

        $julyAfter = $this->finance('checks', $branch->id, '2026-07-01', '2026-07-31');
        $this->assertSame($july['outgoing']['pending'], $julyAfter['outgoing']['pending']);
        $august = $this->finance('checks', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertSame($half, $august['outgoing']['cleared']);
        $this->assertSame($half, $august['outgoing']['bounced']);
        $this->assertSame('0.00', $august['outgoing']['pending']);
        $this->assertSame('cleared', Settlement::find($cleared)->check_status);
        $this->assertSame('bounced', Settlement::find($bounced)->check_status);
        $this->assertSame($half, $august['outgoing_current']['cleared']);
    }

    public function test_later_return_does_not_rewrite_earlier_ar_ap_or_checks(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
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
                'quantity' => 2,
                'cost_price' => 50,
                'selling_price' => 80,
            ]],
        ])->assertCreated();
        $customer = $this->makeCustomer($branch);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => '2026-08-15',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 80]],
        ])->assertCreated()->json();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');
        $julyAr = $this->finance('receivables', $branch->id, '2026-07-01', '2026-07-31');
        $julyAp = $this->finance('payables', $branch->id, '2026-07-01', '2026-07-31');

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $julyArAfter = $this->finance('receivables', $branch->id, '2026-07-01', '2026-07-31');
        $julyApAfter = $this->finance('payables', $branch->id, '2026-07-01', '2026-07-31');
        $this->assertSame($julyAr['closing']['ledger'], $julyArAfter['closing']['ledger']);
        $this->assertSame($julyAr['operational_as_of'], $julyArAfter['operational_as_of']);
        $this->assertSame($julyAp['closing']['ledger'], $julyApAfter['closing']['ledger']);
        $this->assertSame($julyAp['operational_as_of'], $julyApAfter['operational_as_of']);
        $augustAr = $this->finance('receivables', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertSame('0.00', $augustAr['closing']['ledger']);
    }

    public function test_financial_position_is_balanced_and_splits_opening_from_current_result(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $branch = $this->makeBranch(['supports_toman' => true, 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 40,
            'selling_price' => 100,
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100]],
        ])->assertCreated();

        $july = $this->finance('financial-position', $branch->id, '2026-07-01', '2026-07-31');
        $this->assertTrue($july['balanced'], json_encode($july));
        $this->assertSame($july['total_assets'], Money::add($july['total_liabilities'], $july['equity']['total_equity']));
        $this->assertSame('0.00', $july['equity']['accumulated_result_before_period']);
        $this->assertNotSame($july['equity']['current_period_result'], $july['equity']['accumulated_result_before_period']);
        $this->assertSame($july['equity']['current_period_result'], $july['equity']['period_result']);
        $this->assertSame($july['equity']['accumulated_result_before_period'], $july['equity']['accumulated_result']);

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'amount' => 10,
            'currency' => 'toman',
            'category' => 'حمل',
            'date' => now()->toDateString(),
        ])->assertCreated();
        $this->putJson('/api/expenses/'.\App\Models\Expense::latest('id')->value('id'), ['amount' => 8])->assertOk();

        $august = $this->finance('financial-position', $branch->id, '2026-08-01', '2026-08-31');
        $this->assertTrue($august['balanced'], json_encode($august));
        $this->assertSame($july['equity']['current_period_result'], $august['equity']['accumulated_result_before_period']);
        $this->assertSame('-8.00', $august['equity']['current_period_result']);
        $this->assertNotSame($august['equity']['current_period_result'], $august['equity']['accumulated_result_before_period']);
    }

    public function test_toman_and_dinar_positions_are_independently_balanced(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'supports_dinar' => true, 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $tomanBook = $this->makeBook();
        $dinarBook = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $tomanBook->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 40,
            'selling_price' => 90,
        ])->assertCreated();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $dinarBook->id,
            'quantity' => 1,
            'currency' => 'dinar',
            'cost_price' => 3,
            'selling_price' => 7,
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $tomanBook->id, 'quantity' => 1, 'actual_price' => 90]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'dinar',
            'items' => [['book_id' => $dinarBook->id, 'quantity' => 1, 'actual_price' => 7]],
        ])->assertCreated();

        $toman = $this->getJson('/api/finance/financial-position?'.http_build_query([
            'branch_id' => $branch->id,
            'date_from' => '1970-01-01',
            'date_to' => '2099-12-31',
            'currency' => 'toman',
        ]))->assertOk()->json();
        $dinar = $this->getJson('/api/finance/financial-position?'.http_build_query([
            'branch_id' => $branch->id,
            'date_from' => '1970-01-01',
            'date_to' => '2099-12-31',
            'currency' => 'dinar',
        ]))->assertOk()->json();
        $this->assertTrue($toman['balanced'], json_encode($toman));
        $this->assertTrue($dinar['balanced'], json_encode($dinar));
        $this->assertNotSame($toman['total_assets'], $dinar['total_assets']);
    }

    public function test_report_authorization_matrix_at_endpoints(): void
    {
        $home = $this->makeBranch(['name' => 'خانه', 'supports_toman' => true]);
        $other = $this->makeBranch(['name' => 'دیگر', 'city' => 'مشهد', 'supports_toman' => true]);
        $iraq = $this->makeBranch(['name' => 'نجف', 'city' => 'نجف', 'country' => 'عراق', 'supports_toman' => true]);
        $this->actingAsRole('admin', $home);
        $book = $this->makeBook();
        $this->makeInventory($home, $book, ['quantity' => 2, 'price_toman' => 50, 'cost_price_toman' => 20]);
        $this->makeInventory($other, $book, ['quantity' => 2, 'price_toman' => 50, 'cost_price_toman' => 20]);
        $this->postJson('/api/invoices', [
            'branch_id' => $home->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 50]],
        ])->assertCreated();

        $this->actingAsRole('warehouse_staff', $home);
        $this->getJson('/api/finance/pnl?currency=toman')->assertForbidden();
        $this->getJson('/api/reports/monthly-trends?currency=toman')->assertForbidden();
        $this->getJson('/api/reports/top-books')->assertForbidden();
        $dash = $this->getJson('/api/reports/dashboard')->assertOk()->json();
        $this->assertSame('0.00', $dash['today_sales_toman']);
        $this->assertSame(0, $dash['pending_checks']);
        $this->assertSame(0, $dash['total_suppliers']);
        $this->assertSame(0, $dash['total_branches']);
        $this->assertGreaterThan(0, $dash['total_stock']);

        $this->actingAsRole('branch_manager', $home);
        $this->getJson('/api/reports/all-branches')->assertForbidden();
        $this->getJson('/api/finance/pnl?currency=toman')->assertOk();
        $this->getJson('/api/finance/treasury?date_from=1970-01-01&date_to=2099-12-31')->assertOk();
        $this->getJson('/api/reports/top-books?currency=toman')->assertOk();
        $this->getJson('/api/reports/monthly-trends?branch_id='.$other->id.'&currency=toman')->assertForbidden();
        $own = $this->getJson('/api/reports/monthly-trends?currency=toman')->assertOk()->json();
        $this->assertNotEmpty($own);
        $this->getJson('/api/finance/receivables?branch_id='.$other->id.'&currency=toman')->assertForbidden();

        $this->actingAsRole('branch_manager', $home, ['iraq_only_visible_branches' => [$iraq->id]]);
        $this->getJson('/api/finance/pnl?branch_id='.$iraq->id.'&currency=toman')->assertForbidden();
        $this->getJson('/api/reports/top-books?branch_id='.$iraq->id)->assertForbidden();
        $this->getJson('/api/reports/iraq-profit?currency=toman')->assertForbidden();

        $this->actingAsRole('accountant', $home);
        $this->getJson('/api/reports/monthly-trends?currency=toman')->assertOk();
        $this->getJson('/api/reports/top-books?branch_id='.$other->id)->assertOk();
        $this->getJson('/api/finance/treasury?date_from=1970-01-01&date_to=2099-12-31')->assertOk()
            ->assertJsonFragment(['branch_id' => null]);
        $this->getJson('/api/reports/all-branches')->assertOk();
    }

    public function test_pending_checks_on_dashboard_are_branch_scoped_for_manager(): void
    {
        $a = $this->makeBranch(['supports_toman' => true]);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد', 'supports_toman' => true]);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 2, 'price_toman' => 80, 'cost_price_toman' => 40]);
        $this->makeInventory($b, $book, ['quantity' => 2, 'price_toman' => 80, 'cost_price_toman' => 40]);
        $this->postJson('/api/invoices', [
            'branch_id' => $b->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'B-1',
            'payer_name' => 'الف',
            'due_date' => now()->addDay()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 80]],
        ])->assertCreated();

        $this->actingAsRole('branch_manager', $a);
        $dash = $this->getJson('/api/reports/dashboard')->assertOk()->json();
        $this->assertSame(0, $dash['pending_checks']);
        $this->assertSame(0, $dash['total_suppliers']);
        $this->actingAsRole('admin', $a);
        $adminDash = $this->getJson('/api/reports/dashboard')->assertOk()->json();
        $this->assertGreaterThan(0, $adminDash['pending_checks']);
    }

    /**
     * @return array<string, mixed>
     */
    private function finance(string $path, int $branchId, string $from, string $to): array
    {
        return $this->getJson('/api/finance/'.$path.'?'.http_build_query([
            'branch_id' => $branchId,
            'date_from' => $from,
            'date_to' => $to,
            'currency' => 'toman',
        ]))->assertOk()->json();
    }
}
