<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\DomainException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnLotAllocation;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Services\Ledger\FinancialPostingService;
use App\Services\Ledger\SystemAccounts;
use App\Services\Settlement\PayableSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group ledger */
class ConsignmentReturnPostingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_unpaid_consignment_return_debits_payable_at_snapshot(): void
    {
        $ctx = $this->sale(2, 200, PayableSnapshot::BASIS_FULL_UNIT_COST, '1.0000');
        $ret = $this->returnOf($ctx, 2, 280);
        $entry = (new FinancialPostingService())->postCustomerReturn($ret, $ctx['cash'], now());

        $this->assertSame('200.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'credit'));
        $this->assertSame('200.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'debit'));
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('supplier_recoverable', 'toman')->id)->count());
        $this->assertJournalBalanced($entry->id);
        $row = CustomerReturnLotAllocation::first();
        $this->assertSame('200.00', $row->publisher_payable_reversed);
        $this->assertSame('200.00', $row->unsettled_payable_reversed);
        $this->assertSame('0.00', $row->settled_payable_reversed);
    }

    public function test_legacy_ninety_percent_return_reverses_snapshot_not_raw_cost(): void
    {
        $ctx = $this->sale(1, 90, PayableSnapshot::BASIS_PERCENTAGE_OF_UNIT_COST, '0.9000');
        $ret = $this->returnOf($ctx, 1, 140);
        $entry = (new FinancialPostingService())->postCustomerReturn($ret, $ctx['cash'], now());
        $this->assertSame('90.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'credit'));
        $this->assertSame('90.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'debit'));
        $this->assertNotSame('100.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'credit'));
        $this->assertJournalBalanced($entry->id);
    }

    public function test_fully_settled_return_debits_recoverable(): void
    {
        $ctx = $this->sale(1, 100, PayableSnapshot::BASIS_FULL_UNIT_COST, '1.0000');
        $this->settle($ctx, 100);
        $ret = $this->returnOf($ctx, 1, 140);
        $entry = (new FinancialPostingService())->postCustomerReturn($ret, $ctx['cash'], now());
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('supplier_recoverable', 'toman')->id, 'debit'));
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('supplier_payable', 'toman')->id)->count());
        $this->assertJournalBalanced($entry->id);
    }

    public function test_partially_settled_and_multiple_partial_returns(): void
    {
        $ctx = $this->sale(2, 200, PayableSnapshot::BASIS_FULL_UNIT_COST, '1.0000');
        $this->settle($ctx, 80);
        $first = $this->returnOf($ctx, 1, 140, 'RET-A');
        $e1 = (new FinancialPostingService())->postCustomerReturn($first, $ctx['cash'], now());
        $row1 = CustomerReturnLotAllocation::where('customer_return_id', $first->id)->first();
        $this->assertSame('100.00', $row1->publisher_payable_reversed);
        $this->assertSame('100.00', $row1->unsettled_payable_reversed);
        $this->assertSame('0.00', $row1->settled_payable_reversed);
        $this->assertSame('100.00', $this->sum($e1->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'debit'));
        $this->assertJournalBalanced($e1->id);

        $second = $this->returnOf($ctx, 1, 140, 'RET-B');
        $e2 = (new FinancialPostingService())->postCustomerReturn($second, $ctx['cash'], now());
        $row2 = CustomerReturnLotAllocation::where('customer_return_id', $second->id)->first();
        $this->assertSame('100.00', $row2->publisher_payable_reversed);
        $this->assertSame('20.00', $row2->unsettled_payable_reversed);
        $this->assertSame('80.00', $row2->settled_payable_reversed);
        $this->assertSame('20.00', $this->sum($e2->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'debit'));
        $this->assertSame('80.00', $this->sum($e2->id, SystemAccounts::get('supplier_recoverable', 'toman')->id, 'debit'));
        $this->assertJournalBalanced($e2->id);
    }

    public function test_return_quantity_cannot_exceed_original_allocation(): void
    {
        $ctx = $this->sale(2, 200, PayableSnapshot::BASIS_FULL_UNIT_COST, '1.0000');
        $ret = $this->returnOf($ctx, 3, 140);
        $this->expectException(DomainException::class);
        (new FinancialPostingService())->postCustomerReturn($ret, $ctx['cash'], now());
    }

    /** @return array<string, mixed> */
    private function sale(int $qty, mixed $payable, string $basis, string $rate): array
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $lot = StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => 100,
            'qty_original' => 5,
            'qty_available' => 5,
            'origin' => 'iraq_local',
            'payable_basis' => $basis,
            'payable_rate' => $rate,
            'payable_rule_source' => 'intake',
        ]);
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 140 * $qty,
            'total' => 140 * $qty,
            'type' => 'sale',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => $qty,
            'unit_price' => 140,
            'actual_price' => 140,
        ]);
        $alloc = SaleLotAllocation::create([
            'invoice_item_id' => $item->id,
            'stock_lot_id' => $lot->id,
            'quantity' => $qty,
            'unit_cost' => 100,
            'currency' => 'toman',
            'publisher_payable' => $payable,
            'payable_basis' => $basis,
            'payable_rate' => $rate,
            'rule_source' => 'intake',
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

        return compact('branch', 'book', 'lot', 'invoice', 'item', 'alloc', 'cash', 'supplier');
    }

    private function settle(array $ctx, mixed $amount): void
    {
        $settlement = Settlement::create([
            'supplier_id' => $ctx['supplier']->id,
            'branch_id' => $ctx['branch']->id,
            'settlement_number' => 'SET-' . uniqid(),
            'period_type' => 'custom',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $amount,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ]);
        SettlementAllocation::create([
            'settlement_id' => $settlement->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'amount' => $amount,
            'currency' => 'toman',
        ]);
    }

    private function returnOf(array $ctx, int $qty, mixed $refund, ?string $number = null): CustomerReturn
    {
        $ret = CustomerReturn::create([
            'invoice_id' => $ctx['invoice']->id,
            'branch_id' => $ctx['branch']->id,
            'return_number' => $number ?: ('RET-' . uniqid()),
            'refund_amount' => $refund,
            'refund_method' => 'cash',
        ]);
        $item = CustomerReturnItem::create([
            'customer_return_id' => $ret->id,
            'book_id' => $ctx['book']->id,
            'invoice_item_id' => $ctx['item']->id,
            'quantity' => $qty,
            'unit_price' => $refund,
        ]);
        CustomerReturnLotAllocation::create([
            'customer_return_id' => $ret->id,
            'customer_return_item_id' => $item->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'stock_lot_id' => $ctx['lot']->id,
            'quantity' => $qty,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'consignment',
            'supplier_id' => $ctx['supplier']->id,
            'payable_basis' => $ctx['alloc']->payable_basis,
            'payable_rate' => $ctx['alloc']->payable_rate,
            'origin_scope' => 'iraq_local',
        ]);

        return $ret->fresh();
    }

    private function sum(int $entryId, int $accountId, string $side): string
    {
        return number_format((float) JournalLine::where('journal_entry_id', $entryId)
            ->where('ledger_account_id', $accountId)->sum($side), 2, '.', '');
    }

    private function assertJournalBalanced(int $entryId): void
    {
        $debit = (string) JournalLine::where('journal_entry_id', $entryId)->sum('debit');
        $credit = (string) JournalLine::where('journal_entry_id', $entryId)->sum('credit');
        $this->assertEquals(0, bccomp($debit, $credit, 2));
    }
}
