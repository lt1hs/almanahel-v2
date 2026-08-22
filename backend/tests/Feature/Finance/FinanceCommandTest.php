<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAccount;
use App\Models\StockLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class FinanceCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_bootstrap_is_dry_run_by_default_and_apply_is_idempotent(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active'], false);
        $this->assertSame(0, FinancialAccount::count());

        $this->artisan('finance:bootstrap-accounts')
            ->assertSuccessful();
        $this->assertSame(0, FinancialAccount::count());

        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])
            ->assertSuccessful();
        $created = FinancialAccount::count();
        $this->assertGreaterThan(0, $created);
        $this->assertTrue(FinancialAccount::where('branch_id', $branch->id)->where('type', 'cash_drawer')->where('is_default', true)->exists());
        $this->assertTrue(FinancialAccount::where('branch_id', $branch->id)->where('type', 'checks_payable')->where('is_default', true)->exists());
        $this->assertTrue(FinancialAccount::whereNull('branch_id')->where('type', 'bank')->where('currency', 'toman')->where('is_default', true)->exists());
        $this->assertTrue(FinancialAccount::whereNull('branch_id')->where('type', 'checks_payable')->where('currency', 'toman')->where('is_default', true)->exists());

        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])
            ->assertSuccessful();
        $this->assertSame($created, FinancialAccount::count());
    }

    public function test_t9_preflight_fails_on_unstamped_and_passes_after_bootstrap_on_clean_db(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'status' => 'active'], false);
        $this->artisan('finance:t9-preflight')->assertFailed();

        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
        $this->artisan('finance:t9-preflight')->assertSuccessful();
        $this->artisan('finance:reports-preflight', ['--format' => 'json'])->assertSuccessful();
        $this->artisan('finance:reports-preflight', ['--format' => 'json'])->assertSuccessful();

        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        StockLot::create([
            'book_id' => $book->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'ownership_type' => 'consignment',
            'currency' => 'toman',
            'unit_cost' => 100,
            'qty_original' => 1,
            'qty_available' => 1,
            'payable_basis' => null,
            'payable_rate' => null,
        ]);
        $this->artisan('finance:t9-preflight')->assertFailed();
        $this->assertTrue(app(\App\Services\Finance\FinanceMode::class)->postingV2());
    }

    public function test_bootstrap_does_not_replace_existing_default(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true]);
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
        $original = FinancialAccount::where('branch_id', $branch->id)->where('type', 'cash_drawer')->where('is_default', true)->firstOrFail();
        $original->update(['name' => 'Keep me']);
        $this->artisan('finance:bootstrap-accounts', ['--apply' => true])->assertSuccessful();
        $this->assertSame('Keep me', $original->fresh()->name);
        $this->assertSame(1, FinancialAccount::where('branch_id', $branch->id)->where('type', 'cash_drawer')->where('is_default', true)->count());
    }
}
