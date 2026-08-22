<?php

namespace Tests\Feature\Finance;

use App\Exceptions\DomainException;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\StockLot;
use App\Services\Treasury\FinancialAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class FinancialAccountDefaultTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_multiple_non_default_banks_allowed(): void
    {
        $branch = $this->makeBranch([], false);
        $this->makeBank($branch, 'a', false);
        $this->makeBank($branch, 'b', false);
        $this->assertSame(2, FinancialAccount::where('type', 'bank')->count());
        $this->expectException(DomainException::class);
        app(FinancialAccountResolver::class)->default($branch->id, 'toman', 'bank');
    }

    public function test_only_one_default_per_scope(): void
    {
        $branch = $this->makeBranch([], false);
        $this->makeBank($branch, 'a', true);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeBank($branch, 'b', true);
    }

    public function test_separate_defaults_for_toman_and_dinar(): void
    {
        $branch = $this->makeBranch([], false);
        $toman = $this->makeBank($branch, 't', true, 'toman');
        $dinar = $this->makeBank($branch, 'd', true, 'dinar');
        $resolver = app(FinancialAccountResolver::class);
        $this->assertTrue($toman->is($resolver->default($branch->id, 'toman', 'bank')));
        $this->assertTrue($dinar->is($resolver->default($branch->id, 'dinar', 'bank')));
    }

    public function test_separate_defaults_for_different_branches(): void
    {
        $a = $this->makeBranch(['name' => 'A'], false);
        $b = $this->makeBranch(['name' => 'B'], false);
        $acctA = $this->makeBank($a, 'a', true);
        $acctB = $this->makeBank($b, 'b', true);
        $resolver = app(FinancialAccountResolver::class);
        $this->assertTrue($acctA->is($resolver->default($a->id, 'toman', 'bank')));
        $this->assertTrue($acctB->is($resolver->default($b->id, 'toman', 'bank')));
    }

    public function test_corporate_null_branch_default(): void
    {
        $corp = $this->makeBank(null, 'corp', true);
        $resolved = app(FinancialAccountResolver::class)->default(null, 'toman', 'bank');
        $this->assertTrue($corp->is($resolved));
    }

    public function test_inactive_default_is_rejected(): void
    {
        $branch = $this->makeBranch([], false);
        $acct = $this->makeBank($branch, 'x', true);
        $acct->is_active = false;
        $acct->save();
        $this->assertFalse($acct->fresh()->is_default);
        $this->expectException(DomainException::class);
        app(FinancialAccountResolver::class)->default($branch->id, 'toman', 'bank');
    }

    public function test_missing_default_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        app(FinancialAccountResolver::class)->default(null, 'toman', 'cash_drawer');
    }

    private function makeBank(?\App\Models\Branch $branch, string $suffix, bool $default, string $currency = 'toman'): FinancialAccount
    {
        $ledger = LedgerAccount::where('code', 'sys.bank.' . $currency)->firstOrFail();

        return FinancialAccount::create([
            'code' => 'bank.' . $suffix . '.' . $currency . '.' . uniqid(),
            'branch_id' => $branch?->id,
            'currency' => $currency,
            'type' => 'bank',
            'name' => 'Bank ' . $suffix,
            'ledger_account_id' => $ledger->id,
            'is_default' => $default,
            'is_active' => true,
        ]);
    }
}
