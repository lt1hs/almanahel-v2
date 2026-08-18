<?php

namespace Tests\Feature\Stock;

use App\Models\ConsignmentReceiptItem;
use App\Models\StockLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group stock */
class StockLotProvenanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_owned_and_consignment_lots_coexist_for_same_book(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 3,
            'currency' => 'toman',
            'cost_price' => 50000,
            'selling_price' => 100000,
        ])->assertCreated();

        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 4,
                'cost_price' => 60000,
                'selling_price' => 100000,
            ]],
        ])->assertCreated();

        $lots = StockLot::where('branch_id', $branch->id)->where('book_id', $book->id)->get();
        $this->assertEquals(2, $lots->count());
        $this->assertEquals(7, $lots->sum('qty_available'));
        $this->assertTrue($lots->contains(fn ($l) => $l->ownership_type === 'owned'));
        $this->assertTrue($lots->contains(fn ($l) => $l->ownership_type === 'consignment'));
    }

    public function test_transfer_preserves_receipt_provenance_and_sale_updates_origin_receipt(): void
    {
        $qom = $this->makeBranch(['name' => 'قم', 'city' => 'قم', 'type' => 'store']);
        $mashhad = $this->makeBranch(['name' => 'مشهد', 'city' => 'مشهد', 'type' => 'store']);
        $this->actingAsRole('admin', $qom);
        $book = $this->makeBook();
        $supplier = $this->makeSupplier();

        $this->postJson('/api/consignments', [
            'supplier_id' => $supplier->id,
            'branch_id' => $qom->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [[
                'book_id' => $book->id,
                'quantity' => 5,
                'cost_price' => 70000,
                'selling_price' => 120000,
            ]],
        ])->assertCreated();

        $receiptItem = ConsignmentReceiptItem::first();
        $this->assertNotNull($receiptItem);

        $transfer = $this->postJson('/api/transfers', [
            'from_branch_id' => $qom->id,
            'to_branch_id' => $mashhad->id,
            'items' => [['book_id' => $book->id, 'quantity' => 2]],
        ])->assertCreated()->json();

        $this->assertSame('shipped', $transfer['status']);
        $this->putJson('/api/transfers/' . $transfer['id'] . '/status', ['status' => 'received'])->assertOk();

        $destLot = StockLot::where('branch_id', $mashhad->id)->where('book_id', $book->id)->first();
        $this->assertNotNull($destLot);
        $this->assertEquals($receiptItem->id, $destLot->consignment_receipt_item_id);
        $this->assertEquals('consignment', $destLot->ownership_type);
        $this->assertEquals(2, $destLot->qty_available);

        $this->postJson('/api/invoices', [
            'branch_id' => $mashhad->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 120000]],
        ])->assertCreated();

        $this->assertEquals(1, $receiptItem->fresh()->quantity_sold);
        $this->assertEquals(1, $destLot->fresh()->qty_available);
    }

    public function test_two_suppliers_create_separate_lots(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $s1 = $this->makeSupplier(['name' => 'S1']);
        $s2 = $this->makeSupplier(['name' => 'S2']);

        foreach ([$s1, $s2] as $supplier) {
            $this->postJson('/api/consignments', [
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'currency' => 'toman',
                'received_at' => now()->toDateString(),
                'items' => [[
                    'book_id' => $book->id,
                    'quantity' => 2,
                    'cost_price' => 50000,
                    'selling_price' => 90000,
                ]],
            ])->assertCreated();
        }

        $lots = StockLot::where('book_id', $book->id)->get();
        $this->assertEquals(2, $lots->count());
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $lots->pluck('supplier_id')->all());
    }
}
