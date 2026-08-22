<?php

namespace Tests\Feature\Finance;

use App\Models\Check;
use App\Models\ConsignmentReceipt;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Settlement;
use App\Models\StockLot;
use App\Services\Ledger\CheckLifecycle;
use App\Services\Settlement\EffectiveSettlement;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group t9
 * @group finance
 */
class T91CorrectnessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_check_transition_matrix_incoming_and_supplier(): void
    {
        $incomingAllowed = [['pending', 'cleared'], ['pending', 'bounced'], ['pending', 'pending'], ['cleared', 'cleared'], ['bounced', 'bounced']];
        $incomingRejected = [
            ['cleared', 'pending'], ['bounced', 'pending'], ['cleared', 'bounced'], ['bounced', 'cleared'],
            ['pending', 'cancelled'], ['cleared', 'cancelled'],
        ];
        foreach ($incomingAllowed as [$from, $to]) {
            CheckLifecycle::assertIncoming($from, $to);
            $this->addToAssertionCount(1);
        }
        foreach ($incomingRejected as [$from, $to]) {
            try {
                CheckLifecycle::assertIncoming($from, $to);
                $this->fail("incoming {$from}->{$to} should fail");
            } catch (\App\Exceptions\DomainException $e) {
                $this->assertSame(409, $e->status);
            }
        }

        $supplierAllowed = [
            ['pending', 'cleared'], ['pending', 'bounced'], ['pending', 'cancelled'],
            ['pending', 'pending'], ['cleared', 'cleared'], ['bounced', 'bounced'], ['cancelled', 'cancelled'],
        ];
        $supplierRejected = [
            ['cleared', 'pending'], ['bounced', 'pending'], ['cancelled', 'pending'],
            ['bounced', 'cleared'], ['cancelled', 'cleared'], ['cleared', 'bounced'], ['cleared', 'cancelled'],
            ['bounced', 'cancelled'], ['cancelled', 'bounced'],
        ];
        foreach ($supplierAllowed as [$from, $to]) {
            CheckLifecycle::assertSupplier($from, $to);
            $this->addToAssertionCount(1);
        }
        foreach ($supplierRejected as [$from, $to]) {
            try {
                CheckLifecycle::assertSupplier($from, $to);
                $this->fail("supplier {$from}->{$to} should fail");
            } catch (\App\Exceptions\DomainException $e) {
                $this->assertSame(409, $e->status);
            }
        }
    }

    public function test_sale_pending_check_bounce_reopens_payable(): void
    {
        $ctx = $this->sold(2);
        $receipt = ConsignmentReceipt::first();
        $this->assertSame('unsettled', $receipt->status);

        $settlement = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 200000, 'check'))
            ->assertCreated()
            ->json();
        $this->assertSame('pending', $settlement['check_status']);
        $this->assertNotNull(Settlement::find($settlement['id'])->paid_at);
        $this->assertTrue(EffectiveSettlement::isEffective(Settlement::find($settlement['id'])));
        $this->assertSame('settled', $receipt->fresh()->status);

        $this->putJson('/api/consignments/settlements/'.$settlement['id'].'/check', ['check_status' => 'bounced'])
            ->assertOk();
        $row = Settlement::find($settlement['id']);
        $this->assertNotNull($row->bounced_at);
        $this->assertFalse(EffectiveSettlement::isEffective($row));
        $this->assertSame('unsettled', $receipt->fresh()->status);
        $this->assertTrue(Money::isZero($receipt->fresh()->settled_amount));

        $preview = $this->preview($ctx);
        $this->assertEquals(200000, (float) $preview['total_payable']);
        $this->assertSame(1, JournalEntry::where('event_type', 'supplier_check_bounced')->count());
        $this->assertSame('reversed', JournalEntry::where('event_type', 'settlement')->value('status'));
    }

    public function test_sale_pending_check_clear_credits_bank(): void
    {
        $ctx = $this->sold(2);
        $settlement = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 200000, 'check'))
            ->assertCreated()
            ->json();
        $bank = FinancialAccount::query()
            ->where('branch_id', $ctx['branch']->id)
            ->where('type', 'bank')
            ->where('currency', 'toman')
            ->where('is_default', true)
            ->firstOrFail();
        $before = $this->accountNet($bank->id);

        $this->putJson('/api/consignments/settlements/'.$settlement['id'].'/check', ['check_status' => 'cleared'])
            ->assertOk();
        $this->assertNotNull(Settlement::find($settlement['id'])->cleared_at);
        $this->assertTrue(Money::cmp($this->accountNet($bank->id), $before) < 0);
        $this->assertSame(1, JournalEntry::where('event_type', 'supplier_check_cleared')->count());
        $this->assertSame('settled', ConsignmentReceipt::first()->fresh()->status);
    }

    public function test_rejected_cleared_and_bounced_to_pending(): void
    {
        $ctx = $this->sold(2);
        $cleared = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 100000, 'check'))->assertCreated()->json('id');
        $this->putJson('/api/consignments/settlements/'.$cleared.'/check', ['check_status' => 'cleared'])->assertOk();
        $this->putJson('/api/consignments/settlements/'.$cleared.'/check', ['check_status' => 'pending'])->assertStatus(409);

        $bounced = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 100000, 'check'))->assertCreated()->json('id');
        $this->putJson('/api/consignments/settlements/'.$bounced.'/check', ['check_status' => 'bounced'])->assertOk();
        $this->putJson('/api/consignments/settlements/'.$bounced.'/check', ['check_status' => 'pending'])->assertStatus(409);
    }

    public function test_bounced_settlement_then_new_valid_settlement(): void
    {
        $ctx = $this->sold(2);
        $first = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 200000, 'check'))->assertCreated()->json('id');
        $this->putJson('/api/consignments/settlements/'.$first.'/check', ['check_status' => 'bounced'])->assertOk();
        $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 200000, 'cash'))->assertCreated();
        $this->assertSame('settled', ConsignmentReceipt::first()->fresh()->status);
    }

    public function test_return_after_pending_check_then_bounce_fails_closed(): void
    {
        $ctx = $this->sold(2);
        $id = $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 200000, 'check'))->assertCreated()->json('id');
        $invoice = Invoice::first();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoice->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $invoice->items()->first()->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->putJson('/api/consignments/settlements/'.$id.'/check', ['check_status' => 'bounced'])->assertStatus(409);
        $this->putJson('/api/consignments/settlements/'.$id.'/check', ['check_status' => 'cancelled'])->assertStatus(409);
        $this->assertSame('pending', Settlement::find($id)->check_status);
    }

    public function test_partial_customer_payment_after_return_and_idempotency_conflict(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 5, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $customer = Customer::create(['name' => 'علی', 'phone' => '0912', 'branch_id' => $branch->id]);
        $invoice = $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => 'علی',
            'payment_method' => 'credit',
            'currency' => 'toman',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 2, 'actual_price' => 100000]],
        ])->assertCreated();
        $this->postJson('/api/returns/customer', [
            'invoice_id' => $invoice->json('id'),
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => Invoice::find($invoice->json('id'))->items()->first()->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'amount' => 50000,
            'currency' => 'toman',
            'method' => 'cash',
            'invoice_id' => $invoice->json('id'),
            'idempotency_key' => 'pay-a',
        ])->assertCreated();
        $this->assertSame('partially_paid', Invoice::find($invoice->json('id'))->payment_status);

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'amount' => 40000,
            'currency' => 'toman',
            'method' => 'cash',
            'invoice_id' => $invoice->json('id'),
            'idempotency_key' => 'pay-a',
        ])->assertStatus(409);

        $this->postJson('/api/customers/'.$customer->id.'/payments', [
            'amount' => 50000,
            'currency' => 'toman',
            'method' => 'check',
            'invoice_id' => $invoice->json('id'),
        ])->assertStatus(422);
    }

    public function test_resolved_default_corporate_account_rejected_for_branch_user(): void
    {
        $branch = $this->makeBranch();
        $user = $this->actingAsRole('branch_manager', $branch);
        $this->expectException(\App\Exceptions\DomainException::class);
        app(\App\Services\Treasury\FinancialAccountResolver::class)->requireFor(
            'settlement',
            'cash',
            null,
            'toman',
            null,
            $user
        );
    }

    public function test_unauthorized_cross_branch_check_and_default_account(): void
    {
        $a = $this->makeBranch(['name' => 'A']);
        $b = $this->makeBranch(['name' => 'B', 'city' => 'مشهد']);
        $this->actingAsRole('admin', $a);
        $book = $this->makeBook();
        $this->makeInventory($a, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $sale = $this->postJson('/api/invoices', [
            'branch_id' => $a->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'C-1',
            'bank_name' => 'ملی',
            'payer_name' => 'مشتری',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ]);
        $sale->assertCreated();
        $check = Check::first();

        $this->actingAsRole('branch_manager', $b);
        $this->putJson('/api/checks/'.$check->id, ['status' => 'cleared'])->assertForbidden();

        $this->actingAsRole('warehouse_staff', $a);
        $this->putJson('/api/checks/'.$check->id, ['status' => 'cleared'])->assertForbidden();

        $this->actingAsRole('branch_manager', $a);
        $corp = FinancialAccount::query()->whereNull('branch_id')->where('type', 'bank')->where('currency', 'toman')->where('is_default', true)->firstOrFail();
        $this->putJson('/api/checks/'.$check->id, [
            'status' => 'cleared',
            'financial_account_id' => $corp->id,
        ])->assertForbidden();
    }

    public function test_multi_supplier_owned_and_consignment_gift(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $s1 = $this->makeSupplier(['name' => 'S1']);
        $s2 = $this->makeSupplier(['name' => 'S2']);
        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'currency' => 'toman',
            'cost_price' => 40000,
            'selling_price' => 90000,
        ])->assertCreated();
        $this->postJson('/api/consignments', [
            'supplier_id' => $s1->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 50000, 'selling_price' => 90000]],
        ])->assertCreated();
        $this->postJson('/api/consignments', [
            'supplier_id' => $s2->id,
            'branch_id' => $branch->id,
            'currency' => 'toman',
            'received_at' => now()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'cost_price' => 60000, 'selling_price' => 90000]],
        ])->assertCreated();

        $this->postJson('/api/gifts', [
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 3,
            'recipient_name' => 'کتابخانه',
            'currency' => 'toman',
            'gifted_at' => now()->toDateString(),
        ])->assertCreated();

        $this->assertEquals(3, GiftLotAllocation::count());
        $this->assertNull(Gift::first()->supplier_id);
        $this->assertEquals(2, GiftLotAllocation::where('ownership_type', 'consignment')->distinct('supplier_id')->count('supplier_id'));
        $this->assertEquals(50000, (float) $this->preview(['supplier' => $s1, 'branch' => $branch])['total_payable']);
        $this->assertEquals(60000, (float) $this->preview(['supplier' => $s2, 'branch' => $branch])['total_payable']);
        $payableId = LedgerAccount::where('code', 'sys.supplier_payable.toman')->value('id');
        $payables = JournalLine::query()
            ->where('ledger_account_id', $payableId)
            ->where('credit', '>', 0)
            ->count();
        $this->assertGreaterThanOrEqual(2, $payables);
    }

    public function test_bulk_settlement_rolls_back_and_zero_rejected(): void
    {
        $ctx = $this->sold(2);
        $other = $this->makeSupplier(['name' => 'دیگر']);
        $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 0, 'cash'))->assertStatus(422);

        $before = Settlement::count();
        $this->postJson('/api/consignments/settle-bulk', [
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'payment_method' => 'cash',
            'settlements' => [
                [
                    'supplier_id' => $ctx['supplier']->id,
                    'amount' => 200000,
                    'currency' => 'toman',
                    'branch_id' => $ctx['branch']->id,
                ],
                [
                    'supplier_id' => $other->id,
                    'amount' => 100000,
                    'currency' => 'toman',
                    'branch_id' => $ctx['branch']->id,
                ],
            ],
        ])->assertStatus(422);
        $this->assertSame($before, Settlement::count());
    }

    public function test_receipt_status_matrix(): void
    {
        $ctx = $this->sold(2);
        $receipt = ConsignmentReceipt::first();
        $this->assertSame('unsettled', $receipt->status);

        $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 100000, 'cash'))->assertCreated();
        $this->assertSame('partially_settled', $receipt->fresh()->status);

        $this->postJson('/api/consignments/settle', $this->settlePayload($ctx, 100000, 'cash'))->assertCreated();
        $this->assertSame('settled', $receipt->fresh()->status);
    }

    public function test_incoming_check_idempotent_status_and_timestamps(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, ['quantity' => 2, 'price_toman' => 100000, 'cost_price_toman' => 70000]);
        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'check',
            'currency' => 'toman',
            'check_number' => 'IN-1',
            'bank_name' => 'ملت',
            'payer_name' => 'مشتری',
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();
        $check = Check::first();
        $this->putJson('/api/checks/'.$check->id, ['status' => 'cleared'])->assertOk();
        $first = $check->fresh()->cleared_at;
        $this->putJson('/api/checks/'.$check->id, ['status' => 'cleared'])->assertOk();
        $this->assertTrue($first->equalTo($check->fresh()->cleared_at));
        $this->assertSame(1, JournalEntry::where('event_type', 'check_cleared')->count());
        $this->putJson('/api/checks/'.$check->id, ['status' => 'bounced'])->assertStatus(409);
        $this->putJson('/api/checks/'.$check->id, ['status' => 'pending'])->assertStatus(409);
    }

    /**
     * @return array{branch: \App\Models\Branch, book: \App\Models\Book, supplier: \App\Models\Supplier}
     */
    private function sold(int $qty): array
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
            'items' => [['book_id' => $book->id, 'quantity' => $qty, 'actual_price' => 150000]],
        ])->assertCreated();

        return compact('branch', 'book', 'supplier');
    }

    private function settlePayload(array $ctx, mixed $amount, string $method): array
    {
        $payload = [
            'supplier_id' => $ctx['supplier']->id,
            'branch_id' => $ctx['branch']->id,
            'period_type' => 'custom',
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => $amount,
            'currency' => 'toman',
            'payment_method' => $method,
        ];
        if ($method === 'check') {
            $payload['check_number'] = 'SUP-'.uniqid();
            $payload['bank_name'] = 'ملی';
        }

        return $payload;
    }

    private function preview(array $ctx): array
    {
        return $this->getJson('/api/consignments/settlement-preview?'.http_build_query([
            'supplier_id' => $ctx['supplier']->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'branch_id' => $ctx['branch']->id,
            'currency' => 'toman',
        ]))->assertOk()->json();
    }

    private function accountNet(int $financialAccountId): string
    {
        $net = '0.00';
        foreach (JournalLine::where('financial_account_id', $financialAccountId)->get() as $line) {
            $net = Money::add($net, $line->debit);
            $net = Money::sub($net, $line->credit);
        }

        return $net;
    }
}
