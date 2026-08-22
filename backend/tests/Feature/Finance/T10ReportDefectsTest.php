<?php

namespace Tests\Feature\Finance;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ledger\SystemAccounts;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t10
 * @group finance
 */
class T10ReportDefectsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_customer_returns_must_affect_return_period_not_sale_month_cogs(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 150, 'cost_price_toman' => 100]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 150]],
        ])->assertCreated()->json();
        $itemId = InvoiceItem::where('invoice_id', $sale['id'])->value('id');

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $july = $this->getJson('/api/finance/pnl?'.http_build_query([
            'branch_id' => $branch->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'currency' => 'toman',
        ]))->assertOk()->json();
        $august = $this->getJson('/api/finance/pnl?'.http_build_query([
            'branch_id' => $branch->id,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->assertSame('150.00', $july['sales_revenue']);
        $this->assertSame('0.00', $july['sales_returns']);
        $this->assertSame('100.00', $july['net_cogs']);
        $this->assertSame('0.00', $august['sales_revenue']);
        $this->assertSame('150.00', $august['sales_returns']);
        $this->assertTrue(Money::isNegative($august['net_cogs']));
        $this->assertSame('-100.00', $august['net_cogs']);
    }

    public function test_today_sales_and_period_reports_must_use_sold_at(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 150, 'cost_price_toman' => 100]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 150]],
        ])->assertCreated()->json();
        DB::table('invoices')->where('id', $sale['id'])->update(['created_at' => '2026-08-19 12:00:00']);

        Carbon::setTestNow('2026-08-19 15:00:00');
        $dash = $this->getJson('/api/reports/dashboard')->assertOk()->json();
        $this->assertSame('0.00', $dash['today_sales_toman']);
        $july = $this->getJson('/api/finance/pnl?'.http_build_query([
            'branch_id' => $branch->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'currency' => 'toman',
        ]))->assertOk()->json();
        $this->assertSame('150.00', $july['net_sales']);
    }

    public function test_financial_reports_must_not_sum_toman_and_dinar(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'supports_dinar' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'quantity' => 4,
            'price_toman' => 100,
            'cost_price_toman' => 70,
            'price_dinar' => 5,
            'cost_price_dinar' => 3,
        ]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100]],
        ])->assertCreated();
        $dinarBook = $this->makeBook();
        $this->makeInventory($branch, $dinarBook, [
            'quantity' => 4,
            'price_toman' => 0,
            'cost_price_toman' => 0,
            'price_dinar' => 5,
            'cost_price_dinar' => 3,
        ]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'dinar',
            'items' => [['book_id' => $dinarBook->id, 'quantity' => 1, 'actual_price' => 5]],
        ])->assertCreated();

        $rows = $this->getJson('/api/reports/all-branches')->assertOk()->json();
        $this->assertArrayNotHasKey('pending_credit', $rows[0]);
        $this->assertSame('100.00', $rows[0]['revenue_toman']);
        $this->assertSame('5.00', $rows[0]['revenue_dinar']);
        $this->assertNotEquals(
            (string) (100 + 5),
            $rows[0]['revenue_toman']
        );
        $pnlT = $this->getJson('/api/finance/pnl?currency=toman&date_from=1970-01-01&date_to=2099-12-31')->assertOk()->json();
        $pnlD = $this->getJson('/api/finance/pnl?currency=dinar&date_from=1970-01-01&date_to=2099-12-31')->assertOk()->json();
        $this->assertSame('100.00', $pnlT['net_sales']);
        $this->assertSame('5.00', $pnlD['net_sales']);
    }

    public function test_iraq_pnl_must_allocate_mixed_origin_invoices_at_item_level(): void
    {
        $iraq = $this->makeBranch([
            'name' => 'نجف',
            'city' => 'نجف',
            'country' => 'عراق',
            'type' => 'store',
            'is_iraq_store' => true,
            'supports_toman' => true,
        ]);
        $this->actingAsRole('admin', $iraq);
        $local = $this->makeBook(['iraq_only' => true, 'title' => 'عراق محلی']);
        $qom = $this->makeBook(['iraq_only' => false, 'title' => 'توزیع قم']);
        $this->makeInventory($iraq, $local, ['quantity' => 2, 'price_toman' => 100, 'cost_price_toman' => 40]);
        $this->makeInventory($iraq, $qom, ['quantity' => 2, 'price_toman' => 200, 'cost_price_toman' => 80]);
        $this->postJson('/api/invoices', [
            'branch_id' => $iraq->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [
                ['book_id' => $local->id, 'quantity' => 1, 'actual_price' => 100],
                ['book_id' => $qom->id, 'quantity' => 1, 'actual_price' => 200],
            ],
        ])->assertCreated();

        $report = $this->getJson('/api/reports/iraq-profit?currency=toman&date_from=1970-01-01&date_to=2099-12-31')
            ->assertOk()->json();
        $this->assertSame('100.00', $report['iraq_local']['gross_sales']);
        $this->assertSame('200.00', $report['qom_distributed']['gross_sales']);
        $this->assertSame('300.00', $report['combined']['gross_sales']);
        $this->assertNotEquals('300.00', $report['iraq_local']['gross_sales']);
        $this->assertSame('0.00', $report['currencies']['dinar']['combined']['gross_sales']);
    }
}
