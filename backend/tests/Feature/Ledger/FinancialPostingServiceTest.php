<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnLotAllocation;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
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
class FinancialPostingServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_owned_cash_sale_posts_expected_accounts_and_dimensions(): void
    {
        $ctx = $this->ownedContext();
        $entry = (new FinancialPostingService())->postSale($ctx['invoice'], $ctx['cash'], now());

        $this->assertSame('150.00', $this->sum($entry->id, $ctx['cash']->ledger_account_id, 'debit'));
        $this->assertSame('150.00', $this->sum($entry->id, SystemAccounts::get('sales_revenue', 'toman')->id, 'credit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'debit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('owned_inventory', 'toman')->id, 'credit'));
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('supplier_payable', 'toman')->id)->count());

        $cashLine = JournalLine::where('journal_entry_id', $entry->id)->whereNotNull('financial_account_id')->first();
        $this->assertSame($ctx['cash']->id, (int) $cashLine->financial_account_id);
        $cogsLine = JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('cogs', 'toman')->id)->first();
        $this->assertSame($ctx['branch']->id, (int) $cogsLine->branch_id);
        $this->assertSame($ctx['lot']->id, (int) $cogsLine->stock_lot_id);
        $this->assertSame($ctx['alloc']->id, (int) $cogsLine->sale_lot_allocation_id);
        $this->assertNull($cogsLine->financial_account_id);
        $this->assertJournalBalanced($entry->id);
    }

    public function test_consignment_cash_sale_credits_payable_not_inventory_at_snapshot(): void
    {
        $ctx = $this->consignmentContext();
        $entry = (new FinancialPostingService())->postSale($ctx['invoice'], $ctx['cash'], now());

        $this->assertSame('200.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'debit'));
        $this->assertSame('200.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'credit'));
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('owned_inventory', 'toman')->id)->count());
        $payable = JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('supplier_payable', 'toman')->id)->first();
        $this->assertSame($ctx['supplier']->id, (int) $payable->supplier_id);
        $this->assertSame('iraq_local', $payable->origin_scope);
        $this->assertJournalBalanced($entry->id);
    }

    public function test_legacy_ninety_percent_consignment_cogs_equals_publisher_payable(): void
    {
        $ctx = $this->legacyConsignmentContext();
        $entry = (new FinancialPostingService())->postSale($ctx['invoice'], $ctx['cash'], now());

        $this->assertSame('90.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'debit'));
        $this->assertSame('90.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'credit'));
        $this->assertNotSame('100.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'debit'));
        $this->assertJournalBalanced($entry->id);
    }

    public function test_mixed_owned_legacy_and_full_cost_sale_balances(): void
    {
        $ctx = $this->mixedThreeWayContext();
        $entry = (new FinancialPostingService())->postSale($ctx['invoice'], $ctx['cash'], now());

        $this->assertJournalBalanced($entry->id);
        $this->assertSame('430.00', $this->sum($entry->id, $ctx['cash']->ledger_account_id, 'debit'));
        $this->assertSame('290.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'debit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('owned_inventory', 'toman')->id, 'credit'));
        $this->assertSame('190.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'credit'));
    }

    public function test_owned_gift_and_consignment_gift_use_persisted_allocations(): void
    {
        $owned = $this->ownedContext();
        $gift = Gift::create([
            'branch_id' => $owned['branch']->id,
            'book_id' => $owned['book']->id,
            'quantity' => 1,
            'recipient_name' => 'x',
            'cost_value' => 100,
            'currency' => 'toman',
            'is_consignment' => false,
            'gifted_at' => now(),
        ]);
        GiftLotAllocation::create([
            'gift_id' => $gift->id,
            'stock_lot_id' => $owned['lot']->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'owned',
        ]);
        $entry = (new FinancialPostingService())->postGift($gift, now());
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('gift_expense', 'toman')->id, 'debit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('owned_inventory', 'toman')->id, 'credit'));
        $giftLine = JournalLine::where('journal_entry_id', $entry->id)
            ->where('ledger_account_id', SystemAccounts::get('gift_expense', 'toman')->id)->first();
        $this->assertNotNull($giftLine->gift_lot_allocation_id);
        $this->assertJournalBalanced($entry->id);

        $consign = $this->consignmentContext();
        $gift2 = Gift::create([
            'branch_id' => $consign['branch']->id,
            'book_id' => $consign['book']->id,
            'quantity' => 1,
            'recipient_name' => 'y',
            'cost_value' => 100,
            'currency' => 'toman',
            'is_consignment' => true,
            'supplier_id' => $consign['supplier']->id,
            'gifted_at' => now(),
        ]);
        GiftLotAllocation::create([
            'gift_id' => $gift2->id,
            'stock_lot_id' => $consign['lot']->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'ownership_type' => 'consignment',
            'supplier_id' => $consign['supplier']->id,
            'publisher_payable' => 100,
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
        ]);
        $entry2 = (new FinancialPostingService())->postGift($gift2, now());
        $this->assertSame('100.00', $this->sum($entry2->id, SystemAccounts::get('gift_expense', 'toman')->id, 'debit'));
        $this->assertSame('100.00', $this->sum($entry2->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'credit'));
        $this->assertJournalBalanced($entry2->id);
    }

    public function test_owned_customer_return_reverses_sold_cost(): void
    {
        $ctx = $this->ownedContext();
        $ret = $this->makeReturn($ctx, 'owned');
        $entry = (new FinancialPostingService())->postCustomerReturn($ret, $ctx['cash'], now());
        $this->assertSame('150.00', $this->sum($entry->id, SystemAccounts::get('sales_returns', 'toman')->id, 'debit'));
        $this->assertSame('150.00', $this->sum($entry->id, $ctx['cash']->ledger_account_id, 'credit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('cogs', 'toman')->id, 'credit'));
        $this->assertSame('100.00', $this->sum($entry->id, SystemAccounts::get('owned_inventory', 'toman')->id, 'debit'));
        $this->assertNotNull(JournalLine::where('journal_entry_id', $entry->id)
            ->whereNotNull('customer_return_lot_allocation_id')->first());
        $this->assertJournalBalanced($entry->id);
    }

    public function test_settlement_has_no_pnl_and_uses_allocation_ids(): void
    {
        $ctx = $this->consignmentContext();
        $settlement = Settlement::create([
            'supplier_id' => $ctx['supplier']->id,
            'branch_id' => $ctx['branch']->id,
            'settlement_number' => 'SET-1',
            'period_type' => 'custom',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 200,
            'currency' => 'toman',
            'payment_method' => 'bank_transfer',
        ]);
        $alloc = SettlementAllocation::create([
            'settlement_id' => $settlement->id,
            'sale_lot_allocation_id' => $ctx['alloc']->id,
            'amount' => 200,
            'currency' => 'toman',
            'quantity' => 2,
            'unit_cost' => 100,
        ]);
        $bank = $this->makeTreasury($ctx['branch'], 'bank');
        $entry = (new FinancialPostingService())->postSettlement($settlement, $bank, now());
        $this->assertSame('200.00', $this->sum($entry->id, SystemAccounts::get('supplier_payable', 'toman')->id, 'debit'));
        $this->assertSame('200.00', $this->sum($entry->id, $bank->ledger_account_id, 'credit'));
        $this->assertSame($alloc->id, (int) JournalLine::where('journal_entry_id', $entry->id)->first()->settlement_allocation_id);
        $this->assertSame($ctx['supplier']->id, (int) JournalLine::where('journal_entry_id', $entry->id)
            ->whereNotNull('financial_account_id')->first()->supplier_id);
        $this->assertSame(0, JournalLine::where('journal_entry_id', $entry->id)
            ->whereIn('ledger_account_id', [
                SystemAccounts::get('operating_expense', 'toman')->id,
                SystemAccounts::get('cogs', 'toman')->id,
                SystemAccounts::get('sales_revenue', 'toman')->id,
            ])->count());
        $this->assertJournalBalanced($entry->id);
    }

    public function test_expense_edited_twice_preserves_full_version_chain(): void
    {
        $ctx = $this->ownedContext();
        $expense = Expense::create([
            'branch_id' => $ctx['branch']->id,
            'amount' => 100,
            'currency' => 'toman',
            'category' => 'rent',
            'date' => now()->toDateString(),
        ]);
        $service = new FinancialPostingService();
        $when = now()->addDay();
        $v1 = $service->postExpense($expense, $ctx['cash'], now());
        $expense->update(['amount' => 120]);
        $v2 = $service->reverseAndReplaceExpense($expense, $v1, $ctx['cash'], $when);
        $expense->update(['amount' => 140]);
        $v3 = $service->reverseAndReplaceExpense($expense, $v2, $ctx['cash'], $when->copy()->addHour());

        $this->assertSame(1, (int) $v1->version);
        $this->assertSame(2, (int) $v2->version);
        $this->assertSame(3, (int) $v3->version);
        $this->assertSame('reversed', $v1->fresh()->status);
        $this->assertSame('reversed', $v2->fresh()->status);
        $this->assertSame('active', $v3->fresh()->status);
        $this->assertSame('100.00', $this->sum($v1->id, SystemAccounts::get('operating_expense', 'toman')->id, 'debit'));
        $this->assertSame('120.00', $this->sum($v2->id, SystemAccounts::get('operating_expense', 'toman')->id, 'debit'));
        $this->assertSame('140.00', $this->sum($v3->id, SystemAccounts::get('operating_expense', 'toman')->id, 'debit'));
        $this->assertSame($v1->id, (int) $v2->supersedes_entry_id);
        $this->assertSame($v2->id, (int) $v3->supersedes_entry_id);

        $r1 = JournalEntry::where('event_type', 'expense_reversal')->where('reverses_entry_id', $v1->id)->firstOrFail();
        $r2 = JournalEntry::where('event_type', 'expense_reversal')->where('reverses_entry_id', $v2->id)->firstOrFail();
        $this->assertSame(1, (int) $r1->version);
        $this->assertSame(2, (int) $r2->version);
        $this->assertSame($when->toDateTimeString(), $r1->occurred_at->toDateTimeString());
        $this->assertSame(1, JournalEntry::where('source_type', Expense::class)
            ->where('source_id', $expense->id)->where('event_type', 'expense')->where('status', 'active')->count());
        $this->assertJournalBalanced($r1->id);
        $this->assertJournalBalanced($v3->id);
    }

    public function test_failure_missing_account_mixed_currency_empty_unstamped_duplicate(): void
    {
        $ctx = $this->ownedContext();
        $service = new FinancialPostingService();
        $inactive = $ctx['cash']->replicate();
        $inactive->code = 'cash.inactive.' . uniqid();
        $inactive->is_default = false;
        $inactive->is_active = false;
        $inactive->save();

        try {
            $service->postSale($ctx['invoice'], $inactive, now());
            $this->fail('inactive');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $ctx['alloc']->update(['currency' => 'dinar']);
        try {
            $service->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
            $this->fail('mixed');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $ctx['alloc']->update(['currency' => 'toman']);

        $expense = Expense::create([
            'branch_id' => $ctx['branch']->id,
            'amount' => 0,
            'currency' => 'toman',
            'category' => 'rent',
            'date' => now()->toDateString(),
        ]);
        try {
            $service->postExpense($expense, $ctx['cash'], now());
            $this->fail('empty');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $consign = $this->consignmentContext();
        $consign['alloc']->update(['payable_basis' => null, 'publisher_payable' => null]);
        try {
            $service->postSale($consign['invoice'], $consign['cash'], now());
            $this->fail('unstamped');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $service->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
        $this->expectException(DomainException::class);
        $service->postSale($ctx['invoice']->fresh(), $ctx['cash'], now());
    }

    public function test_credit_sale_requires_ar_account_and_stores_customer_id(): void
    {
        $ctx = $this->ownedContext();
        $customer = Customer::create(['name' => 'علی', 'phone' => '0912', 'branch_id' => $ctx['branch']->id]);
        $ctx['invoice']->update(['payment_method' => 'credit', 'customer_id' => $customer->id]);
        $ar = $this->makeTreasury($ctx['branch'], 'accounts_receivable');
        $entry = (new FinancialPostingService())->postSale($ctx['invoice']->fresh(), $ar, now());
        $line = JournalLine::where('journal_entry_id', $entry->id)->whereNotNull('financial_account_id')->first();
        $this->assertSame($customer->id, (int) $line->customer_id);
        $this->assertSame($ar->id, (int) $line->financial_account_id);
    }

    public function test_wrong_account_type_branch_corporate_and_currency_fail_closed(): void
    {
        $ctx = $this->ownedContext();
        $service = new FinancialPostingService();
        $bank = $this->makeTreasury($ctx['branch'], 'bank');
        try {
            $service->postSale($ctx['invoice'], $bank, now());
            $this->fail('bank on cash sale');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $other = $this->makeBranch();
        $otherCash = $this->makeTreasury($other);
        try {
            $service->postSale($ctx['invoice'], $otherCash, now());
            $this->fail('other branch');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $corp = $this->makeTreasury(null, 'cash_drawer');
        try {
            $service->postSale($ctx['invoice'], $corp, now());
            $this->fail('corporate on branch sale');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $dinarCash = FinancialAccount::create([
            'code' => 'cash.dinar.' . uniqid(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'dinar',
            'type' => 'cash_drawer',
            'name' => 'dinar cash',
            'ledger_account_id' => LedgerAccount::where('code', 'sys.cash_drawer.dinar')->firstOrFail()->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        try {
            $service->postSale($ctx['invoice'], $dinarCash, now());
            $this->fail('currency');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(0, JournalEntry::where('source_type', Invoice::class)
            ->where('source_id', $ctx['invoice']->id)->count());
    }

    private function assertJournalBalanced(int $entryId): void
    {
        $debit = (string) JournalLine::where('journal_entry_id', $entryId)->sum('debit');
        $credit = (string) JournalLine::where('journal_entry_id', $entryId)->sum('credit');
        $this->assertEquals(0, bccomp($debit, $credit, 2));
    }

    private function sum(int $entryId, int $accountId, string $side): string
    {
        return number_format((float) JournalLine::where('journal_entry_id', $entryId)
            ->where('ledger_account_id', $accountId)->sum($side), 2, '.', '');
    }

    private function makeTreasury($branch, string $type = 'cash_drawer'): FinancialAccount
    {
        $currency = 'toman';
        $ledger = LedgerAccount::where('code', 'sys.' . $type . '.' . $currency)->firstOrFail();
        $branchId = is_object($branch) ? $branch->id : null;

        return FinancialAccount::create([
            'code' => $type . '.' . ($branchId ?? 'c') . '.' . uniqid(),
            'branch_id' => $branchId,
            'currency' => $currency,
            'type' => $type,
            'name' => $type,
            'ledger_account_id' => $ledger->id,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function ownedContext(): array
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'cost_price_toman' => 100]);
        $lot = StockLot::where('branch_id', $branch->id)->where('book_id', $book->id)->firstOrFail();
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => 150,
            'total' => 150,
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
        $cash = $this->makeTreasury($branch);

        return compact('branch', 'book', 'lot', 'invoice', 'alloc', 'cash', 'item');
    }

    /** @return array<string, mixed> */
    private function consignmentContext($branch = null, $cash = null): array
    {
        return $this->makeConsignmentSale(
            $branch,
            $cash,
            2,
            280,
            200,
            PayableSnapshot::BASIS_FULL_UNIT_COST,
            '1.0000'
        );
    }

    /** @return array<string, mixed> */
    private function legacyConsignmentContext(): array
    {
        return $this->makeConsignmentSale(
            null,
            null,
            1,
            140,
            90,
            PayableSnapshot::BASIS_PERCENTAGE_OF_UNIT_COST,
            '0.9000'
        );
    }

    /** @return array<string, mixed> */
    private function makeConsignmentSale($branch, $cash, int $qty, mixed $total, mixed $payable, string $basis, string $rate): array
    {
        $branch = $branch ?: $this->makeBranch();
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
            'payable_rule_source' => $basis === PayableSnapshot::BASIS_FULL_UNIT_COST ? 'intake' : 'legacy_inferred',
        ]);
        $invoice = Invoice::create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-C-' . uniqid(),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'currency' => 'toman',
            'subtotal' => $total,
            'total' => $total,
            'type' => 'sale',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'book_id' => $book->id,
            'quantity' => $qty,
            'unit_price' => $qty > 0 ? ((float) $total / $qty) : 0,
            'actual_price' => $qty > 0 ? ((float) $total / $qty) : 0,
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
            'rule_source' => $lot->payable_rule_source,
        ]);
        $cash = $cash ?: $this->makeTreasury($branch);

        return compact('branch', 'book', 'lot', 'invoice', 'alloc', 'cash', 'supplier', 'item');
    }

    /** @return array<string, mixed> */
    private function mixedThreeWayContext(): array
    {
        $owned = $this->ownedContext();
        $legacyLot = StockLot::create([
            'book_id' => $this->makeBook()->id,
            'branch_id' => $owned['branch']->id,
            'supplier_id' => $this->makeSupplier()->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => 100,
            'qty_original' => 5,
            'qty_available' => 5,
            'origin' => 'iraq_local',
            'payable_basis' => PayableSnapshot::BASIS_PERCENTAGE_OF_UNIT_COST,
            'payable_rate' => '0.9000',
            'payable_rule_source' => 'legacy_inferred',
        ]);
        $fullLot = StockLot::create([
            'book_id' => $this->makeBook()->id,
            'branch_id' => $owned['branch']->id,
            'supplier_id' => $this->makeSupplier()->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => 100,
            'qty_original' => 5,
            'qty_available' => 5,
            'origin' => 'qom_distributed',
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
            'payable_rule_source' => 'intake',
        ]);
        $legacyItem = InvoiceItem::create([
            'invoice_id' => $owned['invoice']->id,
            'book_id' => $legacyLot->book_id,
            'quantity' => 1,
            'unit_price' => 140,
            'actual_price' => 140,
        ]);
        $fullItem = InvoiceItem::create([
            'invoice_id' => $owned['invoice']->id,
            'book_id' => $fullLot->book_id,
            'quantity' => 1,
            'unit_price' => 140,
            'actual_price' => 140,
        ]);
        SaleLotAllocation::create([
            'invoice_item_id' => $legacyItem->id,
            'stock_lot_id' => $legacyLot->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'publisher_payable' => 90,
            'payable_basis' => PayableSnapshot::BASIS_PERCENTAGE_OF_UNIT_COST,
            'payable_rate' => '0.9000',
            'rule_source' => 'legacy_inferred',
        ]);
        SaleLotAllocation::create([
            'invoice_item_id' => $fullItem->id,
            'stock_lot_id' => $fullLot->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'currency' => 'toman',
            'publisher_payable' => 100,
            'payable_basis' => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate' => '1.0000',
            'rule_source' => 'intake',
        ]);
        $owned['invoice']->update(['subtotal' => 430, 'total' => 430]);

        return $owned;
    }

    private function makeReturn(array $ctx, string $ownership, int $qty = 1, mixed $refund = 150): CustomerReturn
    {
        $ret = CustomerReturn::create([
            'invoice_id' => $ctx['invoice']->id,
            'branch_id' => $ctx['branch']->id,
            'return_number' => 'RET-' . uniqid(),
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
            'ownership_type' => $ownership,
            'supplier_id' => $ctx['supplier']->id ?? null,
            'payable_basis' => $ctx['alloc']->payable_basis,
            'payable_rate' => $ctx['alloc']->payable_rate,
            'origin_scope' => $ctx['lot']->origin,
        ]);

        return $ret->fresh();
    }
}
