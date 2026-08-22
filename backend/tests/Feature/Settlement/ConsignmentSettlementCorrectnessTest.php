<?php

namespace Tests\Feature\Settlement;

use App\Models\ConsignmentReceipt;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Services\Suppliers\SupplierAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group finance
 * @group suppliers
 * @group gifts
 */
class ConsignmentSettlementCorrectnessTest extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    public function test_partial_settlement_keeps_receipt_visible_with_remaining_payable(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
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
            'items' => [['book_id' => $book->id, 'quantity' => 10, 'actual_price' => 15000]],
        ])->assertCreated();

        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
            'branch_id' => $branch->id,
        ]))->assertOk()->json();

        $this->assertSame(100000.0, (float) $preview['total_payable']);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '40000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $list = $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=all')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('partially_settled', $list[0]['payable_status']);
        $this->assertSame(60000.0, (float) $list[0]['remaining_payable']);

        $partial = $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=partially_settled')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $partial);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '20000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $after = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame('partially_settled', $after['payable_status']);
        $this->assertSame(40000.0, (float) $after['remaining_payable']);

        // New sale adds payable without hiding the row.
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
            'items' => [['book_id' => $book->id, 'quantity' => 3, 'actual_price' => 15000]],
        ])->assertCreated();

        // New sale may create a second receipt (unsettled) while the first stays partially_settled.
        // Account-level remaining payable must increase without hiding the partial row.
        $openRows = $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=all')
            ->assertOk()
            ->json('data');
        $this->assertTrue(
            collect($openRows)->contains(fn ($r) => $r['payable_status'] === 'partially_settled')
        );
        $totalRemaining = collect($openRows)
            ->filter(fn ($r) => in_array($r['payable_status'], ['unsettled', 'partially_settled'], true))
            ->sum(fn ($r) => (float) $r['remaining_payable']);
        $this->assertSame(70000.0, $totalRemaining);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '70000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $final = $this->getJson('/api/consignments?branch_id='.$branch->id.'&payable_status=settled')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($final);
        foreach ($final as $row) {
            $this->assertSame(0.0, (float) $row['remaining_payable']);
        }
    }

    public function test_consignment_gift_settled_via_consignment_settlement_updates_gift_status(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 5, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $gift = $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'recipient_name' => 'Test',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated()->json();

        $this->assertTrue((bool) $gift['is_consignment']);
        $this->assertSame('pending', $gift['accounting_status']);

        $preview = $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_account_id' => $account->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'toman',
            'branch_id' => $branch->id,
        ]))->assertOk()->json();

        $this->assertGreaterThan(0, (float) ($preview['breakdown']['gift_payable'] ?? 0));
        $this->assertSame(20000.0, (float) $preview['total_payable']);

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $preview['total_payable'],
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $giftRow = $this->getJson('/api/gifts?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame('settled', $giftRow['settlement_status']);
        $this->assertSame('settled', $giftRow['accounting_status']);
        $this->assertSame(0.0, (float) $giftRow['remaining_payable']);

        // Manual settle again must not double-pay.
        $this->putJson('/api/gifts/'.$gift['id'].'/status', [
            'accounting_status' => 'pending',
        ])->assertStatus(422);
    }

    public function test_partial_gift_settlement_shows_partially_settled(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
        $account = app(SupplierAccountResolver::class)->ensureForPair($branch->id, $supplier->id);

        $this->postJson('/api/consignments', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 4, 'cost_price' => 10000, 'selling_price' => 15000]],
        ])->assertCreated();

        $giftId = $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 4,
            'recipient_name' => 'Test',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated()->json('id');

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '15000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $giftRow = $this->getJson('/api/gifts?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame($giftId, $giftRow['id']);
        $this->assertSame('partially_settled', $giftRow['settlement_status']);
        $this->assertSame(25000.0, (float) $giftRow['remaining_payable']);

        $this->putJson('/api/gifts/'.$giftId.'/status', [
            'accounting_status' => 'settled',
        ])->assertStatus(422);
    }

    public function test_two_branches_same_canonical_supplier_settle_independently(): void
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
                'items' => [['book_id' => $book->id, 'quantity' => 2, 'cost_price' => 10000, 'selling_price' => 15000]],
            ])->assertCreated();
            $this->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'payment_method' => 'cash',
                'currency' => 'toman',
                'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 15000]],
            ])->assertCreated();
        }

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $accountA->id,
            'branch_id' => $a->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '10000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $rowA = $this->getJson('/api/consignments?branch_id='.$a->id)->assertOk()->json('data.0');
        $rowB = $this->getJson('/api/consignments?branch_id='.$b->id)->assertOk()->json('data.0');
        $this->assertSame('partially_settled', $rowA['payable_status']);
        $this->assertSame(10000.0, (float) $rowA['remaining_payable']);
        $this->assertSame('unsettled', $rowB['payable_status']);
        $this->assertSame(20000.0, (float) $rowB['remaining_payable']);
    }

    public function test_full_settle_then_new_sale_reopens_remaining_and_syncs_status(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();
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

        $this->postJson('/api/consignments/settle', [
            'supplier_account_id' => $account->id,
            'branch_id' => $branch->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => '20000.00',
            'currency' => 'toman',
            'payment_method' => 'cash',
        ])->assertCreated();

        $afterSettle = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame('settled', $afterSettle['payable_status']);
        $this->assertSame(0.0, (float) $afterSettle['remaining_payable']);
        $this->assertSame('settled', \App\Models\ConsignmentReceipt::query()->find($afterSettle['id'])->status);

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 15000]],
        ])->assertCreated();

        $afterSale = $this->getJson('/api/consignments?branch_id='.$branch->id)->assertOk()->json('data.0');
        $this->assertSame('partially_settled', $afterSale['payable_status']);
        $this->assertSame(10000.0, (float) $afterSale['remaining_payable']);
        $this->assertSame('partially_settled', \App\Models\ConsignmentReceipt::query()->find($afterSale['id'])->status);
    }
}
