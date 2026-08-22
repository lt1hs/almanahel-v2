<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\DomainException;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Services\Ledger\FinancialPostingService;
use App\Services\Settlement\PayableSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group ledger */
class InvoiceAndAllocationGuardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_normal_percentage_and_above_list_invoice_totals_post(): void
    {
        $normal = $this->sale(150, 150, 0, 150);
        (new FinancialPostingService())->postSale($normal['invoice'], $normal['cash'], now());
        $this->assertSame(1, JournalEntry::where('source_id', $normal['invoice']->id)->count());

        $pct = $this->sale(100, 200, 20, 180, discount: 10, qty: 2);
        (new FinancialPostingService())->postSale($pct['invoice'], $pct['cash'], now());

        $above = $this->sale(150, 200, 0, 200, actual: 200);
        (new FinancialPostingService())->postSale($above['invoice'], $above['cash'], now());
        $this->assertTrue(true);
    }

    public function test_tampered_total_subtotal_and_zero_leave_no_journal(): void
    {
        $ctx = $this->sale(150, 150, 0, 150);
        $before = JournalEntry::count();
        $ctx['invoice']->update(['total' => 999]);
        try {
            (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('tampered total');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $ctx['invoice']->update(['total' => 150, 'subtotal' => 50]);
        try {
            (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('tampered subtotal');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $ctx['invoice']->update(['subtotal' => 0, 'total' => 0]);
        $ctx['item']->update(['actual_price' => 0, 'unit_price' => 0]);
        try {
            (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('zero');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_incomplete_sale_gift_and_wrong_lot_leave_no_journal(): void
    {
        $ctx = $this->sale(150, 150, 0, 150);
        $ctx['alloc']->update(['quantity' => 0]);
        $before = JournalEntry::count();
        try {
            (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('qty');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $otherBranch = $this->makeBranch();
        $ctx['alloc']->update(['quantity' => 1]);
        $ctx['lot']->update(['branch_id' => $otherBranch->id]);
        try {
            (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('branch');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, JournalEntry::count());

        $gift = Gift::create([
            'branch_id' => $ctx['branch']->id,
            'book_id' => $ctx['book']->id,
            'quantity' => 2,
            'recipient_name' => 'x',
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
        try {
            (new FinancialPostingService())->postGift($gift, now());
            $this->fail('gift qty');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_settlement_check_credits_checks_payable_not_bank(): void
    {
        $ctx = $this->sale(150, 150, 0, 150);
        $supplier = $this->makeSupplier();
        $lot = StockLot::create([
            'book_id' => $ctx['book']->id,
            'branch_id' => $ctx['branch']->id,
            'supplier_id' => $supplier->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => 100,
            'qty_original' => 2,
            'qty_available' => 2,
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
        ]);
        $invoice = Invoice::create([
            'branch_id' => $ctx['branch']->id,
            'invoice_number' => 'INV-S-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 140,
            'total' => 140,
            'sold_at' => now(),
            'type' => 'sale',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $ctx['book']->id,
            'quantity' => 1,
            'unit_price' => 140,
            'actual_price' => 140,
        ]);
        $saleAlloc = SaleLotAllocation::create([
            'invoice_item_id' => $item->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'publisher_payable' => 100,
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
        ]);
        $settlement = Settlement::create([
            'supplier_id' => $supplier->id,
            'branch_id' => $ctx['branch']->id,
            'settlement_number' => 'SET-CHK',
            'period_type' => 'custom',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 100,
            'currency' => 'toman',
            'payment_method' => 'check',
            'paid_at' => now(),
        ]);
        SettlementAllocation::create([
            'settlement_id' => $settlement->id,
            'sale_lot_allocation_id' => $saleAlloc->id,
            'amount' => 100,
            'currency' => 'toman',
        ]);
        $bank = FinancialAccount::create([
            'code' => 'bank.' . uniqid(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
            'type' => 'bank',
            'name' => 'bank',
            'ledger_account_id' => LedgerAccount::where('code', 'sys.bank.toman')->firstOrFail()->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        $before = JournalEntry::count();
        try {
            (new FinancialPostingService())->postSettlement($settlement, $bank, now());
            $this->fail('bank on check');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, JournalEntry::count());

        $checks = FinancialAccount::create([
            'code' => 'cp.' . uniqid(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
            'type' => 'checks_payable',
            'name' => 'checks payable',
            'ledger_account_id' => LedgerAccount::where('code', 'sys.checks_payable.toman')->firstOrFail()->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        $entry = (new FinancialPostingService())->postSettlement($settlement, $checks, now());
        $this->assertSame($checks->id, (int) $entry->lines->firstWhere('credit', '100.00')->financial_account_id);
    }

    /** @return array<string, mixed> */
    private function sale(mixed $unit, mixed $subtotal, mixed $discountAmount, mixed $total, mixed $actual = null, mixed $discount = 0, int $qty = 1): array
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 10, 'cost_price_toman' => 100]);
        $lot = StockLot::where('branch_id', $branch->id)->where('book_id', $book->id)->firstOrFail();
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'sold_at' => now(),
            'type' => 'sale',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => $qty,
            'unit_price' => $unit,
            'actual_price' => $actual ?? $unit,
            'discount' => $discount,
        ]);
        $alloc = SaleLotAllocation::create([
            'invoice_item_id' => $item->id,
            'stock_lot_id' => $lot->id,
            'quantity' => $qty,
            'unit_cost' => 100,
            'currency' => 'toman',
        ]);
        $cash = FinancialAccount::create([
            'code' => 'cash.' . uniqid(),
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'type' => 'cash_drawer',
            'name' => 'cash',
            'ledger_account_id' => LedgerAccount::where('code', 'sys.cash_drawer.toman')->firstOrFail()->id,
            'is_default' => false,
            'is_active' => true,
        ]);

        return compact('branch', 'book', 'lot', 'invoice', 'item', 'alloc', 'cash');
    }
}
