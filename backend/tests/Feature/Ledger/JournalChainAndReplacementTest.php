<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\DomainException;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Ledger\FinancialPostingService;
use App\Services\Ledger\T9Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group ledger */
class JournalChainAndReplacementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_twice_edited_expense_preflight_passes_and_invalid_chains_fail(): void
    {
        $ctx = $this->expenseContext();
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
        $service = new FinancialPostingService();
        $v1 = $service->postExpense($ctx['expense'], $ctx['cash'], now());
        $ctx['expense']->update(['amount' => 120]);
        $v2 = $service->reverseAndReplaceExpense($ctx['expense'], $v1, $ctx['cash'], now());
        $ctx['expense']->update(['amount' => 140]);
        $service->reverseAndReplaceExpense($ctx['expense'], $v2, $ctx['cash'], now());

        $this->artisan('finance:t9-preflight')->assertSuccessful();
        $this->assertSame([], (new T9Preflight())->issues());

        JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'dup-active',
            'source_type' => Expense::class,
            'source_id' => $ctx['expense']->id,
            'event_type' => 'expense',
            'version' => 4,
            'currency' => 'toman',
            'status' => 'active',
        ]);
        $this->assertPreflightContains('multiple active non-reversal');
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('memo', 'dup-active')->delete();

        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $v1->id)->update(['status' => 'active']);
        $this->assertPreflightContains('target is not reversed');
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $v1->id)->update(['status' => 'reversed', 'reversed_at' => now()]);

        $r1 = JournalEntry::where('reverses_entry_id', $v1->id)->firstOrFail();
        $other = Expense::create([
            'branch_id' => $ctx['branch']->id,
            'amount' => 10,
            'currency' => 'toman',
            'category' => 'rent',
            'date' => now()->toDateString(),
        ]);
        $r1->forceFill(['source_id' => $other->id]);
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $r1->id)->update(['source_id' => $other->id]);
        $this->assertPreflightContains('source identity mismatch');
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $r1->id)->update(['source_id' => $ctx['expense']->id]);

        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $r1->id)->update(['currency' => 'dinar']);
        $this->assertPreflightContains('currency mismatch');
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('id', $r1->id)->update(['currency' => 'toman']);

        JournalEntry::create([
            'occurred_at' => now(),
            'memo' => 'gap',
            'source_type' => Expense::class,
            'source_id' => $ctx['expense']->id,
            'event_type' => 'expense',
            'version' => 6,
            'currency' => 'toman',
            'status' => 'reversed',
            'reversed_at' => now(),
        ]);
        $this->assertPreflightContains('journal version gap');
        \Illuminate\Support\Facades\DB::table('journal_entries')->where('memo', 'gap')->delete();
    }

    public function test_replacement_rejects_unrelated_skipped_active_and_double_reversal(): void
    {
        $a = $this->expenseContext();
        $b = $this->expenseContext($a['branch'], $a['cash']);
        $service = new FinancialPostingService();
        $v1a = $service->postExpense($a['expense'], $a['cash'], now());
        $v1b = $service->postExpense($b['expense'], $b['cash'], now());

        try {
            $service->postExpense($a['expense']->fresh(), $a['cash'], now(), replacement: true, supersedesEntryId: (int) $v1b->id);
            $this->fail('unrelated');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        try {
            $service->postExpense($a['expense']->fresh(), $a['cash'], now(), replacement: true, supersedesEntryId: (int) $v1a->id);
            $this->fail('active supersedes');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $service->reverse($v1a, $a['expense'], 'expense_reversal', now());
        $v2 = $service->postExpense($a['expense']->fresh(), $a['cash'], now(), replacement: true, supersedesEntryId: (int) $v1a->id);
        $service->reverse($v2, $a['expense'], 'expense_reversal', now());
        try {
            $service->postExpense($a['expense']->fresh(), $a['cash'], now(), replacement: true, supersedesEntryId: (int) $v1a->id);
            $this->fail('skipped version');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->expectException(DomainException::class);
        $service->reverse($v1a->fresh(), $a['expense'], 'expense_reversal', now());
    }

    public function test_reverse_rejects_different_source_and_double_reversal_leaves_no_extra_journal(): void
    {
        $ctx = $this->expenseContext();
        $service = new FinancialPostingService();
        $v1 = $service->postExpense($ctx['expense'], $ctx['cash'], now());
        $other = Expense::create([
            'branch_id' => $ctx['branch']->id,
            'amount' => 50,
            'currency' => 'toman',
            'category' => 'rent',
            'date' => now()->toDateString(),
        ]);
        $before = JournalEntry::count();
        try {
            $service->reverse($v1, $other, 'expense_reversal', now());
            $this->fail('source');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('active', $v1->fresh()->status);
    }

    private function assertPreflightContains(string $needle): void
    {
        $issues = (new T9Preflight())->issues();
        $this->assertTrue(
            collect($issues)->contains(fn ($i) => str_contains($i, $needle)),
            implode("\n", $issues)
        );
    }

    /** @return array<string, mixed> */
    private function expenseContext($branch = null, $cash = null): array
    {
        $branch = $branch ?: $this->makeBranch();
        $expense = Expense::create([
            'branch_id' => $branch->id,
            'amount' => 100,
            'currency' => 'toman',
            'category' => 'rent',
            'date' => now()->toDateString(),
        ]);
        if (!$cash) {
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
        }

        return compact('branch', 'expense', 'cash');
    }
}
