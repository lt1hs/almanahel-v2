<?php

namespace Tests\Feature\Pricing;

use App\Models\GiftLotAllocation;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\SaleLotAllocation;
use App\Models\StockLot;
use App\Services\Suppliers\SupplierAccountResolver;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group pricing */
class ConsignmentCostRevisionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_consignment_cost_revision_does_not_rewrite_past_sales(): void
    {
        config([
            'almanahel.selling_price_versioning_enabled' => true,
            'almanahel.consignment_cost_revision_enabled' => true,
        ]);
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier(['name' => 'ناشر دیگر']);
        $accountA = app(SupplierAccountResolver::class)->ensureForPair($a->id, $supplier->id);
        $accountB = app(SupplierAccountResolver::class)->ensureForPair($b->id, $supplier->id);
        $accountOther = app(SupplierAccountResolver::class)->ensureForPair($a->id, $other->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $accountA->id,
            'branch_id' => $a->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 150]],
        ])->assertCreated();
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $accountB->id,
            'branch_id' => $b->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 4, 'cost_price' => 100, 'selling_price' => 150]],
        ])->assertCreated();
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $accountOther->id,
            'branch_id' => $a->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'cost_price' => 80, 'selling_price' => 150]],
        ])->assertCreated();
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $a->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'currency' => 'toman',
            'cost_price' => 90,
            'selling_price' => 150,
        ])->assertCreated();

        $version = app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $a->id, 'toman')['version'];
        $sale1 = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 2,
                'actual_price' => 150,
                'expected_price_version' => $version,
            ]],
        ])->assertCreated()->json();

        $firstAllocs = SaleLotAllocation::query()
            ->whereIn('invoice_item_id', collect($sale1['items'])->pluck('id'))
            ->get();
        $this->assertSame('200.00', Money::of($firstAllocs->sum(fn ($a) => (float) $a->publisher_payable)));

        $journalsBefore = JournalEntry::count();
        $payload = [
            'type' => 'consignment_cost',
            'book_id' => $book->id,
            'supplier_account_id' => $accountA->id,
            'currency' => 'toman',
            'new_cost' => 120,
            'scope' => 'selected_branches',
            'branch_ids' => [$a->id],
            'reason' => 'اعلام قیمت جدید ناشر',
            'idempotency_key' => 'cost-1',
        ];
        $preview = $this->postJson('/api/price-changes/preview', $payload)->assertOk()->json();
        $this->postJson('/api/price-changes', $payload + ['preview_hash' => $preview['preview_hash']])->assertCreated();
        $this->assertSame($journalsBefore, JournalEntry::count());

        $sell = app(\App\Services\Pricing\SellingPriceService::class)->current($book->id, $a->id, 'toman');
        $this->assertSame('150.00', $sell['price']);

        $lotA = StockLot::query()
            ->where('branch_id', $a->id)
            ->where('supplier_account_id', $accountA->id)
            ->first();
        $this->assertSame('100.00', Money::of($lotA->unit_cost));
        $this->assertSame('120.00', Money::of($lotA->payable_unit_cost));

        $lotB = StockLot::query()->where('branch_id', $b->id)->first();
        $this->assertSame('100.00', Money::of($lotB->payable_unit_cost ?? $lotB->unit_cost));
        $otherLot = StockLot::query()->where('supplier_account_id', $accountOther->id)->first();
        $this->assertSame('80.00', Money::of($otherLot->payable_unit_cost ?? $otherLot->unit_cost));
        $owned = StockLot::query()->where('ownership_type', 'owned')->first();
        $this->assertNull($owned->payable_unit_cost);

        $sale2 = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 3,
                'actual_price' => 150,
                'expected_price_version' => $sell['version'],
            ]],
        ])->assertCreated()->json();

        $secondAllocs = SaleLotAllocation::query()
            ->whereIn('invoice_item_id', collect($sale2['items'])->pluck('id'))
            ->get();
        $this->assertSame('360.00', Money::of($secondAllocs->sum(fn ($row) => (float) $row->publisher_payable)));
        $this->assertNotNull($secondAllocs->first()->consignment_cost_revision_id);

        $this->assertSame('200.00', Money::of($firstAllocs->fresh()->sum(fn ($row) => (float) $row->publisher_payable)));
        $total = (float) $firstAllocs->fresh()->sum(fn ($row) => (float) $row->publisher_payable)
            + (float) $secondAllocs->sum(fn ($row) => (float) $row->publisher_payable);
        $this->assertSame(560.0, $total);

        $this->postJson('/api/returns/customer', [
            'invoice_id' => $sale1['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $sale1['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame('200.00', Money::of(
            SaleLotAllocation::query()
                ->whereIn('invoice_item_id', collect($sale1['items'])->pluck('id'))
                ->get()
                ->sum(fn ($row) => (float) $row->publisher_payable)
        ));

        $sale3 = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 1,
                'actual_price' => 150,
                'expected_price_version' => $sell['version'],
            ]],
        ])->assertCreated()->json();
        $resaleAllocs = SaleLotAllocation::query()
            ->whereIn('invoice_item_id', collect($sale3['items'])->pluck('id'))
            ->get();
        $this->assertSame('120.00', Money::of($resaleAllocs->sum(fn ($row) => (float) $row->publisher_payable)));
        $this->assertNotNull($resaleAllocs->first()->consignment_cost_revision_id);

        $gift = $this->postJson('/api/gifts', [
            'branch_id' => $a->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'recipient_name' => 'Test',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated()->json();
        $giftAlloc = GiftLotAllocation::query()->where('gift_id', $gift['id'])->first();
        $this->assertNotNull($giftAlloc->consignment_cost_revision_id);
        $this->assertSame('120.00', Money::of($giftAlloc->unit_cost));
    }

    public function test_reserved_lots_block_consignment_cost_apply(): void
    {
        config(['almanahel.consignment_cost_revision_enabled' => true]);
        $from = $this->makeBranch(['name' => 'From', 'can_transfer' => true]);
        $to = $this->makeBranch(['name' => 'To', 'city' => 'مشهد', 'can_transfer' => true]);
        $this->actingAsRole('admin', $from);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($from->id, $supplier->id);
        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $from->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 100, 'selling_price' => 150]],
        ])->assertCreated();
        $this->postJson('/api/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
        ])->assertCreated();

        $payload = [
            'type' => 'consignment_cost',
            'book_id' => $book->id,
            'supplier_account_id' => $account->id,
            'currency' => 'toman',
            'new_cost' => 120,
            'scope' => 'selected_branches',
            'branch_ids' => [$from->id],
            'reason' => 'امانی',
            'idempotency_key' => 'reserved-1',
        ];
        $preview = $this->postJson('/api/price-changes/preview', $payload)->assertOk()->json();
        $this->postJson('/api/price-changes', $payload + ['preview_hash' => $preview['preview_hash']])
            ->assertStatus(409)
            ->assertJsonPath('error', 'lots_reserved');
    }

    public function test_branch_manager_cannot_change_consignment_cost(): void
    {
        config(['almanahel.consignment_cost_revision_enabled' => true]);
        $branch = $this->makeBranch();
        $this->actingAsRole('branch_manager', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);
        $this->postJson('/api/price-changes/preview', [
            'type' => 'consignment_cost',
            'book_id' => $book->id,
            'supplier_account_id' => $account->id,
            'currency' => 'toman',
            'new_cost' => 120,
            'scope' => 'selected_branches',
            'branch_ids' => [$branch->id],
            'reason' => 'x',
            'idempotency_key' => 'mgr',
        ])->assertForbidden();
    }
}
