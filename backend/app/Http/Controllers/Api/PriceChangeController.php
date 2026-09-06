<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Branch;
use App\Models\PriceChangeBatch;
use App\Models\StockLot;
use App\Models\SupplierAccount;
use App\Services\Pricing\PriceChangeService;
use App\Services\Pricing\SellingPriceService;
use App\Support\Authorization\BranchAccess;
use Illuminate\Http\Request;

class PriceChangeController extends Controller
{
    public function preview(Request $request, PriceChangeService $service)
    {
        $payload = $this->validated($request);

        return response()->json($service->preview($payload, $request->user()));
    }

    public function store(Request $request, PriceChangeService $service)
    {
        $payload = $this->validated($request, true);

        return response()->json($service->apply($payload, $request->user()), 201);
    }

    public function show(Request $request, PriceChangeBatch $priceChangeBatch)
    {
        BranchAccess::assertCanViewPriceHistory($request->user());
        $priceChangeBatch->load(['sellingRevisions.branch', 'consignmentRevisions.lots', 'creator:id,name']);

        return response()->json($priceChangeBatch);
    }

    public function current(Request $request, SellingPriceService $selling)
    {
        BranchAccess::assertCanViewPriceHistory($request->user());
        $validated = $request->validate([
            'book_id' => 'required|exists:books,id',
            'currency' => 'required|in:toman,dinar',
            'type' => 'required|in:selling_price,consignment_cost',
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
        ]);

        $user = $request->user();
        $visible = BranchAccess::visibleBranchIds($user);
        $branches = Branch::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible ?: [0]))
            ->orderBy('id')
            ->get();

        $bookId = (int) $validated['book_id'];
        $currency = $validated['currency'];
        $rows = [];
        $supplierId = null;
        if ($validated['type'] === 'consignment_cost' && !empty($validated['supplier_account_id'])) {
            $supplierId = SupplierAccount::query()->find($validated['supplier_account_id'])?->supplier_id;
        }

        foreach ($branches as $branch) {
            $current = $selling->current($bookId, (int) $branch->id, $currency);
            $lots = StockLot::query()
                ->sellable()
                ->where('book_id', $bookId)
                ->where('branch_id', $branch->id)
                ->where('currency', $currency)
                ->when($validated['type'] === 'consignment_cost', function ($q) use ($supplierId) {
                    $q->where('ownership_type', 'consignment');
                    if ($supplierId) {
                        $q->where(function ($inner) use ($supplierId) {
                            $inner->where('supplier_id', $supplierId)
                                ->orWhereHas('supplierAccount', fn ($sa) => $sa->where('supplier_id', $supplierId));
                        });
                    }
                })
                ->get();
            $consignmentCost = $lots->first(fn ($lot) => $lot->ownership_type === 'consignment');
            $rows[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'can_set_pricing' => (bool) $branch->can_set_pricing,
                'supports_toman' => (bool) $branch->supports_toman,
                'supports_dinar' => (bool) $branch->supports_dinar,
                'selling_price' => $current['price'],
                'price_version' => $current['version'],
                'consignment_cost' => $consignmentCost ? $consignmentCost->effectivePayableUnitCost() : null,
                'unsold_qty' => (int) $lots->sum('qty_available'),
                'reserved_qty' => (int) $lots->sum('qty_reserved'),
                'consignment_lots' => $lots->where('ownership_type', 'consignment')->count(),
            ];
        }

        return response()->json([
            'book_id' => $bookId,
            'currency' => $currency,
            'type' => $validated['type'],
            'branches' => $rows,
        ]);
    }

    public function history(Request $request, Book $book, SellingPriceService $selling)
    {
        BranchAccess::assertCanViewPriceHistory($request->user());
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($branchId) {
            BranchAccess::assertBranchAllowed($request->user(), $branchId);
        } elseif ($request->user()->role === 'branch_manager') {
            $branchId = $request->user()->branch_id ? (int) $request->user()->branch_id : null;
        }

        $consignment = \App\Models\ConsignmentCostRevision::query()
            ->where('book_id', $book->id)
            ->with(['branch:id,name', 'creator:id,name', 'lots'])
            ->orderByDesc('id')
            ->limit(200);
        if ($branchId) {
            $consignment->where('branch_id', $branchId);
        }
        if ($request->filled('currency')) {
            $consignment->where('currency', $request->get('currency'));
        }

        return response()->json([
            'selling' => $selling->history((int) $book->id, $branchId, $request->get('currency')),
            'consignment' => $consignment->get(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $apply = false): array
    {
        $rules = [
            'type' => 'required|in:selling_price,consignment_cost',
            'book_id' => 'required|exists:books,id',
            'currency' => 'required|in:toman,dinar',
            'scope' => 'required|in:all_branches,selected_branches',
            'branch_ids' => 'required_if:scope,selected_branches|array',
            'branch_ids.*' => 'integer',
            'reason' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:80',
            'effective_at' => 'nullable|date',
            'supplier_account_id' => 'required_if:type,consignment_cost|nullable|exists:supplier_accounts,id',
            'new_price' => 'required_if:type,selling_price|nullable|numeric',
            'new_cost' => 'required_if:type,consignment_cost|nullable|numeric',
        ];
        if ($apply) {
            $rules['preview_hash'] = 'required|string|size:64';
        }

        return $request->validate($rules);
    }
}
