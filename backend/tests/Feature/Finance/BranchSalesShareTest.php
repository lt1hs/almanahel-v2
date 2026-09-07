<?php

namespace Tests\Feature\Finance;

use App\Models\BranchSalesShareRule;
use App\Models\BranchShareReturnAllocation;
use App\Models\InvoiceItemBranchShare;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group finance */
class BranchSalesShareTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Illuminate\Support\Facades\Cache::forget(\App\Support\BranchShareFlags::SETTING_KEY);
        parent::tearDown();
    }

    public function test_flag_off_creates_no_shares_and_keeps_profit_shape(): void
    {
        $this->assertTrue(Schema::hasTable('branch_sales_share_rules'));
        $this->assertTrue(Schema::hasTable('invoice_item_branch_shares'));
        $this->assertTrue(Schema::hasTable('branch_share_return_allocations'));
        $this->assertFalse((bool) config('almanahel.branch_sales_share_enabled'));

        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $this->assertSame(0, InvoiceItemBranchShare::count());
        $profit = $this->getJson('/api/branches/'.$branch->id.'/profit')->assertOk()->json();
        $this->assertArrayNotHasKey('branch_sales_share', $profit);
        $this->assertArrayHasKey('net_profit_toman', $profit);
        $this->assertJournalsBalanced();

        $this->postJson('/api/branch-sales-shares/preview', $this->sharePayload([$branch->id], '10.00', 'k-off'))
            ->assertForbidden()
            ->assertJsonPath('error', 'feature_disabled');
    }

    public function test_sale_discount_return_and_rate_change_are_historical(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true]);
        $t0 = Carbon::parse('2026-09-01 10:00:00');
        Carbon::setTestNow($t0);

        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 20, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $this->applyShare($this->sharePayload([$branch->id], '10.00', 'share-10', $t0->toIso8601String()));

        $full = $this->sell($branch->id, $book->id, 1, '100000');
        $this->assertSame('10000.00', Money::of(InvoiceItemBranchShare::where('invoice_item_id', $full['items'][0]['id'])->value('share_amount')));

        $discounted = $this->sell($branch->id, $book->id, 1, '100000', '10000');
        $discShare = InvoiceItemBranchShare::where('invoice_item_id', $discounted['items'][0]['id'])->first();
        $this->assertSame('90000.00', Money::of($discShare->net_sales_amount));
        $this->assertSame('9000.00', Money::of($discShare->share_amount));
        $this->assertSame(1000, (int) $discShare->rate_bps);

        $t1 = $t0->copy()->addHour();
        Carbon::setTestNow($t1);
        $this->applyShare($this->sharePayload([$branch->id], '15.00', 'share-15', $t1->toIso8601String()));

        $this->assertSame('9000.00', Money::of($discShare->fresh()->share_amount));
        $this->assertSame(1000, (int) $discShare->fresh()->rate_bps);

        $after = $this->sell($branch->id, $book->id, 1, '100000');
        $afterShare = InvoiceItemBranchShare::where('invoice_item_id', $after['items'][0]['id'])->first();
        $this->assertSame('15000.00', Money::of($afterShare->share_amount));
        $this->assertSame(1500, (int) $afterShare->rate_bps);

        $partialInvoice = $this->sell($branch->id, $book->id, 5, '100000');
        $partialShare = InvoiceItemBranchShare::where('invoice_item_id', $partialInvoice['items'][0]['id'])->first();
        $this->assertSame('75000.00', Money::of($partialShare->share_amount));

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $partialInvoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $partialInvoice['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();
        $reversed = BranchShareReturnAllocation::query()->where('sale_share_id', $partialShare->id)->get()->reduce(
            fn ($sum, $row) => Money::add($sum, $row->reversed_share_amount),
            '0.00'
        );
        $this->assertSame('30000.00', Money::of($reversed));

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $partialInvoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $partialInvoice['items'][0]['id'], 'quantity' => 3]],
        ])->assertCreated();
        $this->assertSame('75000.00', Money::of(BranchShareReturnAllocation::query()->where('sale_share_id', $partialShare->id)->get()->reduce(
            fn ($sum, $row) => Money::add($sum, $row->reversed_share_amount),
            '0.00'
        )));
        $this->assertSame('0.00', Money::sub($partialShare->fresh()->share_amount, BranchShareReturnAllocation::query()->where('sale_share_id', $partialShare->id)->get()->reduce(
            fn ($sum, $row) => Money::add($sum, $row->reversed_share_amount),
            '0.00'
        )));
    }

    public function test_price_and_cost_changes_do_not_rewrite_shares_and_currencies_stay_split(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00'));
        $tomanBranch = $this->makeBranch(['name' => 'قم', 'supports_toman' => true, 'supports_dinar' => false]);
        $dinarBranch = $this->makeBranch([
            'name' => 'نجف',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
            'supports_toman' => false,
            'supports_dinar' => true,
        ]);
        $this->actingAsRole('admin', $tomanBranch);
        $book = $this->makeBook();
        $this->makeInventory($tomanBranch, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->makeInventory($dinarBranch, $book, [
            'quantity' => 3,
            'price_toman' => 0,
            'price_dinar' => 2000,
            'cost_price_toman' => 0,
            'cost_price_dinar' => 1400,
        ]);

        $this->applyShare($this->sharePayload(null, '10.00', 'share-all', now()->subMinute()->toIso8601String(), 'all_branches'));

        $tomanSale = $this->sell($tomanBranch->id, $book->id, 1, '100000');
        $dinarSale = $this->postJson('/api/invoices', [
            'branch_id' => $dinarBranch->id,
            'payment_method' => 'cash',
            'currency' => 'dinar',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 2000]],
        ])->assertCreated()->json();

        $tomanShare = InvoiceItemBranchShare::where('invoice_item_id', $tomanSale['items'][0]['id'])->first();
        $dinarShare = InvoiceItemBranchShare::where('invoice_item_id', $dinarSale['items'][0]['id'])->first();
        $this->assertSame('10000.00', Money::of($tomanShare->share_amount));
        $this->assertSame('200.00', Money::of($dinarShare->share_amount));
        $this->assertSame('toman', $tomanShare->currency);
        $this->assertSame('dinar', $dinarShare->currency);

        \App\Models\Inventory::query()->where('branch_id', $tomanBranch->id)->update([
            'price_toman' => 999999,
            'cost_price_toman' => 1,
        ]);
        $this->assertSame('10000.00', Money::of($tomanShare->fresh()->share_amount));

        $summary = $this->getJson('/api/branch-sales-shares/summary')->assertOk()->json();
        $this->assertSame('10000.00', Money::of($summary['totals']['toman']['net_share']));
        $this->assertSame('200.00', Money::of($summary['totals']['dinar']['net_share']));
        $this->assertNotSame($summary['totals']['toman']['net_share'], $summary['totals']['dinar']['net_share']);

        $profit = $this->getJson('/api/branches/'.$tomanBranch->id.'/profit')->assertOk()->json();
        $this->assertArrayHasKey('net_profit_toman', $profit);
        $this->assertSame('managerial', $profit['branch_sales_share']['mode']);
        $this->assertTrue($profit['branch_sales_share']['official_net_profit_unchanged']);
        $this->assertSame('شاخص مدیریتی', $profit['branch_sales_share']['currencies']['toman']['remaining_profit_label']);
    }

    public function test_managerial_mode_does_not_change_journals_and_stays_balanced(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 4, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $off = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated()->json();
        $offLines = $this->lineCountForInvoice($off['id']);
        $offCodes = $this->accountCodesForInvoice($off['id']);

        config(['almanahel.branch_sales_share_enabled' => true]);
        Carbon::setTestNow(now());
        $this->applyShare($this->sharePayload([$branch->id], '10.00', 'share-j', now()->subMinute()->toIso8601String()));

        $on = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated()->json();

        $this->assertSame($offLines, $this->lineCountForInvoice($on['id']));
        $this->assertSame($offCodes, $this->accountCodesForInvoice($on['id']));
        $this->assertSame(1, InvoiceItemBranchShare::where('invoice_id', $on['id'])->count());
        $this->assertSame(0, InvoiceItemBranchShare::where('invoice_id', $off['id'])->count());
        $this->assertJournalsBalanced();
        $this->assertSame(0, LedgerAccount::query()->where('code', 'like', '%branch_commission%')->count());
        $this->assertSame(0, LedgerAccount::query()->where('code', 'like', '%branch_payable%')->count());
    }

    public function test_idempotency_overlap_roles_and_snapshot_replay(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00'));
        $branch = $this->makeBranch();
        $other = $this->makeBranch(['name' => 'دیگر', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 3, 'price_toman' => 100000, 'cost_price_toman' => 70000]);

        $payload = $this->sharePayload([$branch->id], '10.00', 'share-idemp', now()->toIso8601String());
        $preview = $this->postJson('/api/branch-sales-shares/preview', $payload)->assertOk()->json();
        $this->assertTrue($preview['past_sales_unchanged']);
        $first = $this->postJson('/api/branch-sales-shares', $payload + ['preview_hash' => $preview['preview_hash']])->assertCreated()->json();
        $dup = $this->postJson('/api/branch-sales-shares', $payload + ['preview_hash' => $preview['preview_hash']])->assertCreated()->json();
        $this->assertSame($first['id'], $dup['id']);
        $this->assertSame(1, BranchSalesShareRule::count());

        $this->postJson('/api/branch-sales-shares', $this->sharePayload([$branch->id], '11.00', 'share-idemp', now()->toIso8601String()) + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');

        $overlapPreview = $this->postJson('/api/branch-sales-shares/preview', $this->sharePayload(
            [$branch->id],
            '12.00',
            'share-overlap',
            now()->toIso8601String()
        ))->assertOk()->json();
        $this->assertTrue($overlapPreview['has_overlap']);
        $this->postJson('/api/branch-sales-shares', $this->sharePayload(
            [$branch->id],
            '12.00',
            'share-overlap',
            now()->toIso8601String()
        ) + ['preview_hash' => $overlapPreview['preview_hash']])->assertStatus(409);

        $sale = $this->sell($branch->id, $book->id, 1, '100000');
        $service = app(\App\Services\BranchShare\BranchSalesShareService::class);
        $service->snapshotSale(\App\Models\Invoice::find($sale['id']));
        $this->assertSame(1, InvoiceItemBranchShare::where('invoice_id', $sale['id'])->count());

        $this->actingAsRole('accountant', $branch);
        $this->getJson('/api/branch-sales-shares/history')->assertOk();
        $this->postJson('/api/branch-sales-shares/preview', $this->sharePayload([$branch->id], '20.00', 'acc', now()->toIso8601String()))
            ->assertForbidden();

        $this->actingAsRole('branch_manager', $branch);
        $this->getJson('/api/branches/'.$branch->id.'/sales-share')->assertOk();
        $this->getJson('/api/branches/'.$other->id.'/sales-share')->assertForbidden();
        $this->postJson('/api/branch-sales-shares/preview', $this->sharePayload([$branch->id], '20.00', 'bm', now()->toIso8601String()))
            ->assertForbidden();

        $this->actingAsRole('warehouse_staff', $branch);
        $this->getJson('/api/branch-sales-shares/rules')->assertForbidden();
        $this->assertJournalsBalanced();
    }

    public function test_effective_from_with_timezone_offset_is_current_in_utc(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true, 'app.timezone' => 'UTC']);
        $nowUtc = Carbon::parse('2026-09-07 16:00:00', 'UTC');
        Carbon::setTestNow($nowUtc);

        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $this->applyShare($this->sharePayload(
            [$branch->id],
            '10.00',
            'share-tz',
            '2026-09-07T19:00:00+03:00'
        ));

        $rule = BranchSalesShareRule::query()->first();
        $this->assertNotNull($rule);
        $this->assertTrue($rule->effective_from->equalTo($nowUtc), (string) $rule->effective_from);

        $this->getJson('/api/branch-sales-shares/rules')
            ->assertOk()
            ->assertJsonPath('branches.0.rate', '10.00');
    }

    public function test_later_rule_closes_previous_with_to_after_from(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true, 'app.timezone' => 'UTC']);
        $t0 = Carbon::parse('2026-09-07 10:00:00', 'UTC');
        Carbon::setTestNow($t0);
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $this->applyShare($this->sharePayload([$branch->id], '10.00', 'share-w1', $t0->toIso8601String()));

        $t1 = $t0->copy()->addHour();
        Carbon::setTestNow($t1);
        $this->applyShare($this->sharePayload([$branch->id], '12.00', 'share-w2', $t1->toIso8601String()));

        $rules = BranchSalesShareRule::query()->orderBy('id')->get();
        $this->assertCount(2, $rules);
        $this->assertTrue($rules[0]->effective_from->lt($rules[0]->effective_to));
        $this->assertTrue($rules[0]->effective_to->equalTo($t1));
        $this->assertNull($rules[1]->effective_to);
    }

    public function test_rule_cannot_have_effective_to_before_or_equal_from(): void
    {
        config(['almanahel.branch_sales_share_enabled' => true, 'app.timezone' => 'UTC']);
        $from = Carbon::parse('2026-09-07 12:00:00', 'UTC');
        Carbon::setTestNow($from);
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->applyShare($this->sharePayload([$branch->id], '10.00', 'share-invalid', $from->toIso8601String()));

        $rule = BranchSalesShareRule::query()->first();
        $this->assertNotNull($rule);

        try {
            $rule->effective_to = $from->copy()->subMinute();
            $rule->save();
            $this->fail('expected invalid window to be rejected');
        } catch (\App\Exceptions\DomainException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_rule_window', $e->context['error'] ?? null);
        }

        $rule->refresh();
        $this->assertNull($rule->effective_to);

        try {
            $rule->effective_to = $from->copy();
            $rule->save();
            $this->fail('expected equal window to be rejected');
        } catch (\App\Exceptions\DomainException $e) {
            $this->assertSame('invalid_rule_window', $e->context['error'] ?? null);
        }
    }

    public function test_admin_enables_flag_from_settings_endpoint(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->assertFalse(\App\Support\BranchShareFlags::enabled());

        $this->putJson('/api/branch-sales-shares/enabled', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('enabled', true);
        $this->assertTrue(\App\Support\BranchShareFlags::enabled());

        $this->getJson('/api/branch-sales-shares/rules')
            ->assertOk()
            ->assertJsonPath('branches.0.branch_id', $branch->id);

        $this->actingAsRole('accountant', $branch);
        $this->putJson('/api/branch-sales-shares/enabled', ['enabled' => false])->assertForbidden();
        $this->assertTrue(\App\Support\BranchShareFlags::enabled());
    }

    /**
     * @param  array<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function sharePayload($branchIds, string $rate, string $key, ?string $from = null, string $scope = 'selected_branches'): array
    {
        $payload = [
            'scope' => $branchIds === null ? 'all_branches' : $scope,
            'rate' => $rate,
            'effective_from' => $from ?? now()->toIso8601String(),
            'reason' => 'آزمایش سهم شعبه',
            'idempotency_key' => $key,
        ];
        if ($branchIds !== null) {
            $payload['branch_ids'] = $branchIds;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyShare(array $payload): array
    {
        $preview = $this->postJson('/api/branch-sales-shares/preview', $payload)->assertOk()->json();
        $this->assertFalse($preview['has_overlap']);

        return $this->postJson('/api/branch-sales-shares', $payload + ['preview_hash' => $preview['preview_hash']])
            ->assertCreated()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function sell(int $branchId, int $bookId, int $qty, string $price, string $discount = '0'): array
    {
        $item = [
            'book_id' => $bookId,
            'quantity' => $qty,
            'actual_price' => $price,
        ];
        if (Money::cmp($discount, '0') > 0) {
            $item['discount'] = $discount;
        }

        return $this->postJson('/api/invoices', [
            'branch_id' => $branchId,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [$item],
        ])->assertCreated()->json();
    }

    private function lineCountForInvoice(int $invoiceId): int
    {
        $entry = JournalEntry::query()
            ->where('source_type', \App\Models\Invoice::class)
            ->where('source_id', $invoiceId)
            ->first();

        return $entry ? $entry->lines()->count() : 0;
    }

    /**
     * @return list<int>
     */
    private function accountCodesForInvoice(int $invoiceId): array
    {
        $entry = JournalEntry::query()
            ->where('source_type', \App\Models\Invoice::class)
            ->where('source_id', $invoiceId)
            ->first();
        if (!$entry) {
            return [];
        }

        return $entry->lines()->orderBy('ledger_account_id')->pluck('ledger_account_id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertJournalsBalanced(): void
    {
        foreach (JournalEntry::query()->with('lines')->get() as $entry) {
            $debit = '0.00';
            $credit = '0.00';
            foreach ($entry->lines as $line) {
                $debit = Money::add($debit, $line->debit);
                $credit = Money::add($credit, $line->credit);
            }
            $this->assertSame(0, Money::cmp($debit, $credit), 'unbalanced journal '.$entry->id);
        }
    }
}
