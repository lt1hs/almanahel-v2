<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\Inventory;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use App\Support\StockMovementLogger;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Settlement\SnapshotPayable;
use App\Services\Stock\StockLotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftController extends Controller
{
    public function index(Request $request)
    {
        $query = Gift::query()
            ->select([
                'id', 'branch_id', 'book_id', 'user_id', 'supplier_id', 'supplier_account_id',
                'quantity', 'recipient_name', 'cost_value', 'currency',
                'is_consignment', 'accounting_status', 'gifted_at', 'reason',
            ])
            ->with([
                'book:id,title,author',
                'branch:id,name',
                'supplier:id,name',
            ])
            ->latest('gifted_at');

        $ids = BranchAccess::visibleBranchIds($request->user());
        if ($ids !== null) {
            if (!$ids) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('branch_id', $ids);
            }
        }

        if ($request->filled('branch_id')) {
            BranchAccess::assertBranchAllowed($request->user(), (int) $request->branch_id);
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('status')) {
            $query->where('accounting_status', $request->status);
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('recipient_name', 'like', $q)
                    ->orWhereHas('book', fn ($b) => $b->where('title', 'like', $q));
            });
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'book_id'          => 'required|exists:books,id',
            'quantity'         => 'required|integer|min:1',
            'recipient_name'   => 'required|string|max:255',
            'recipient_phone'  => 'nullable|string|max:30',
            'reason'           => 'nullable|string',
            'cost_value'       => 'nullable|numeric|min:0.01',
            'currency'         => 'required|in:toman,dinar',
            'is_consignment'   => 'boolean',
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'gifted_at'        => 'required|date',
        ]);

        BranchAccess::assertCanMutateInBranch($request->user(), (int) $validated['branch_id']);

        return DB::transaction(function () use ($request, $validated) {
            $inventory = Inventory::where('branch_id', $validated['branch_id'])
                ->where('book_id', $validated['book_id'])
                ->whereNull('superseded_by_inventory_id')
                ->lockForUpdate()
                ->first();

            if (!$inventory || $inventory->quantity < $validated['quantity']) {
                throw new DomainException('موجودی کافی برای اهدای این کتاب وجود ندارد');
            }

            $gift = Gift::create([
                'branch_id' => $validated['branch_id'],
                'book_id' => $validated['book_id'],
                'quantity' => $validated['quantity'],
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'cost_value' => 0,
                'currency' => $validated['currency'],
                'is_consignment' => false,
                'supplier_id' => null,
                'gifted_at' => $validated['gifted_at'],
                'user_id' => $request->user()->id,
                'accounting_status' => 'pending',
            ]);

            $lotService = app(StockLotService::class);
            $allocs = $lotService->allocateGift($gift);
            $cost = '0.00';
            $hasConsignment = false;
            $supplierIds = [];
            foreach ($allocs as $alloc) {
                $cost = Money::add($cost, Money::mul($alloc->unit_cost, $alloc->quantity));
                if ($alloc->ownership_type === 'consignment') {
                    $hasConsignment = true;
                    if ($alloc->supplier_id) {
                        $supplierIds[(int) $alloc->supplier_id] = true;
                    }
                }
            }
            $gift->update([
                'cost_value' => $cost,
                'is_consignment' => $hasConsignment,
                'supplier_id' => count($supplierIds) === 1 ? array_key_first($supplierIds) : null,
                'supplier_account_id' => $this->giftAccountId($allocs),
            ]);

            StockMovementLogger::log(
                (int) $validated['branch_id'],
                (int) $validated['book_id'],
                'out',
                (int) $validated['quantity'],
                'other',
                $request->user()->name,
                "هدیه به {$validated['recipient_name']}",
            );

            ActivityLogger::record(
                'gifts',
                'created',
                "هدیه به {$validated['recipient_name']} — کتاب #{$validated['book_id']} ×{$validated['quantity']}",
                $gift,
                [
                    'book_id' => $validated['book_id'],
                    'quantity' => $validated['quantity'],
                    'recipient_name' => $validated['recipient_name'],
                    'cost_value' => $gift->cost_value,
                    'currency' => $gift->currency,
                ],
                (int) $validated['branch_id'],
            );

            app(FinancePostingGateway::class)->gift($gift->fresh());
            $receiptIds = collect($allocs)
                ->pluck('stock_lot_id');
            $itemIds = \App\Models\StockLot::query()
                ->whereIn('id', $receiptIds->all() ?: [0])
                ->whereNotNull('consignment_receipt_item_id')
                ->pluck('consignment_receipt_item_id');
            $receipts = \App\Models\ConsignmentReceipt::query()
                ->whereHas('items', fn ($q) => $q->whereIn('id', $itemIds->all() ?: [0]))
                ->with('items')
                ->get();
            foreach ($receipts as $receipt) {
                app(SnapshotPayable::class)->syncReceipt($receipt);
            }

            return response()->json($gift->fresh()->load(['book', 'branch', 'supplier']), 201);
        });
    }

    public function show(Request $request, Gift $gift)
    {
        BranchAccess::assertBranchAllowed($request->user(), (int) $gift->branch_id);
        return response()->json($gift->load(['book', 'branch', 'supplier', 'user']));
    }

    public function updateStatus(Request $request, Gift $gift)
    {
        BranchAccess::assertCanMutateInBranch($request->user(), (int) $gift->branch_id);

        $validated = $request->validate([
            'accounting_status' => 'required|in:pending,settled',
        ]);

        if ($gift->accounting_status === $validated['accounting_status']) {
            return response()->json($gift->load(['book', 'branch', 'supplier']));
        }

        $gift->update($validated);
        // Consignment gift payable already recorded via receipt item quantity_sold at gift time.
        // Do not create synthetic empty settled receipts.

        ActivityLogger::record(
            'gifts',
            'status_changed',
            "وضعیت هدیه #{$gift->id} → {$validated['accounting_status']}",
            $gift,
            [
                'accounting_status' => $validated['accounting_status'],
                'recipient_name' => $gift->recipient_name,
                'book_id' => $gift->book_id,
            ],
            (int) $gift->branch_id,
        );

        return response()->json($gift->load(['book', 'branch', 'supplier']));
    }

    /** @param  list<\App\Models\GiftLotAllocation>  $allocs */
    private function giftAccountId(array $allocs): ?int
    {
        $ids = [];
        foreach ($allocs as $alloc) {
            if ($alloc->supplier_account_id) {
                $ids[(int) $alloc->supplier_account_id] = true;
            }
        }
        if (count($ids) === 1) {
            return array_key_first($ids);
        }

        return null;
    }
}
