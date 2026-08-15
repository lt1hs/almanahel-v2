<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::withCount(['consignmentReceipts', 'inventories', 'settlements'])
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', $q)
                    ->orWhere('city', 'like', $q)
                    ->orWhere('phone', 'like', $q)
                    ->orWhere('email', 'like', $q);
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:30',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city'    => 'nullable|string|max:100',
            'type'    => 'nullable|in:publisher,individual,company',
            'status'  => 'nullable|in:active,inactive',
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        $validated['type'] = $validated['type'] ?? 'publisher';

        $supplier = Supplier::create($validated);
        return response()->json($supplier->loadCount(['consignmentReceipts', 'inventories', 'settlements']), 201);
    }

    public function show(Supplier $supplier)
    {
        return response()->json($supplier->load(['consignmentReceipts.items.book']));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255',
            'phone'   => 'nullable|string|max:30',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city'    => 'nullable|string|max:100',
            'type'    => 'nullable|in:publisher,individual,company',
            'status'  => 'nullable|in:active,inactive',
        ]);
        $supplier->update($validated);
        return response()->json($supplier->fresh()->loadCount(['consignmentReceipts', 'inventories', 'settlements']));
    }

    public function destroy(Supplier $supplier)
    {
        $receipts = $supplier->consignmentReceipts()->count();
        $inventories = $supplier->inventories()->count();
        $settlements = $supplier->settlements()->count();

        if ($receipts > 0 || $inventories > 0 || $settlements > 0) {
            return response()->json([
                'message' => 'این تأمین‌کننده دارای سابقه امانی، موجودی یا تسویه است و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.',
                'can_deactivate' => true,
                'counts' => [
                    'consignment_receipts' => $receipts,
                    'inventories' => $inventories,
                    'settlements' => $settlements,
                ],
            ], 422);
        }

        $supplier->delete();
        return response()->json(['message' => 'تامین‌کننده با موفقیت حذف شد']);
    }

    /** Get unsettled balance per supplier */
    public function balance(Supplier $supplier)
    {
        $unsettled = \App\Models\ConsignmentReceipt::where('supplier_id', $supplier->id)
            ->whereIn('status', ['unsettled', 'partially_settled'])
            ->selectRaw('currency, SUM(total_value - settled_amount) as balance')
            ->groupBy('currency')
            ->get();

        return response()->json([
            'supplier' => $supplier,
            'unsettled_balances' => $unsettled,
        ]);
    }
}
