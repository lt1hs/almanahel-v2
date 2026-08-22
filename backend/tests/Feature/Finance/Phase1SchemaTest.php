<?php

namespace Tests\Feature\Finance;

use App\Exceptions\DomainException;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Treasury\FinancialAccountResolver;
use App\Support\Ledger\JournalSchemaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class Phase1SchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_system_ledger_codes_exist_per_currency(): void
    {
        foreach (['owned_inventory', 'sales_revenue', 'sales_returns', 'cogs', 'operating_expense', 'gift_expense', 'customer_credit_liability', 'supplier_recoverable', 'trade_payable'] as $code) {
            foreach (['toman', 'dinar'] as $currency) {
                $this->assertTrue(
                    LedgerAccount::where('code', 'sys.' . $code . '.' . $currency)->exists(),
                    $code . ' ' . $currency
                );
            }
        }
    }

    public function test_multiple_bank_accounts_allowed_and_missing_default_fails(): void
    {
        $branch = $this->makeBranch([], false);
        $ledger = LedgerAccount::where('code', 'sys.bank.toman')->firstOrFail();

        FinancialAccount::create([
            'code' => 'bank.toman.a',
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'type' => 'bank',
            'name' => 'Bank A',
            'ledger_account_id' => $ledger->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        FinancialAccount::create([
            'code' => 'bank.toman.b',
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'type' => 'bank',
            'name' => 'Bank B',
            'ledger_account_id' => $ledger->id,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->assertSame(2, FinancialAccount::where('branch_id', $branch->id)->where('type', 'bank')->count());

        try {
            app(FinancialAccountResolver::class)->default($branch->id, 'toman', 'bank');
            $this->fail('Expected domain error');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_corporate_null_branch_default_is_used_when_present(): void
    {
        $ledger = LedgerAccount::where('code', 'sys.bank.toman')->firstOrFail();
        $corp = FinancialAccount::create([
            'code' => 'corp.bank.toman',
            'branch_id' => null,
            'currency' => 'toman',
            'type' => 'bank',
            'name' => 'Corporate bank',
            'ledger_account_id' => $ledger->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $resolved = app(FinancialAccountResolver::class)->default(null, 'toman', 'bank');
        $this->assertTrue($corp->is($resolved));
    }

    public function test_posted_journal_monetary_fields_are_immutable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $entry = JournalEntry::latest('id')->firstOrFail();
        $this->expectException(DomainException::class);
        $entry->update(['memo' => 'mutated']);
    }

    public function test_journal_status_lifecycle_metadata_may_change(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $entry = JournalEntry::latest('id')->firstOrFail();
        $entry->update(['status' => 'reversed', 'reversed_at' => now()]);
        $this->assertSame('reversed', $entry->fresh()->status);
    }

    public function test_journal_line_updates_are_rejected(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $line = JournalLine::latest('id')->firstOrFail();
        $this->expectException(DomainException::class);
        $line->update(['debit' => '0.01']);
    }

    public function test_versioning_rollback_guard_fails_when_version_greater_than_one(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $first = JournalEntry::latest('id')->firstOrFail();
        JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'v2',
            'source_type' => $first->source_type,
            'source_id' => $first->source_id,
            'event_type' => $first->event_type,
            'version' => 2,
            'currency' => $first->currency,
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        JournalSchemaGuard::assertCanRestoreSourceEventUnique();
    }

    public function test_http_settlement_preview_uses_v2_full_unit_cost(): void
    {
        $branch = $this->makeBranch();
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
                'quantity' => 10,
                'cost_price' => 100000,
                'selling_price' => 150000,
            ]],
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 150000]],
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?' . http_build_query([
            'supplier_id' => $supplier->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $branch->id,
            'currency' => 'toman',
        ]))->assertOk()->json();

        $this->assertEquals(200000, (float) $preview['total_payable']);
    }
}
