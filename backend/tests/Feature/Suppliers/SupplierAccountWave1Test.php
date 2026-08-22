<?php

namespace Tests\Feature\Suppliers;

use App\Models\ConsignmentReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StockLot;
use App\Models\Settlement;
use App\Models\SupplierAccount;
use App\Services\Suppliers\SupplierAccountBackfill;
use App\Services\Suppliers\SupplierAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group suppliers
 * @group finance
 */
class SupplierAccountWave1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_schema_has_accounts_without_balance_column(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_accounts'));
        $this->assertFalse(Schema::hasColumn('supplier_accounts', 'balance'));
        $this->assertFalse(Schema::hasColumn('supplier_accounts', 'outstanding'));
        foreach ([
            'stock_lots',
            'consignment_receipts',
            'consignment_returns',
            'settlements',
            'gifts',
            'journal_lines',
            'sale_lot_allocations',
            'gift_lot_allocations',
            'customer_return_lot_allocations',
            'consignment_return_lot_allocations',
            'settlement_allocations',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'supplier_account_id'), $table);
        }
    }

    public function test_backfill_creates_one_account_per_branch_supplier_pair(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->makeInventory($a, $book, [
            'quantity' => 2,
            'type' => 'consignment',
            'supplier_id' => $supplier->id,
            'cost_price_toman' => 10000,
        ]);
        $this->makeInventory($b, $book, [
            'quantity' => 3,
            'type' => 'consignment',
            'supplier_id' => $supplier->id,
            'cost_price_toman' => 10000,
        ]);
        StockLot::query()->update(['supplier_account_id' => null]);
        SupplierAccount::query()->delete();

        $dry = app(SupplierAccountBackfill::class)->run(false);
        $this->assertSame(2, $dry['accounts_planned']);
        $this->assertSame(0, SupplierAccount::count());

        $apply = app(SupplierAccountBackfill::class)->run(true);
        $this->assertSame(2, SupplierAccount::count());
        $this->assertSame(2, $apply['accounts_created']);
        $this->assertSame(1, SupplierAccount::where('branch_id', $a->id)->where('supplier_id', $supplier->id)->count());
        $this->assertSame(1, SupplierAccount::where('branch_id', $b->id)->where('supplier_id', $supplier->id)->count());
        $this->assertGreaterThan(0, (int) $apply['stamped']['stock_lots']);
        $this->assertTrue(StockLot::where('branch_id', $a->id)->whereNotNull('supplier_account_id')->exists());
        $lotWithoutSupplier = StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $a->id,
            'ownership_type' => 'owned',
            'currency' => 'toman',
            'unit_cost' => 1,
            'qty_original' => 1,
            'qty_available' => 1,
            'origin' => 'other',
        ]);
        $this->assertNull($lotWithoutSupplier->supplier_account_id);
        app(SupplierAccountBackfill::class)->run(true);
        $this->assertNull($lotWithoutSupplier->fresh()->supplier_account_id);
    }

    public function test_resolver_account_id_wins_and_legacy_ambiguous_fails(): void
    {
        $branch = $this->makeBranch();
        $s1 = $this->makeSupplier(['name' => 'S1']);
        $resolver = app(SupplierAccountResolver::class);
        $account = $resolver->ensureForPair($branch->id, $s1->id);

        $resolved = $resolver->resolveForMutation($branch->id, $account->id, null);
        $this->assertSame($account->id, $resolved['account']->id);
        $this->assertFalse($resolved['legacy_used']);

        $legacy = $resolver->resolveForMutation($branch->id, null, $s1->id);
        $this->assertSame($account->id, $legacy['account']->id);
        $this->assertTrue($legacy['legacy_used']);

        $this->expectException(\App\Exceptions\DomainException::class);
        $resolver->resolveForMutation($branch->id, null, null);
    }

    public function test_legacy_supplier_unresolved_when_no_account(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier();
        try {
            app(SupplierAccountResolver::class)->resolveForMutation($branch->id, null, $supplier->id, false);
            $this->fail('expected 422');
        } catch (\App\Exceptions\DomainException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('supplier_account_unresolved', $e->context['error'] ?? null);
        }
    }

    public function test_canonical_suppliers_locked_and_accounts_isolated(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/suppliers')->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'X'])->assertForbidden();

        $this->actingAsRole('admin', $a);
        $supplier = $this->makeSupplier();
        $accountA = $this->postJson('/api/supplier-accounts', [
            'branch_id' => $a->id,
            'supplier_id' => $supplier->id,
            'display_name' => 'حساب الف',
        ])->assertCreated()->json();
        $accountB = $this->postJson('/api/supplier-accounts', [
            'branch_id' => $b->id,
            'supplier_id' => $supplier->id,
            'display_name' => 'حساب ب',
        ])->assertCreated()->json();

        $this->actingAsRole('branch_manager', $a);
        $this->getJson('/api/supplier-accounts')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $accountA['id']);
        $this->getJson('/api/supplier-accounts/'.$accountB['id'])->assertForbidden();
        $this->postJson('/api/supplier-accounts', [
            'branch_id' => $b->id,
            'supplier_id' => $supplier->id,
            'display_name' => 'جعلی',
        ])->assertForbidden();
        $this->postJson('/api/supplier-accounts', [
            'branch_id' => $a->id,
            'supplier_id' => $supplier->id,
            'display_name' => 'تکراری',
        ])->assertStatus(422);

        $this->actingAsRole('warehouse_staff', $a);
        $this->postJson('/api/supplier-accounts', [
            'branch_id' => $a->id,
            'display_name' => 'انبار',
        ])->assertForbidden();

        $this->actingAsRole('admin');
        $this->getJson('/api/supplier-accounts')->assertStatus(422);
        $this->getJson('/api/supplier-accounts?aggregate=1')->assertOk();
        $this->actingAsRole('accountant');
        $this->getJson('/api/supplier-accounts?aggregate=1')->assertForbidden();
        $this->actingAsRole('admin');
        $this->postJson('/api/supplier-accounts?aggregate=1', [
            'branch_id' => $a->id,
            'display_name' => 'تجمیع',
            'aggregate' => 1,
        ])->assertStatus(422);
    }

    public function test_forged_account_on_consignment_is_rejected(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        $this->actingAsRole('branch_manager', $a);
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $accountB->id,
            'branch_id' => $a->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 20000]],
        ])->assertForbidden();

        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $b->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 20000]],
        ])->assertForbidden();
    }

    public function test_assigned_accountant_sees_only_own_branch_consignments(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        foreach ([[$a, $accountA], [$b, $accountB]] as [$branch, $account]) {
            $this->postJson('/api/consignments', [
                'supplier_account_id' => $account->id,
                'branch_id' => $branch->id,
                'currency' => 'toman',
                'received_at' => now()->toDateString(),
                'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 20000]],
            ])->assertCreated();
        }

        $this->actingAsRole('accountant', $a);
        $branchIds = collect($this->getJson('/api/consignments')->assertOk()->json('data'))
            ->pluck('branch_id')
            ->unique()
            ->all();
        $this->assertSame([(int) $a->id], array_map('intval', $branchIds));
        $this->getJson('/api/consignments?branch_id='.$b->id)->assertForbidden();
    }

    public function test_unassigned_accountant_has_no_operational_consignment_access(): void
    {
        $this->actingAsRole('accountant');
        $this->getJson('/api/consignments')->assertStatus(422);
        $this->getJson('/api/consignments/settlements')->assertStatus(422);
    }

    public function test_consignment_settle_link_never_substitutes_canonical_supplier_id(): void
    {
        $this->assertNull(\App\Support\ConsignmentSettleLink::build(10, null));
        $this->assertTrue(\App\Support\ConsignmentSettleLink::mustNotSubstituteCanonicalSupplierId(null, 99));
        $href = \App\Support\ConsignmentSettleLink::build(10, 55);
        $this->assertStringContainsString('supplier_account_id=55', $href);
        $this->assertStringNotContainsString('supplier_account_id=99', $href);
    }

    public function test_new_consignment_stamps_account_and_balance_separates_currencies(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $receipt = $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 20000]],
        ])->assertCreated()->json();

        $this->assertNotNull($receipt['supplier_account_id']);
        $this->assertSame($supplier->id, $receipt['supplier_id']);
        $lot = StockLot::where('branch_id', $branch->id)->first();
        $this->assertSame($receipt['supplier_account_id'], $lot->supplier_account_id);

        $account = SupplierAccount::find($receipt['supplier_account_id']);
        $ledger = LedgerAccount::where('code', 'sys.supplier_payable.toman')->firstOrFail();
        $entry = JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'test',
            'event_type' => 'test',
            'currency' => 'toman',
            'status' => 'active',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $ledger->id,
            'currency' => 'toman',
            'debit' => 0,
            'credit' => 50,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'supplier_account_id' => $account->id,
        ]);
        $dinarLedger = LedgerAccount::where('code', 'sys.supplier_payable.dinar')->firstOrFail();
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $dinarLedger->id,
            'currency' => 'dinar',
            'debit' => 0,
            'credit' => 9,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'supplier_account_id' => $account->id,
        ]);

        $payload = $this->getJson('/api/supplier-accounts/'.$account->id.'/balance')->assertOk()->json();
        $this->assertSame($account->id, $payload['supplier_account_id']);
        $by = collect($payload['currencies'])->keyBy('currency');
        $this->assertSame('50.00', $by['toman']['as_of']['supplier_payable']);
        $this->assertSame('9.00', $by['dinar']['as_of']['supplier_payable']);
        $this->assertArrayHasKey('period_activity', $by['toman']);
        $this->assertArrayHasKey('current_operational_snapshot', $by['toman']);
        $this->assertArrayNotHasKey('mixed', $payload);
        $this->assertNull($account->getAttribute('balance'));
    }

    public function test_artisan_backfill_command_dry_run(): void
    {
        $this->artisan('suppliers:backfill-accounts')
            ->assertSuccessful();
        Artisan::call('suppliers:backfill-accounts', ['--apply' => true, '--json' => true]);
        $this->assertNotEmpty(Artisan::output());
    }

    public function test_warehouse_cannot_store_consignment(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('warehouse_staff', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 20000]],
        ])->assertForbidden();
    }

    public function test_local_account_provisions_canonical_supplier_and_runs_operations(): void
    {
        $branch = $this->makeBranch();
        $ownedBook = $this->makeBook(['title' => 'Owned title']);
        $consignmentBook = $this->makeBook(['title' => 'Consignment title']);
        $this->actingAsRole('branch_manager', $branch);

        $account = $this->postJson('/api/supplier-accounts', [
            'branch_id' => $branch->id,
            'display_name' => 'ناشر محلی',
            'type' => 'publisher',
        ])->assertCreated()->json();

        $this->assertTrue((bool) $account['created_canonical_supplier']);
        $this->assertNotNull($account['supplier_id']);

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $ownedBook->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 5000,
            'selling_price' => 8000,
            'supplier_account_id' => $account['id'],
            'log_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account['id'],
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $consignmentBook->id, 'quantity' => 3, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $consignmentBook->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account['id'],
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();
        $this->assertGreaterThan(0, (float) $preview['total_payable']);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account['id'],
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $preview['total_payable'],
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    public function test_settlement_preview_is_isolated_per_supplier_account(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->actingAsRole('admin', $a);

        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        foreach ([[$a, $accountA, 2], [$b, $accountB, 5]] as [$branch, $account, $qty]) {
            $this->postJson('/api/consignments', [
                'supplier_account_id' => $account->id,
                'branch_id' => $branch->id,
                'currency' => 'toman',
                'received_at' => now()->toDateString(),
                'items' => [['book_id' => $book->id, 'quantity' => 10, 'cost_price' => 10000, 'selling_price' => 15000]],
            ])->assertCreated();
            $this->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'payment_method' => 'cash',
                'currency' => 'toman',
                'items' => [['book_id' => $book->id, 'quantity' => $qty, 'actual_price' => 15000]],
            ])->assertCreated();
        }

        $previewA = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $accountA->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->assertSame(20000.0, (float) $previewA['total_payable']);
        foreach ($previewA['items'] as $line) {
            $this->assertSame($accountA->id, $line['supplier_account_id'] ?? null);
            $this->assertSame($book->id, $line['book_id'] ?? null);
            $this->assertSame($book->title, $line['title'] ?? null);
        }
    }

    public function test_balance_uses_payable_category_not_cogs_counterpart(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 15000]],
        ])->assertCreated();

        $balance = $this->getJson('/api/supplier-accounts/'.$account->id.'/balance')->assertOk()->json();
        $toman = collect($balance['currencies'])->firstWhere('currency', 'toman');
        $this->assertSame('20000.00', $toman['as_of']['supplier_payable']);
        $this->assertSame('20000.00', $toman['period_activity']['payable_credits']);
        $this->assertArrayHasKey('outstanding_payable', $toman['current_operational_snapshot']);
        $this->assertSame('current', $toman['current_operational_snapshot']['as_of_label']);
    }

    public function test_iraq_visibility_does_not_grant_supplier_account_access(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد', 'country' => 'عراق', 'is_iraq_store' => true]);
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $a);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        $this->actingAsRole('branch_manager', $a, [
            'iraq_only_visible_branches' => [$b->id],
        ]);
        $this->getJson('/api/supplier-accounts/'.$accountB->id)->assertForbidden();
        $this->getJson('/api/supplier-accounts/'.$accountB->id.'/balance')->assertForbidden();
    }

    public function test_authorization_matrix_for_supplier_accounts(): void
    {
        $branch = $this->makeBranch();
        $other = $this->makeBranch(['name' => 'Other', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);
        $otherAccount = app(SupplierAccountResolver::class)->ensureForPair($other->id, $supplier->id);

        foreach (['super_admin', 'admin'] as $role) {
            $this->actingAsRole($role, $branch);
            $this->getJson('/api/suppliers')->assertOk();
            $this->getJson('/api/supplier-accounts?aggregate=1')->assertOk();
            $this->getJson('/api/supplier-accounts/'.$account->id)->assertOk();
        }

        $this->actingAsRole('accountant');
        $this->getJson('/api/suppliers')->assertForbidden();
        $this->getJson('/api/supplier-accounts?aggregate=1')->assertForbidden();
        $this->getJson('/api/supplier-accounts?branch_id='.$branch->id)->assertStatus(422);
        $this->getJson('/api/supplier-accounts?financial=1&branch_id='.$branch->id)->assertStatus(422);
        $this->getJson('/api/supplier-accounts/'.$account->id)->assertForbidden();
        $this->getJson('/api/supplier-accounts/'.$account->id.'/balance')->assertForbidden();
        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertForbidden();

        $this->actingAsRole('accountant', $branch);
        $this->getJson('/api/supplier-accounts?branch_id='.$branch->id)->assertForbidden();
        $financial = $this->getJson('/api/supplier-accounts?financial=1&branch_id='.$branch->id)->assertOk()->json();
        $this->assertNotEmpty($financial);
        $this->assertArrayNotHasKey('phone', $financial[0]);
        $this->assertArrayNotHasKey('email', $financial[0]);
        $this->getJson('/api/supplier-accounts/'.$account->id)->assertForbidden();
        $this->getJson('/api/supplier-accounts/'.$account->id.'/balance')->assertForbidden();

        $this->actingAsRole('branch_manager', $branch);
        $this->getJson('/api/suppliers')->assertForbidden();
        $this->getJson('/api/supplier-accounts?branch_id='.$branch->id)->assertOk();
        $this->getJson('/api/supplier-accounts/'.$account->id)->assertOk();
        $this->getJson('/api/supplier-accounts/'.$otherAccount->id)->assertForbidden();

        $this->actingAsRole('warehouse_staff', $branch);
        $this->getJson('/api/supplier-accounts?branch_id='.$branch->id)->assertForbidden();
    }

    public function test_backfill_reports_unresolved_pair_from_journal_lines(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier();
        $ledger = LedgerAccount::where('code', 'sys.supplier_payable.toman')->firstOrFail();
        $entry = JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'orphan',
            'event_type' => 'test',
            'currency' => 'toman',
            'status' => 'active',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $ledger->id,
            'currency' => 'toman',
            'debit' => 0,
            'credit' => 10,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
        ]);

        $dry = app(SupplierAccountBackfill::class)->run(false);
        $this->assertGreaterThan(0, (int) $dry['unresolved_pair']);
        $this->assertGreaterThan(0, (int) $dry['stamped']['journal_lines']['missing_account']);
    }

    public function test_sale_journal_stamps_allocation_supplier_account_id(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $alloc = \App\Models\SaleLotAllocation::query()->firstOrFail();
        $payableLine = JournalLine::query()
            ->where('sale_lot_allocation_id', $alloc->id)
            ->where('ledger_account_id', LedgerAccount::where('code', 'sys.supplier_payable.toman')->value('id'))
            ->firstOrFail();
        $this->assertSame($account->id, (int) $alloc->supplier_account_id);
        $this->assertSame($account->id, (int) $payableLine->supplier_account_id);
    }

    public function test_strict_backfill_command_fails_when_unresolved(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier();
        $ledger = LedgerAccount::where('code', 'sys.supplier_payable.toman')->firstOrFail();
        $entry = JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'orphan',
            'event_type' => 'test',
            'currency' => 'toman',
            'status' => 'active',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $ledger->id,
            'currency' => 'toman',
            'debit' => 0,
            'credit' => 10,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->artisan('suppliers:backfill-accounts', ['--strict' => true])->assertFailed();
    }

    public function test_recoverable_as_of_balance_is_positive_asset_after_settled_customer_return(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $preview['total_payable'],
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale->json('items.0.id'), 'quantity' => 1]],
        ])->assertCreated();

        $balance = $this->getJson('/api/supplier-accounts/'.$account->id.'/balance')->assertOk()->json();
        $toman = collect($balance['currencies'])->firstWhere('currency', 'toman');
        $this->assertSame('10000.00', $toman['as_of']['supplier_recoverable']);
        $this->assertGreaterThan(0, (float) $toman['as_of']['supplier_recoverable']);
        $this->assertSame('10000.00', $toman['period_activity']['recoverable_debits']);
    }

    public function test_owned_intake_stamps_account_and_canonical_supplier_separately(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 5000,
            'selling_price' => 8000,
            'supplier_account_id' => $account->id,
            'log_date' => now()->toDateString(),
        ])->assertCreated();

        $lot = StockLot::query()->where('branch_id', $branch->id)->where('book_id', $book->id)->firstOrFail();
        $this->assertSame($account->id, (int) $lot->supplier_account_id);
        $this->assertSame($supplier->id, (int) $lot->supplier_id);
        $this->assertSame($account->supplier_id, (int) $lot->supplier_id);
    }

    public function test_cross_branch_intake_resolves_distinct_supplier_accounts(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->actingAsRole('admin', $a);
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);

        $receiptB = $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $b->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated()->json();

        $accountB = SupplierAccount::query()
            ->where('branch_id', $b->id)
            ->where('supplier_id', $supplier->id)
            ->firstOrFail();

        $this->assertNotSame($accountA->id, $accountB->id);
        $this->assertSame($accountB->id, (int) $receiptB['supplier_account_id']);
        $this->assertSame($supplier->id, (int) $receiptB['supplier_id']);
    }

    public function test_multi_branch_intake_stamps_distinct_supplier_accounts_per_branch(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->actingAsRole('admin', $a);
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $accountA->id,
            'branch_id' => $a->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $b->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $lotA = StockLot::query()->where('branch_id', $a->id)->where('book_id', $book->id)->firstOrFail();
        $lotB = StockLot::query()->where('branch_id', $b->id)->where('book_id', $book->id)->firstOrFail();

        $this->assertSame($accountA->id, (int) $lotA->supplier_account_id);
        $this->assertNotSame($lotA->supplier_account_id, $lotB->supplier_account_id);
        $this->assertSame($supplier->id, (int) $lotA->supplier_id);
        $this->assertSame($supplier->id, (int) $lotB->supplier_id);
    }

    public function test_branch_manager_can_settle_own_branch_account(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 3, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $this->actingAsRole('branch_manager', $branch);
        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $preview['total_payable'],
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    public function test_branch_manager_rejected_for_other_branch_settlement(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $a);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        $this->actingAsRole('branch_manager', $a);
        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $accountB->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_accountant_can_settle_with_explicit_branch(): void
    {
        $branch = $this->makeBranch();
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 3, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $this->actingAsRole('accountant', $branch);
        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $preview['total_payable'],
            'currency' => 'toman',
            'payment_method' => 'bank_transfer',
        ])->assertCreated();
    }

    public function test_settlement_branch_mismatch_is_rejected(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $a);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $accountB->id,
            'branch_id' => $a->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertStatus(403);
    }

    public function test_aggregate_settlement_mutation_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments/settle?aggregate=1', [
            'supplier_account_id' => $account->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_unsettled_debt_rows_keyed_by_supplier_account_id(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->actingAsRole('admin', $a);
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        foreach ([[$a, $accountA, 2], [$b, $accountB, 3]] as [$branch, $account, $soldQty]) {
            $this->postJson('/api/consignments', [
                'supplier_account_id' => $account->id,
                'branch_id' => $branch->id,
                'currency' => 'toman',
                'received_at' => now()->toDateString(),
                'items' => [['book_id' => $book->id, 'quantity' => 10, 'cost_price' => 10000, 'selling_price' => 15000]],
            ])->assertCreated();
            $this->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'payment_method' => 'cash',
                'currency' => 'toman',
                'items' => [['book_id' => $book->id, 'quantity' => $soldQty, 'actual_price' => 15000]],
            ])->assertCreated();
        }

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->toDateString();
        $periodQs = '&period_start='.$periodStart.'&period_end='.$periodEnd;

        $rowsA = $this->getJson('/api/consignments/unsettled-by-supplier?branch_id='.$a->id.$periodQs)->assertOk()->json('rows');
        $rowsB = $this->getJson('/api/consignments/unsettled-by-supplier?branch_id='.$b->id.$periodQs)->assertOk()->json('rows');

        $this->assertSame($accountA->id, (int) collect($rowsA)->first()['supplier_account_id']);
        $this->assertSame($accountB->id, (int) collect($rowsB)->first()['supplier_account_id']);
        $this->assertNotSame(
            collect($rowsA)->first()['supplier_account_id'],
            collect($rowsB)->first()['supplier_account_id']
        );
        $this->assertSame($supplier->id, (int) collect($rowsA)->first()['supplier_id']);
    }

    public function test_admin_operational_unsettled_requires_branch_id(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/consignments/unsettled-by-supplier')->assertStatus(422);
    }

    public function test_aggregate_unsettled_is_admin_reporting_only(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);
        $this->getJson('/api/consignments/unsettled-by-supplier?aggregate=1')->assertForbidden();
    }

    public function test_canonical_supplier_id_is_not_accepted_as_supplier_account_id(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier(['name' => 'Canonical']);
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $supplier->id === $account->id ? $account->id + 9999 : $supplier->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_branch_manager_cannot_see_corporate_settlement_history(): void
    {
        $branch = $this->makeBranch();
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $branch);
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        Settlement::create([
            'supplier_id' => $supplier->id,
            'supplier_account_id' => $account->id,
            'branch_id' => null,
            'user_id' => null,
            'settlement_number' => 'SET-CORP',
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ]);
        Settlement::create([
            'supplier_id' => $supplier->id,
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'user_id' => null,
            'settlement_number' => 'SET-BR',
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 2000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ]);

        $this->actingAsRole('branch_manager', $branch);
        $ids = collect($this->getJson('/api/consignments/settlements?branch_id='.$branch->id)->json('data'))
            ->pluck('settlement_number');
        $this->assertTrue($ids->contains('SET-BR'));
        $this->assertFalse($ids->contains('SET-CORP'));
    }

    public function test_assigned_accountant_cannot_access_other_branch_settlement(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $this->actingAsRole('admin', $a);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        $this->actingAsRole('accountant', $a);
        $this->getJson('/api/supplier-accounts?financial=1&branch_id='.$b->id)->assertForbidden();
        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $accountB->id,
            'branch_id' => $b->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 1000,
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_bulk_settlement_scoped_to_branch_accounts(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $this->actingAsRole('admin', $a);
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);

        foreach ([[$a, $accountA], [$b, $accountB]] as [$branch, $account]) {
            $this->postJson('/api/consignments', [
                'supplier_account_id' => $account->id,
                'branch_id' => $branch->id,
                'currency' => 'toman',
                'received_at' => now()->toDateString(),
                'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
            ])->assertCreated();
            $this->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'payment_method' => 'cash',
                'currency' => 'toman',
                'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
            ])->assertCreated();
        }

        $previewB = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $accountB->id,
            'branch_id' => $b->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->postJson('/api/consignments/settle-bulk', [
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'settlements' => [[
                'supplier_account_id' => $accountB->id,
                'branch_id' => $a->id,
                'amount' => $previewB['total_payable'],
                'currency' => 'toman',
            ]],
        ])->assertStatus(403);
    }

    public function test_operational_unsettled_requires_period(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->getJson('/api/consignments/unsettled-by-supplier?branch_id='.$branch->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'period_required');
    }

    public function test_unsettled_rejects_receipt_missing_supplier_account_id(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $receipt = $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated()->json();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();
        ConsignmentReceipt::whereKey($receipt['id'])->update(['supplier_account_id' => null]);

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->toDateString();
        $this->getJson('/api/consignments/unsettled-by-supplier?'.http_build_query([
            'branch_id' => $branch->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]))->assertStatus(422);
    }

    public function test_period_scoped_unsettled_rows_match_settlement_preview_period(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 10, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();
        Invoice::query()->latest('id')->first()?->update([
            'sold_at' => now()->subMonths(3)->startOfMonth(),
        ]);

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->toDateString();

        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => 'toman',
        ]))->assertOk()->json();

        $rows = $this->getJson('/api/consignments/unsettled-by-supplier?'.http_build_query([
            'branch_id' => $branch->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]))->assertOk()->json('rows');

        $this->assertSame($preview['total_payable'], collect($rows)->first()['balance']);
        $this->assertSame('10000.00', collect($rows)->first()['balance']);
    }

    public function test_bulk_settlement_rejects_stale_expected_total(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $supplier = $this->makeSupplier();
        $book = $this->makeBook();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->toDateString();
        $rows = $this->getJson('/api/consignments/unsettled-by-supplier?'.http_build_query([
            'branch_id' => $branch->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]))->assertOk()->json('rows');
        $payable = collect($rows)->first()['balance'];

        $this->postJson('/api/consignments/settle-bulk', [
            'period_type' => 'custom',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payment_method' => 'bank_transfer',
            'settlements' => [[
                'supplier_account_id' => $account->id,
                'branch_id' => $branch->id,
                'amount' => $payable,
                'expected_total' => '1.00',
                'currency' => 'toman',
            ]],
        ])->assertStatus(409);
    }
}
