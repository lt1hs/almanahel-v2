<?php

namespace Tests\Feature\Finance;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnLotAllocation;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerAccount;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Services\Ledger\T9Preflight;
use App\Services\Settlement\PayableSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class T9PreflightCoverageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_clean_sqlite_fixture_preflight_passes(): void
    {
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
        $this->artisan('finance:t9-preflight')->assertSuccessful();
        $this->assertSame([], (new T9Preflight())->issues());
    }

    public function test_incomplete_sale_allocations(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $ctx['alloc']->update(['quantity' => 0]);
        $this->assertIssueContains('sale item');
    }

    public function test_incomplete_gift_allocations(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $gift = Gift::create([
            'branch_id' => $ctx['branch']->id,
            'book_id' => $ctx['book']->id,
            'quantity' => 2,
            'recipient_name' => 'g',
            'cost_value' => 100,
            'currency' => 'toman',
            'is_consignment' => false,
            'gifted_at' => now(),
        ]);
        GiftLotAllocation::create([
            'gift_id' => $gift->id,
            'stock_lot_id' => $ctx['lot']->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'owned',
        ]);
        $this->assertIssueContains('gift');
    }

    public function test_incomplete_customer_return_allocations(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $ret = CustomerReturn::create([
            'invoice_id' => $ctx['invoice']->id,
            'branch_id' => $ctx['branch']->id,
            'return_number' => 'RET-P',
            'refund_amount' => 150,
            'refund_method' => 'cash',
            'returned_at' => now(),
        ]);
        $item = CustomerReturnItem::create([
            'customer_return_id' => $ret->id,
            'book_id' => $ctx['book']->id,
            'invoice_item_id' => $ctx['item']->id,
            'quantity' => 1,
            'unit_price' => 150,
        ]);
        CustomerReturnLotAllocation::create([
            'customer_return_id' => $ret->id,
            'customer_return_item_id' => $item->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'stock_lot_id' => $ctx['lot']->id,
            'quantity' => 0,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'owned',
        ]);
        $this->assertIssueContains('return item');
    }

    public function test_settlement_amount_and_supplier_mismatch(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $supplier = $this->makeSupplier();
        $settlement = Settlement::create([
            'supplier_id' => $supplier->id,
            'branch_id' => $ctx['branch']->id,
            'settlement_number' => 'SET-P',
            'period_type' => 'custom',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 50,
            'currency' => 'toman',
            'payment_method' => 'cash',
            'paid_at' => now(),
        ]);
        SettlementAllocation::create([
            'settlement_id' => $settlement->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'amount' => 10,
            'currency' => 'dinar',
        ]);
        $issues = (new T9Preflight())->issues();
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'settlement')));
    }

    public function test_allocation_lot_book_branch_currency_mismatch(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $otherBook = $this->makeBook();
        $ctx['lot']->update(['book_id' => $otherBook->id]);
        $this->assertIssueContains('book/lot mismatch');
    }

    public function test_invalid_payable_split_invariant(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $ret = CustomerReturn::create([
            'invoice_id' => $ctx['invoice']->id,
            'branch_id' => $ctx['branch']->id,
            'return_number' => 'RET-SPLIT',
            'refund_amount' => 150,
            'refund_method' => 'cash',
            'returned_at' => now(),
        ]);
        $item = CustomerReturnItem::create([
            'customer_return_id' => $ret->id,
            'book_id' => $ctx['book']->id,
            'invoice_item_id' => $ctx['item']->id,
            'quantity' => 1,
            'unit_price' => 150,
        ]);
        CustomerReturnLotAllocation::create([
            'customer_return_id' => $ret->id,
            'customer_return_item_id' => $item->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'stock_lot_id' => $ctx['lot']->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'consignment',
            'publisher_payable_reversed' => 30,
            'unsettled_payable_reversed' => 10,
            'settled_payable_reversed' => 10,
        ]);
        $this->assertIssueContains('payable split invariant');
    }

    public function test_required_schema_indexes_and_foreign_keys_exist(): void
    {
        $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
        $this->assertTrue($indexes->contains('journal_source_event_version_unique'));
        $this->assertTrue($indexes->contains('journal_entries_reverses_entry_id_unique'));
        $this->assertTrue(Schema::hasColumn('journal_lines', 'gift_lot_allocation_id'));
        $this->assertTrue(Schema::hasColumn('journal_lines', 'customer_return_lot_allocation_id'));
        $this->assertFalse(Schema::hasColumn('customer_return_lot_allocations', 'was_supplier_payable_settled'));
        $fk = collect(Schema::getForeignKeys('financial_accounts'))->first(
            fn ($key) => in_array('branch_id', $key['columns'], true)
        );
        $this->assertNotNull($fk);
        $this->assertNotSame('set null', strtolower((string) ($fk['on_delete'] ?? '')));
    }

    public function test_missing_business_dates(): void
    {
        $this->bootstrap();
        $ctx = $this->ownedSale();
        $ctx['invoice']->update(['sold_at' => null]);
        $this->assertIssueContains('sold_at');
    }

    public function test_financial_account_wrong_system_ledger(): void
    {
        $this->bootstrap();
        $acct = FinancialAccount::where('type', 'cash_drawer')->where('currency', 'toman')->firstOrFail();
        $acct->update(['ledger_account_id' => LedgerAccount::where('code', 'sys.bank.toman')->firstOrFail()->id]);
        $this->assertIssueContains('ledger');
    }

    public function test_missing_default_for_payment_method_scope(): void
    {
        $this->makeBranch(['supports_toman' => true, 'status' => 'active'], false);
        $this->assertIssueContains('missing default financial account');
    }

    public function test_financial_account_branch_restrict_on_delete(): void
    {
        $branch = $this->makeBranch();
        FinancialAccount::create([
            'code' => 'cash.keep',
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'type' => 'cash_drawer',
            'name' => 'cash',
            'ledger_account_id' => LedgerAccount::where('code', 'sys.cash_drawer.toman')->firstOrFail()->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $branch->delete();
    }

    private function bootstrap(): void
    {
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
    }

    private function assertIssueContains(string $needle): void
    {
        $issues = (new T9Preflight())->issues();
        $this->assertTrue(
            collect($issues)->contains(fn ($i) => str_contains($i, $needle)),
            implode("\n", $issues)
        );
        $this->artisan('finance:t9-preflight')->assertFailed();
    }

    /** @return array<string, mixed> */
    private function ownedSale(): array
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active']);
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true]);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'cost_price_toman' => 100]);
        $lot = StockLot::where('branch_id', $branch->id)->where('book_id', $book->id)->firstOrFail();
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-P-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 150,
            'total' => 150,
            'sold_at' => now(),
            'type' => 'sale',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'unit_price' => 150,
            'actual_price' => 150,
        ]);
        $alloc = SaleLotAllocation::create([
            'invoice_item_id' => $item->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
        ]);

        return compact('branch', 'book', 'lot', 'invoice', 'item', 'alloc');
    }
}
