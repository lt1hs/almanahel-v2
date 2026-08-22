<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\ConsignmentReturn;
use App\Models\ConsignmentReturnItem;
use App\Models\ConsignmentReceiptItem;
use App\Models\Invoice;
use App\Models\Inventory;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use App\Support\StockMovementLogger;
use App\Services\Stock\StockLotService;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Receivables\InvoiceBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnController extends Controller
{
    private function scopeToUserBranch($query, $user, string $column = 'branch_id')
    {
        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids === null) {
            return $query;
        }
        if (!$ids) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereIn($column, $ids);
    }

    public function customerReturns(Request $request)
    {
        $query = CustomerReturn::with(['invoice', 'branch', 'user', 'items.book']);
        $this->scopeToUserBranch($query, $request->user());
        if ($request->has('branch_id')) {
            BranchAccess::assertBranchAllowed($request->user(), (int) $request->branch_id);
            $query->where('branch_id', $request->branch_id);
        }
        return response()->json($query->latest()->paginate(20));
    }

    public function createCustomerReturn(Request $request)
    {
        $validated = $request->validate([
            'invoice_id'    => 'required|exists:invoices,id',
            'refund_method' => 'required|in:cash,credit',
            'reason'        => 'nullable|string',
            'customer_id'   => 'nullable|exists:customers,id',
            'items'         => 'required|array|min:1',
            'items.*.invoice_item_id' => 'required|exists:invoice_items,id',
            'items.*.quantity'        => 'required|integer|min:1',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $invoice = Invoice::with('items')->lockForUpdate()->findOrFail($validated['invoice_id']);
            BranchAccess::assertCanMutateInBranch($request->user(), (int) $invoice->branch_id);
            Check::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();
            CustomerPayment::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();
            CustomerReturn::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();

            if (!empty($validated['customer_id'])) {
                $linked = $this->resolveReturnCustomer((int) $validated['customer_id'], (int) $invoice->branch_id);
                if ($invoice->customer_id && (int) $invoice->customer_id !== (int) $linked->id) {
                    throw new DomainException('فاکتور به مشتری دیگری وابسته است', 422);
                }
                if (!$invoice->customer_id) {
                    $invoice->customer_id = $linked->id;
                    if (!$invoice->customer_name) {
                        $invoice->customer_name = $linked->name;
                    }
                    if (!$invoice->customer_phone) {
                        $invoice->customer_phone = $linked->phone;
                    }
                    $invoice->save();
                }
            }

            $aggregated = [];
            foreach ($validated['items'] as $item) {
                $id = (int) $item['invoice_item_id'];
                $aggregated[$id] = ($aggregated[$id] ?? 0) + (int) $item['quantity'];
            }

            $refundAmount = '0.00';
            $prepared = [];

            foreach ($aggregated as $invoiceItemId => $qty) {
                $invoiceItem = $invoice->items->firstWhere('id', $invoiceItemId);
                if (!$invoiceItem) {
                    throw new DomainException('قلم مرجوعی متعلق به این فاکتور نیست', 422, [
                        'invoice_item_id' => $invoiceItemId,
                    ]);
                }

                $alreadyReturned = CustomerReturnItem::where('invoice_item_id', $invoiceItem->id)->lockForUpdate()->sum('quantity');
                $remaining = (int) $invoiceItem->quantity - (int) $alreadyReturned;
                if ($qty > $remaining) {
                    throw new DomainException('تعداد مرجوعی بیش از مقدار فروخته‌شده است', 422, [
                        'invoice_item_id' => $invoiceItem->id,
                        'remaining' => $remaining,
                    ]);
                }

                $unitNet = Money::max('0', Money::sub($invoiceItem->actual_price, $invoiceItem->discount));
                $lineRefund = Money::mul($unitNet, $qty);
                $refundAmount = Money::add($refundAmount, $lineRefund);

                $prepared[] = [
                    'invoice_item' => $invoiceItem,
                    'quantity' => $qty,
                    'unit_price' => $unitNet,
                ];
            }

            $split = app(InvoiceBalance::class)->planReturn($invoice, $refundAmount, $validated['refund_method']);
            if (Money::cmp($split['customer_credit_created'], '0') > 0) {
                $invoice->refresh();
                $customer = $invoice->customer_id
                    ? Customer::query()->lockForUpdate()->find($invoice->customer_id)
                    : null;
                if (!$customer || $customer->archived_at) {
                    throw new DomainException('ایجاد اعتبار مشتری نیازمند مشتری فعال ثبت‌شده است', 422);
                }
            }

            $return = CustomerReturn::create([
                'invoice_id'    => $invoice->id,
                'branch_id'     => $invoice->branch_id,
                'user_id'       => $request->user()->id,
                'return_number' => 'RET-' . strtoupper(Str::random(8)),
                'refund_amount' => $refundAmount,
                'refund_method' => $validated['refund_method'],
                'reason'        => $validated['reason'] ?? null,
                'returned_at'   => now(),
                'receivable_reduction' => $split['receivable_reduction'],
                'cash_refund' => $split['cash_refund'],
                'customer_credit_created' => $split['customer_credit_created'],
                'currency' => $split['currency'],
            ]);

            foreach ($prepared as $item) {
                $invoiceItem = $item['invoice_item'];
                $returnItem = CustomerReturnItem::create([
                    'customer_return_id' => $return->id,
                    'book_id'            => $invoiceItem->book_id,
                    'invoice_item_id'    => $invoiceItem->id,
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                ]);

                app(StockLotService::class)->reverseSaleAllocations(
                    $invoiceItem,
                    $item['quantity'],
                    $return,
                    $returnItem
                );

                StockMovementLogger::log(
                    (int) $invoice->branch_id,
                    (int) $invoiceItem->book_id,
                    'in',
                    $item['quantity'],
                    'returned_from_branch',
                    $request->user()->name,
                    "مرجوعی مشتری — {$return->return_number}",
                );
            }

            ActivityLogger::record(
                'returns',
                'created',
                "مرجوعی مشتری {$return->return_number}",
                $return,
                [
                    'return_number' => $return->return_number,
                    'invoice_id' => $invoice->id,
                    'refund_amount' => $refundAmount,
                    'refund_method' => $validated['refund_method'],
                    'items_count' => count($prepared),
                ],
                (int) $invoice->branch_id,
            );

            $cogs = '0.00';
            foreach ($prepared as $item) {
                foreach (\App\Models\SaleLotAllocation::where('invoice_item_id', $item['invoice_item']->id)->get() as $alloc) {
                    $cogs = Money::add($cogs, Money::mul($alloc->unit_cost, min($item['quantity'], (int) $alloc->quantity_returned)));
                }
            }
            app(FinancePostingGateway::class)->customerReturn(
                $return,
                $refundAmount,
                $cogs,
                $invoice->currency,
                (int) $invoice->branch_id,
                $validated['refund_method'],
                $validated['financial_account_id'] ?? null
            );

            $invoice = app(InvoiceBalance::class)->refresh($invoice);

            return response()->json($return->fresh()->load(['items.book', 'invoice']), 201);
        });
    }

    public function consignmentReturns(Request $request)
    {
        $query = ConsignmentReturn::with(['supplier', 'branch', 'user', 'items.book']);
        $this->scopeToUserBranch($query, $request->user());
        if ($request->has('branch_id')) {
            BranchAccess::assertBranchAllowed($request->user(), (int) $request->branch_id);
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        return response()->json($query->latest()->paginate(20));
    }

    public function createConsignmentReturn(Request $request)
    {
        $validated = $request->validate([
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
            'supplier_id' => 'required_without:supplier_account_id|nullable|exists:suppliers,id',
            'branch_id'   => 'required|exists:branches,id',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.book_id'    => 'required|exists:books,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.cost_price' => 'nullable|numeric|min:0',
        ]);

        BranchAccess::assertCanMutateInBranch($request->user(), (int) $validated['branch_id']);
        if ($request->boolean('aggregate')) {
            throw new DomainException('حالت تجمیعی برای ثبت مجاز نیست', 422, ['error' => 'aggregate_not_allowed']);
        }
        $resolved = app(\App\Services\Suppliers\SupplierAccountResolver::class)->resolveForMutation(
            (int) $validated['branch_id'],
            $validated['supplier_account_id'] ?? null,
            $validated['supplier_id'] ?? null,
            true
        );
        $validated['supplier_id'] = $resolved['supplier_id'];
        $validated['supplier_account_id'] = $resolved['account']->id;
        if (!$validated['supplier_id']) {
            throw new DomainException('مرجوعی امانی به تأمین‌کننده متعارف نیاز دارد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        return DB::transaction(function () use ($request, $validated) {
            $lotService = app(\App\Services\Stock\StockLotService::class);
            $prepared = [];

            foreach ($validated['items'] as $item) {
                $inventory = Inventory::where('branch_id', $validated['branch_id'])
                    ->where('book_id', $item['book_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$inventory || $inventory->quantity < $item['quantity']) {
                    throw new DomainException('موجودی کافی برای مرجوعی امانی وجود ندارد', 422, [
                        'book_id' => $item['book_id'],
                    ]);
                }
                $splits = $lotService->allocateConsignmentReturn(
                    (int) $validated['branch_id'],
                    (int) $validated['supplier_id'],
                    (int) $item['book_id'],
                    (int) $item['quantity'],
                );
                $cost = '0.00';
                $currency = $splits[0]['currency'] ?? 'toman';
                foreach ($splits as $split) {
                    $cost = Money::add($cost, $split['cost']);
                }
                $prepared[] = [
                    'book_id' => $item['book_id'],
                    'quantity' => $item['quantity'],
                    'cost_price' => Money::isZero($item['quantity']) ? '0' : bcdiv($cost, (string) $item['quantity'], 2),
                    'splits' => $splits,
                    'currency' => $currency,
                    'cost' => $cost,
                ];
            }

            $return = ConsignmentReturn::create([
                'supplier_id'   => $validated['supplier_id'],
                'supplier_account_id' => $validated['supplier_account_id'],
                'branch_id'     => $validated['branch_id'],
                'user_id'       => $request->user()->id,
                'return_number' => 'CRR-' . strtoupper(Str::random(8)),
                'reason'        => $validated['reason'] ?? null,
            ]);

            foreach ($prepared as $item) {
                ConsignmentReturnItem::create([
                    'consignment_return_id' => $return->id,
                    'book_id'               => $item['book_id'],
                    'quantity'              => $item['quantity'],
                    'cost_price'            => $item['cost_price'],
                ]);
                $createdItem = \App\Models\ConsignmentReturnItem::where('consignment_return_id', $return->id)
                    ->where('book_id', $item['book_id'])
                    ->latest('id')
                    ->first();
                $lotService->persistReturnSplits($createdItem->id, $item['splits']);

                StockMovementLogger::log(
                    (int) $validated['branch_id'],
                    (int) $item['book_id'],
                    'out',
                    (int) $item['quantity'],
                    'other',
                    $request->user()->name,
                    "مرجوعی امانی به ناشر — {$return->return_number}",
                );
            }

            ActivityLogger::record(
                'returns',
                'created',
                "مرجوعی امانی {$return->return_number}",
                $return,
                [
                    'return_number' => $return->return_number,
                    'supplier_id' => $validated['supplier_id'],
                    'items_count' => count($prepared),
                ],
                (int) $validated['branch_id'],
            );

            return response()->json($return->load(['items.book', 'supplier', 'branch']), 201);
        });
    }

    private function syncConsignmentReturned(int $supplierId, int $branchId, int $bookId, int $quantity): void
    {
        // Kept for legacy callers; lot service updates receipt returned qty directly.
        $remaining = $quantity;
        $items = ConsignmentReceiptItem::whereHas('consignmentReceipt', function ($q) use ($supplierId, $branchId) {
            $q->where('supplier_id', $supplierId)
              ->where('branch_id', $branchId)
              ->whereIn('status', ['unsettled', 'partially_settled']);
        })
            ->where('book_id', $bookId)
            ->orderByDesc('id')
            ->get();

        foreach ($items as $receiptItem) {
            if ($remaining <= 0) break;
            $returnable = $receiptItem->quantity_received - $receiptItem->quantity_sold - $receiptItem->quantity_returned;
            if ($returnable <= 0) continue;
            $returned = min($returnable, $remaining);
            $receiptItem->increment('quantity_returned', $returned);
            $remaining -= $returned;
        }
    }

    private function resolveReturnCustomer(int $customerId, int $branchId): Customer
    {
        $customer = Customer::query()->lockForUpdate()->find($customerId);
        if (!$customer) {
            throw new DomainException('مشتری یافت نشد', 422);
        }
        if ($customer->archived_at) {
            throw new DomainException('مشتری غیرفعال است', 422);
        }
        if ($customer->branch_id !== null && (int) $customer->branch_id !== $branchId) {
            throw new DomainException('این مشتری در شعبه فاکتور قابل استفاده نیست', 422);
        }

        return $customer;
    }
}
