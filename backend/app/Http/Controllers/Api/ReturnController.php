<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\ConsignmentReturn;
use App\Models\ConsignmentReturnItem;
use App\Models\ConsignmentReceiptItem;
use App\Models\Invoice;
use App\Models\Inventory;
use App\Support\ConsignmentSync;
use App\Support\StockMovementLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnController extends Controller
{
    public function customerReturns(Request $request)
    {
        $query = CustomerReturn::with(['invoice', 'branch', 'user', 'items.book']);
        if ($request->has('branch_id')) {
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
            'items'         => 'required|array|min:1',
            'items.*.book_id'         => 'required|exists:books,id',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.unit_price'      => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated) {
        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $refundAmount = collect($validated['items'])->sum(fn($i) => $i['unit_price'] * $i['quantity']);

        $return = CustomerReturn::create([
            'invoice_id'    => $invoice->id,
            'branch_id'     => $invoice->branch_id,
            'user_id'       => $request->user()->id,
            'return_number' => 'RET-' . strtoupper(Str::random(8)),
            'refund_amount' => $refundAmount,
            'refund_method' => $validated['refund_method'],
            'reason'        => $validated['reason'] ?? null,
        ]);

        foreach ($validated['items'] as $item) {
            CustomerReturnItem::create([
                'customer_return_id' => $return->id,
                'book_id'            => $item['book_id'],
                'invoice_item_id'    => $item['invoice_item_id'] ?? null,
                'quantity'           => $item['quantity'],
                'unit_price'         => $item['unit_price'],
            ]);

            $inventory = Inventory::where('branch_id', $invoice->branch_id)
                ->where('book_id', $item['book_id'])
                ->lockForUpdate()
                ->first();
            if ($inventory) {
                $inventory->increment('quantity', $item['quantity']);
                ConsignmentSync::decrementSold($inventory, $item['book_id'], $item['quantity']);

                StockMovementLogger::log(
                    (int) $invoice->branch_id,
                    (int) $item['book_id'],
                    'in',
                    (int) $item['quantity'],
                    'returned_from_branch',
                    $request->user()->name,
                    "مرجوعی مشتری — {$return->return_number}",
                );
            }
        }

        return response()->json($return->load(['items.book', 'invoice']), 201);
        });
    }

    public function consignmentReturns(Request $request)
    {
        $query = ConsignmentReturn::with(['supplier', 'branch', 'user', 'items.book']);
        if ($request->has('branch_id')) {
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
            'supplier_id' => 'required|exists:suppliers,id',
            'branch_id'   => 'required|exists:branches,id',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.book_id'    => 'required|exists:books,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.cost_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated) {
        foreach ($validated['items'] as $item) {
            $inventory = Inventory::where('branch_id', $validated['branch_id'])
                ->where('book_id', $item['book_id'])
                ->lockForUpdate()
                ->first();
            if (!$inventory || $inventory->quantity < $item['quantity']) {
                return response()->json([
                    'message' => 'موجودی کافی برای مرجوعی امانی وجود ندارد',
                    'book_id' => $item['book_id'],
                ], 422);
            }
        }

        $return = ConsignmentReturn::create([
            'supplier_id'   => $validated['supplier_id'],
            'branch_id'     => $validated['branch_id'],
            'user_id'       => $request->user()->id,
            'return_number' => 'CRR-' . strtoupper(Str::random(8)),
            'reason'        => $validated['reason'] ?? null,
        ]);

        foreach ($validated['items'] as $item) {
            ConsignmentReturnItem::create([
                'consignment_return_id' => $return->id,
                'book_id'               => $item['book_id'],
                'quantity'              => $item['quantity'],
                'cost_price'            => $item['cost_price'],
            ]);

            $inventory = Inventory::where('branch_id', $validated['branch_id'])
                ->where('book_id', $item['book_id'])
                ->first();
            if ($inventory) {
                $inventory->decrement('quantity', $item['quantity']);
            }

            $this->syncConsignmentReturned(
                $validated['supplier_id'],
                $validated['branch_id'],
                $item['book_id'],
                $item['quantity']
            );

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

        return response()->json($return->load(['items.book', 'supplier', 'branch']), 201);
        });
    }

    private function syncConsignmentReturned(int $supplierId, int $branchId, int $bookId, int $quantity): void
    {
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
}
