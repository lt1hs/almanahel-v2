<?php

namespace Tests\Feature\Finance;

use App\Exceptions\DomainException;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SaleLotAllocation;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Models\WarehouseLog;
use App\Services\Ledger\FinancialPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t9
 * @group finance
 */
class T9CutoverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_v2_is_the_only_runtime_and_full_cost_is_200000(): void
    {
        $this->assertTrue(app(\App\Services\Finance\FinanceMode::class)->postingV2());
        $this->mock(\App\Services\Ledger\LedgerPoster::class, function ($mock) {
            $mock->shouldNotReceive('postSale');
            $mock->shouldNotReceive('postGift');
            $mock->shouldNotReceive('postSettlement');
            $mock->shouldNotReceive('postExpense');
            $mock->shouldNotReceive('postPurchase');
            $mock->shouldNotReceive('postCustomerReturn');
            $mock->shouldNotReceive('postCustomerPayment');
            $mock->shouldNotReceive('postCheckCleared');
            $mock->shouldNotReceive('postCheckBounced');
        });

        $ctx = $this->consignmentSold(2);
        $this->assertEquals(200000, (float) SaleLotAllocation::sum('publisher_payable'));
        $this->assertSame(1, JournalEntry::where('event_type', 'sale')->count());

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $ctx['supplier']->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
        ]))->assertOk()->json();
        $this->assertEquals(200000, (float) $preview['total_payable']);

        $balance = $this->getJson('/api/suppliers/' . $ctx['supplier']->id . '/balance')->assertOk()->json();
        $this->assertEquals(200000, (float) $balance['unsettled_balances'][0]['balance']);

        $unsettled = $this->getJson('/api/consignments/unsettled-by-supplier?'.http_build_query([
            'branch_id' => $ctx['branch']->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
        ]))->assertOk()->json();
        $this->assertEquals(200000, (float) collect($unsettled['rows'] ?? [])->first()['balance']);

        foreach ([0.1, 0.5, 0.99] as $rate) {
            config(['almanahel.consignment_commission_rate' => $rate]);
            Cache::put('almanahel.consignment_commission_rate', $rate);
            $again = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
                'supplier_id' => $ctx['supplier']->id,
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
                'branch_id' => $ctx['branch']->id,
                'currency' => 'toman',
            ]))->assertOk()->json();
            $this->assertEquals(200000, (float) $again['total_payable']);
        }

        $settle = $this->postJson('/api/consignments/settle', [
            'supplier_id' => $ctx['supplier']->id,
            'branch_id' => $ctx['branch']->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 200000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();
        $this->assertEquals(200000, (float) $settle->json('amount'));
        $this->assertEquals(200000, (float) SettlementAllocation::sum('amount'));
    }

    public function test_v2_gift_payable_is_full_cost(): void
    {
        $ctx = $this->consignmentSold(0, 5);
        $this->postJson('/api/gifts', [
            'branch_id' => $ctx['branch']->id,
            'book_id' => $ctx['book']->id,
            'quantity' => 1,
            'recipient_name' => 'هدیه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $ctx['supplier']->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
        ]))->assertOk()->json();
        $this->assertEquals(100000, (float) $preview['total_payable']);
    }

    public function test_v2_return_reduces_open_payable_using_split(): void
    {
        $ctx = $this->consignmentSold(2);
        $invoice = Invoice::first();
        $itemId = $invoice->items()->first()->id;
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoice->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $ctx['supplier']->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
        ]))->assertOk()->json();
        $this->assertEquals(100000, (float) $preview['total_payable']);
    }

    public function test_v2_posting_failure_rolls_back_invoice_and_stock(): void
    {
        $this->mock(FinancialPostingService::class, function ($mock) {
            $mock->shouldReceive('postSale')->andThrow(new DomainException('fail'));
        });
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active']);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $qtyBefore = (int) StockLot::sum('qty_available');

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertStatus(422);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalLine::count());
        $this->assertSame($qtyBefore, (int) StockLot::sum('qty_available'));
    }

    public function test_v2_expense_failure_leaves_no_expense(): void
    {
        $this->mock(FinancialPostingService::class, function ($mock) {
            $mock->shouldReceive('postExpense')->andThrow(new DomainException('fail'));
        });
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active']);
        $this->actingAsRole('admin', $branch);
        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'amount' => 50000,
            'currency' => 'toman',
            'category' => 'حمل',
            'date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_v2_purchase_failure_leaves_no_warehouse_log(): void
    {
        $this->mock(FinancialPostingService::class, function ($mock) {
            $mock->shouldReceive('postOwnedPurchase')->andThrow(new DomainException('fail'));
        });
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active', 'is_central_warehouse' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 70000,
            'selling_price' => 100000,
        ])->assertStatus(422);
        $this->assertSame(0, WarehouseLog::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_v2_credit_paid_is_rejected(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active']);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = \App\Models\Customer::create(['name' => 'مشتری', 'branch_id' => $branch->id]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'credit',
            'currency' => 'toman',
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری',
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->putJson('/api/credits/' . $sale->json('id'), ['payment_status' => 'paid'])->assertStatus(409);
        $this->assertSame('pending', Invoice::find($sale->json('id'))?->payment_status);
    }

    public function test_prepare_t9_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        $ctx = $this->consignmentSold(1);
        $lot = StockLot::where('ownership_type', 'consignment')->first();
        $lot->forceFill(['payable_basis' => null, 'payable_rate' => null])->save();
        $alloc = SaleLotAllocation::first();
        $alloc->forceFill(['publisher_payable' => null, 'payable_basis' => null])->save();

        $this->artisan('finance:prepare-t9')->assertSuccessful();
        $this->assertNull($lot->fresh()->payable_basis);

        $this->artisan('finance:prepare-t9', ['--apply' => true])->assertSuccessful();
        $this->assertSame('full_unit_cost', $lot->fresh()->payable_basis);
        $stamped = (float) $alloc->fresh()->publisher_payable;
        $this->artisan('finance:prepare-t9', ['--apply' => true])->assertSuccessful();
        $this->assertEquals($stamped, (float) $alloc->fresh()->publisher_payable);
        $this->assertNotNull($ctx['invoice']->fresh()->sold_at);
    }

    public function test_reports_flag_is_enabled_after_t10(): void
    {
        $this->assertTrue((bool) config('almanahel.finance_ledger_reports_enabled'));
    }

    private function consignmentSold(int $qty, int $received = 10): array
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active']);
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
                'quantity' => $received,
                'cost_price' => 100000,
                'selling_price' => 150000,
            ]],
        ])->assertCreated();

        $invoice = null;
        if ($qty > 0) {
            $res = $this->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'payment_method' => 'cash',
                'currency' => 'toman',
                'items' => [['book_id' => $book->id, 'quantity' => $qty, 'actual_price' => 150000]],
            ])->assertCreated();
            $invoice = Invoice::find($res->json('id'));
        }

        return compact('branch', 'book', 'supplier') + ['invoice' => $invoice];
    }
}
