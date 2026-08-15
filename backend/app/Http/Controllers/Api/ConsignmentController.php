<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\Inventory;
use App\Models\Settlement;
use App\Support\IntakePolicy;
use App\Support\StockMovementLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsignmentController extends Controller
{
    public function index(Request $request)
    {
        // Heal receipts left unsettled after books were cascade-deleted
        ConsignmentReceipt::closeOrphans();

        $query = ConsignmentReceipt::query()
            ->select([
                'id',
                'receipt_number',
                'supplier_id',
                'branch_id',
                'user_id',
                'status',
                'currency',
                'total_value',
                'settled_amount',
                'received_at',
                'created_at',
            ])
            ->with([
                'supplier:id,name,phone',
                'branch:id,name',
            ])
            ->withCount('items');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('receipt_number', 'like', $q)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $q));
            });
        }

        return response()->json($query->latest('received_at')->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'      => 'required|exists:suppliers,id',
            'branch_id'        => 'required|exists:branches,id',
            'currency'         => 'required|in:toman,dinar',
            'received_at'      => 'required|date',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.book_id'       => 'required|exists:books,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.cost_price'    => 'required|numeric|min:0',
            'items.*.selling_price' => 'required|numeric|min:0',
            'items.*.price_toman'   => 'nullable|numeric|min:0',
            'items.*.price_dinar'   => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated) {
        $iraqOnly = false;
        if (!empty($validated['items'])) {
            $firstBook = \App\Models\Book::find($validated['items'][0]['book_id']);
            $iraqOnly = (bool) ($firstBook?->iraq_only);
        }

        if ($denied = IntakePolicy::assertIntakeAllowed($request->user(), (int) $validated['branch_id'], $iraqOnly)) {
            return $denied;
        }

        $totalValue = 0;
        foreach ($validated['items'] as $item) {
            $totalValue += $item['cost_price'] * $item['quantity'];
        }

        $receipt = ConsignmentReceipt::create([
            'supplier_id'    => $validated['supplier_id'],
            'branch_id'      => $validated['branch_id'],
            'user_id'        => $request->user()->id,
            'receipt_number' => 'CR-' . strtoupper(Str::random(8)),
            'status'         => 'unsettled',
            'currency'       => $validated['currency'],
            'total_value'    => $totalValue,
            'settled_amount' => 0,
            'received_at'    => $validated['received_at'],
            'notes'          => $validated['notes'] ?? null,
        ]);

        foreach ($validated['items'] as $item) {
            ConsignmentReceiptItem::create([
                'consignment_receipt_id' => $receipt->id,
                'book_id'                => $item['book_id'],
                'quantity_received'      => $item['quantity'],
                'quantity_sold'          => 0,
                'quantity_returned'      => 0,
                'cost_price'             => $item['cost_price'],
                'selling_price'          => $item['selling_price'],
            ]);

            // Update or create inventory
            $inventory = Inventory::firstOrCreate(
                ['branch_id' => $validated['branch_id'], 'book_id' => $item['book_id']],
                ['quantity' => 0, 'type' => 'consignment', 'supplier_id' => $validated['supplier_id']]
            );

            $inventory->increment('quantity', $item['quantity']);
            $inventory->update([
                'type'        => 'consignment',
                'supplier_id' => $validated['supplier_id'],
                'price_toman' => $item['price_toman']
                    ?? ($validated['currency'] === 'toman' ? $item['selling_price'] : $inventory->price_toman),
                'price_dinar' => $item['price_dinar']
                    ?? ($validated['currency'] === 'dinar' ? $item['selling_price'] : $inventory->price_dinar),
                'cost_price_toman' => $validated['currency'] === 'toman' ? $item['cost_price'] : $inventory->cost_price_toman,
                'cost_price_dinar' => $validated['currency'] === 'dinar' ? $item['cost_price'] : $inventory->cost_price_dinar,
            ]);

            StockMovementLogger::log(
                (int) $validated['branch_id'],
                (int) $item['book_id'],
                'in',
                (int) $item['quantity'],
                'received_from_supplier',
                $request->user()->name,
                "ورود امانی — رسید {$receipt->receipt_number}",
            );
        }

        return response()->json($receipt->load(['items.book', 'supplier', 'branch']), 201);
        });
    }

    public function show(ConsignmentReceipt $consignmentReceipt)
    {
        $consignmentReceipt->recalculateFromItems();

        return response()->json($consignmentReceipt->fresh()->load(['items.book', 'supplier', 'branch', 'user']));
    }

    /** Manually close a receipt that has no remaining items / nothing left to settle. */
    public function close(ConsignmentReceipt $consignmentReceipt)
    {
        $consignmentReceipt->load('items');

        $soldOutstanding = max(
            0,
            (float) $consignmentReceipt->items->sum(fn ($i) => $i->quantity_sold * $i->cost_price)
                - (float) $consignmentReceipt->settled_amount
        );

        if ($consignmentReceipt->items->isNotEmpty() && $soldOutstanding > 0) {
            return response()->json([
                'message' => 'این رسید هنوز مانده تسویه بر اساس فروش دارد. از صفحه تسویه حساب استفاده کنید.',
            ], 422);
        }

        $consignmentReceipt->forceFill([
            'status'         => 'settled',
            'settled_amount' => $consignmentReceipt->items->isEmpty()
                ? 0
                : (float) $consignmentReceipt->items->sum(fn ($i) => $i->quantity_sold * $i->cost_price),
            'total_value'    => $consignmentReceipt->items->isEmpty()
                ? 0
                : (float) $consignmentReceipt->items->sum(fn ($i) => $i->quantity_received * $i->cost_price),
        ])->save();

        return response()->json($consignmentReceipt->fresh()->load(['supplier', 'branch'])->loadCount('items'));
    }

    /** Sold-based outstanding balance per supplier */
    public function unsettledBySupplier(Request $request)
    {
        $receipts = ConsignmentReceipt::with(['items'])
            ->whereIn('status', ['unsettled', 'partially_settled'])
            ->get();

        $results = [];
        foreach ($receipts as $receipt) {
            $balance = $this->outstandingSoldBalance($receipt);
            if ($balance <= 0) {
                continue;
            }
            $sid = $receipt->supplier_id;
            $currency = $receipt->currency;
            if (!isset($results[$sid])) {
                $results[$sid] = [];
            }
            $merged = false;
            foreach ($results[$sid] as $idx => $entry) {
                if ($entry['currency'] === $currency) {
                    $results[$sid][$idx]['balance'] += $balance;
                    $merged = true;
                    break;
                }
            }
            if (!$merged) {
                $results[$sid][] = ['currency' => $currency, 'balance' => $balance];
            }
        }

        return response()->json($results);
    }

    private function soldValue(ConsignmentReceipt $receipt): float
    {
        $receipt->loadMissing('items');
        return (float) $receipt->items->sum(fn ($item) => $item->quantity_sold * $item->cost_price);
    }

    private function outstandingSoldBalance(ConsignmentReceipt $receipt): float
    {
        return max(0, $this->soldValue($receipt) - (float) $receipt->settled_amount);
    }

    /** Settle a supplier */
    public function settle(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'    => 'required|exists:suppliers,id',
            'branch_id'      => 'nullable|exists:branches,id',
            'period_type'    => 'required|in:monthly,quarterly,custom',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date|after_or_equal:period_start',
            'amount'         => 'required|numeric|min:0',
            'currency'       => 'required|in:toman,dinar',
            'payment_method' => 'required|in:cash,bank_transfer,check',
            'notes'          => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $receipts = ConsignmentReceipt::with('items')
                ->where('supplier_id', $validated['supplier_id'])
                ->where('currency', $validated['currency'])
                ->whereIn('status', ['unsettled', 'partially_settled'])
                ->whereBetween('received_at', [$validated['period_start'], $validated['period_end']])
                ->lockForUpdate()
                ->get();

            $settlement = Settlement::create([
                'supplier_id'       => $validated['supplier_id'],
                'branch_id'         => $validated['branch_id'] ?? null,
                'user_id'           => $request->user()->id,
                'settlement_number' => 'SET-' . strtoupper(Str::random(8)),
                'period_type'       => $validated['period_type'],
                'period_start'      => $validated['period_start'],
                'period_end'        => $validated['period_end'],
                'amount'            => $validated['amount'],
                'currency'          => $validated['currency'],
                'payment_method'    => $validated['payment_method'],
                'notes'             => $validated['notes'] ?? null,
            ]);

            $remaining = $validated['amount'];
            foreach ($receipts as $receipt) {
                $balance = $this->outstandingSoldBalance($receipt);
                if ($remaining <= 0 || $balance <= 0) {
                    continue;
                }
                $pay = min($balance, $remaining);
                $receipt->increment('settled_amount', $pay);
                $receipt->refresh();
                $soldValue = $this->soldValue($receipt);
                $newStatus = ($receipt->settled_amount >= $soldValue && $soldValue > 0)
                    ? 'settled'
                    : 'partially_settled';
                $receipt->update(['status' => $newStatus]);
                $remaining -= $pay;
            }

            return response()->json($settlement, 201);
        });
    }

    public function settlements(Request $request)
    {
        $query = Settlement::with(['supplier', 'branch', 'user']);
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        return response()->json($query->latest()->paginate(20));
    }

    /** Preview sold items for settlement calculation */
    public function settlementPreview(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'  => 'required|exists:suppliers,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'currency'     => 'nullable|in:toman,dinar',
        ]);

        $items = ConsignmentReceiptItem::with(['book', 'consignmentReceipt'])
            ->whereHas('consignmentReceipt', function ($q) use ($validated) {
                $q->where('supplier_id', $validated['supplier_id'])
                  ->whereBetween('received_at', [$validated['period_start'], $validated['period_end']]);
                if (!empty($validated['currency'])) {
                    $q->where('currency', $validated['currency']);
                }
            })
            ->where('quantity_sold', '>', 0)
            ->get()
            ->map(fn($item) => [
                'book_id'    => $item->book_id,
                'title'      => $item->book?->title,
                'qty_sold'   => $item->quantity_sold,
                'cost_price' => $item->cost_price,
                'total'      => $item->quantity_sold * $item->cost_price,
                'receipt'    => $item->consignmentReceipt?->receipt_number,
            ]);

        $totalPayable = $items->sum('total');

        return response()->json([
            'items'         => $items,
            'total_payable' => $totalPayable,
        ]);
    }

    /** Settle multiple suppliers at once */
    public function settleBulk(Request $request)
    {
        $validated = $request->validate([
            'settlements'                    => 'required|array|min:1',
            'settlements.*.supplier_id'      => 'required|exists:suppliers,id',
            'settlements.*.amount'           => 'required|numeric|min:0',
            'settlements.*.currency'         => 'required|in:toman,dinar',
            'period_type'                    => 'required|in:monthly,quarterly,custom',
            'period_start'                   => 'required|date',
            'period_end'                     => 'required|date|after_or_equal:period_start',
            'payment_method'                 => 'required|in:cash,bank_transfer,check',
            'notes'                          => 'nullable|string',
        ]);

        $results = [];
        foreach ($validated['settlements'] as $settlementData) {
            $subRequest = new Request([
                'supplier_id'    => $settlementData['supplier_id'],
                'period_type'    => $validated['period_type'],
                'period_start'   => $validated['period_start'],
                'period_end'     => $validated['period_end'],
                'amount'         => $settlementData['amount'],
                'currency'       => $settlementData['currency'],
                'payment_method' => $validated['payment_method'],
                'notes'          => $validated['notes'] ?? null,
            ]);
            $subRequest->setUserResolver(fn() => $request->user());
            $response = $this->settle($subRequest);
            $results[] = json_decode($response->getContent(), true);
        }

        return response()->json(['settlements' => $results], 201);
    }
}
