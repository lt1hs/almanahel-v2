<?php

namespace Tests\Feature\Pricing;

use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\PriceChangeBatch;
use App\Models\SellingPriceRevision;
use App\Models\StockLot;
use App\Services\Pricing\PriceBackfill;
use App\Support\Money;
use App\Support\PriceFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group pricing */
class SellingPriceRevisionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    protected function tearDown(): void
    {
        Cache::forget(PriceFlags::SELLING_KEY);
        Cache::forget(PriceFlags::CONSIGNMENT_KEY);
        parent::tearDown();
    }

    public function test_schema_and_unit_cost_are_immutable(): void
    {
        $this->assertTrue(Schema::hasTable('price_change_batches'));
        $this->assertTrue(Schema::hasTable('selling_price_revisions'));
        $this->assertTrue(Schema::hasColumn('book_branch_prices', 'price_toman_version'));
        $this->assertTrue(Schema::hasColumn('stock_lots', 'payable_unit_cost'));
        $this->assertTrue(Schema::hasColumn('invoice_items', 'selling_price_version'));
        $this->assertFalse((bool) config('almanahel.selling_price_versioning_enabled'));
        $this->assertFalse((bool) config('almanahel.consignment_cost_revision_enabled'));

        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 100,
            'selling_price' => 150,
        ])->assertCreated();

        $lot = StockLot::query()->first();
        $this->expectException(\App\Exceptions\DomainException::class);
        $lot->unit_cost = 999;
        $lot->save();
    }

    public function test_selected_branch_selling_price_change_is_independent_and_historical(): void
    {
        config(['almanahel.selling_price_versioning_enabled' => true]);
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();

        foreach ([$a, $b] as $branch) {
            $this->postJson('/api/inventory/purchase', [
                'branch_id' => $branch->id,
                'book_id' => $book->id,
                'quantity' => 5,
                'currency' => 'toman',
                'cost_price' => 100,
                'selling_price' => 150,
            ])->assertCreated();
        }

        $version = app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $a->id, 'toman')['version'];
        $first = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 150,
                'expected_price_version' => $version,
            ]],
        ])->assertCreated()->json();
        $this->assertSame('150.00', Money::of($first['items'][0]['list_price'] ?? $first['items'][0]['actual_price']));

        $journalsBefore = JournalEntry::count();
        $key = 'sell-1';
        $preview = $this->postJson('/api/price-changes/preview', $this->sellingPayload($book->id, $a->id, 170, $key))->assertOk()->json();
        $apply = $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $a->id, 170, $key) + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertCreated()->json();
        $this->assertSame(PriceChangeBatch::STATUS_APPLIED, $apply['status']);
        $this->assertSame($journalsBefore, JournalEntry::count());

        $dup = $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $a->id, 170, $key) + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertCreated()->json();
        $this->assertSame($apply['id'], $dup['id']);
        $this->assertSame(1, SellingPriceRevision::query()->where('reason', 'افزایش قیمت تست')->count());

        $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $a->id, 171, $key) + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');

        $this->assertSame('150.00', Money::of(app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $b->id, 'toman')['price']));
        $lotCost = StockLot::query()->where('branch_id', $a->id)->value('unit_cost');
        $this->assertSame('100.00', Money::of($lotCost));

        $v2 = app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $a->id, 'toman');
        $this->assertSame('170.00', $v2['price']);
        $second = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 170,
                'expected_price_version' => $v2['version'],
            ]],
        ])->assertCreated()->json();

        $this->assertSame('150.00', Money::of(InvoiceItem::find($first['items'][0]['id'])->actual_price));
        $this->assertSame('170.00', Money::of(InvoiceItem::find($second['items'][0]['id'])->actual_price));

        $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 150,
                'expected_price_version' => $version,
            ]],
        ])->assertStatus(409)->assertJsonPath('error', 'price_changed');

        $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 100,
                'expected_price_version' => $v2['version'],
            ]],
        ])->assertStatus(422);

        $this->postJson('/api/price-changes/preview', $this->sellingPayload($book->id, $a->id, 180, 'sell-2', [$a->id, 99999]))
            ->assertOk();
        $stalePreview = $this->postJson('/api/price-changes/preview', $this->sellingPayload($book->id, $a->id, 180, 'sell-3'))->assertOk()->json();
        $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $a->id, 180, 'sell-stale') + [
            'preview_hash' => str_repeat('a', 64),
        ])->assertStatus(409)->assertJsonPath('error', 'preview_stale');
        $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $a->id, 180, 'sell-bad', [$a->id, 99999]) + [
            'preview_hash' => $stalePreview['preview_hash'],
        ])->assertStatus(422);
        $this->assertSame('170.00', Money::of(app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $a->id, 'toman')['price']));
    }

    public function test_toman_change_does_not_touch_dinar_and_flags_block_api(): void
    {
        $branch = $this->makeBranch(['supports_toman' => true, 'supports_dinar' => true]);
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 100,
            'selling_price' => 150,
            'price_dinar' => 2000,
        ])->assertCreated();

        $this->postJson('/api/price-changes/preview', $this->sellingPayload($book->id, $branch->id, 170, 'off'))
            ->assertStatus(403);

        config(['almanahel.selling_price_versioning_enabled' => true]);
        $preview = $this->postJson('/api/price-changes/preview', $this->sellingPayload($book->id, $branch->id, 170, 'on'))->assertOk()->json();
        $this->postJson('/api/price-changes', $this->sellingPayload($book->id, $branch->id, 170, 'on') + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertCreated();

        $svc = app(\App\Services\Pricing\SellingPriceService::class);
        $this->assertSame('170.00', $svc->current($book->id, $branch->id, 'toman')['price']);
        $this->assertSame('2000.00', $svc->current($book->id, $branch->id, 'dinar')['price']);
    }

    public function test_warehouse_staff_cannot_upsert_pricing(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('warehouse_staff', $branch);
        $book = $this->makeBook();
        $this->postJson('/api/inventory/upsert-pricing', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'price_toman' => 111,
        ])->assertForbidden();
    }

    public function test_admin_enables_selling_flag_from_api(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->assertFalse(PriceFlags::sellingVersioningEnabled());
        $this->putJson('/api/price-changes/flags', [
            'type' => 'selling_price',
            'enabled' => true,
        ])->assertOk()->assertJsonPath('selling_price_versioning_enabled', true);
        $this->assertTrue(PriceFlags::sellingVersioningEnabled());

        $this->actingAsRole('accountant', $branch);
        $this->putJson('/api/price-changes/flags', [
            'type' => 'selling_price',
            'enabled' => false,
        ])->assertForbidden();
    }

    public function test_branch_overrides_apply_different_selling_prices(): void
    {
        config(['almanahel.selling_price_versioning_enabled' => true]);
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();

        foreach ([$a, $b] as $branch) {
            $this->postJson('/api/inventory/purchase', [
                'branch_id' => $branch->id,
                'book_id' => $book->id,
                'quantity' => 3,
                'currency' => 'toman',
                'cost_price' => 100,
                'selling_price' => 150,
            ])->assertCreated();
        }

        $payload = $this->sellingPayload($book->id, $a->id, 170, 'sell-override', [$a->id, $b->id]) + [
            'branch_overrides' => [
                ['branch_id' => $b->id, 'new_price' => 200],
            ],
        ];
        $preview = $this->postJson('/api/price-changes/preview', $payload)->assertOk()->json();
        $byBranch = collect($preview['applied'])->keyBy('branch_id');
        $this->assertSame('170.00', Money::of($byBranch[$a->id]['new_price']));
        $this->assertSame('200.00', Money::of($byBranch[$b->id]['new_price']));

        $this->postJson('/api/price-changes', $payload + [
            'preview_hash' => $preview['preview_hash'],
        ])->assertCreated();

        $selling = app(\App\Services\Pricing\SellingPriceService::class);
        $this->assertSame('170.00', Money::of($selling->current($book->id, $a->id, 'toman')['price']));
        $this->assertSame('200.00', Money::of($selling->current($book->id, $b->id, 'toman')['price']));
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 150, 'price_dinar' => 0, 'cost_price_toman' => 100]);
        $before = SellingPriceRevision::count();
        $report = app(PriceBackfill::class)->run(false);
        $this->assertGreaterThan(0, $report['selling_would_create']);
        $this->assertSame($before, SellingPriceRevision::count());
        $this->artisan('prices:backfill')->assertSuccessful();
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function sellingPayload(int $bookId, int $branchId, int $price, string $key, ?array $branchIds = null): array
    {
        return [
            'type' => 'selling_price',
            'book_id' => $bookId,
            'currency' => 'toman',
            'new_price' => $price,
            'scope' => 'selected_branches',
            'branch_ids' => $branchIds ?? [$branchId],
            'reason' => 'افزایش قیمت تست',
            'idempotency_key' => $key,
        ];
    }
}
