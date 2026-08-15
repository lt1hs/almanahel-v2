<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseLog;
use App\Models\Inventory;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\IntakePolicy;
use App\Support\StockMovementLogger;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = WarehouseLog::with(['book', 'user', 'relatedTransfer']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('book_id')) {
            $query->where('book_id', $request->book_id);
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);

        return response()->json($query->latest('log_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'         => 'required|exists:branches,id',
            'book_id'           => 'required|exists:books,id',
            'direction'         => 'required|in:in,out',
            'quantity'          => 'required|integer|min:1',
            'handler_name'      => 'required|string|max:255',
            'handler_phone'     => 'nullable|string|max:30',
            'reason'            => 'required|in:received_from_supplier,transferred_to_branch,returned_from_branch,adjustment,other',
            'related_transfer_id' => 'nullable|exists:transfers,id',
            'notes'             => 'nullable|string',
            'log_date'          => 'required|date',
        ]);

        if ($validated['direction'] === 'in') {
            $book = \App\Models\Book::find($validated['book_id']);
            if ($denied = IntakePolicy::assertIntakeAllowed(
                $request->user(),
                (int) $validated['branch_id'],
                (bool) ($book?->iraq_only)
            )) {
                return $denied;
            }
        }

        return DB::transaction(function () use ($request, $validated) {
            $inventory = Inventory::firstOrCreate(
                ['branch_id' => $validated['branch_id'], 'book_id' => $validated['book_id']],
                ['quantity' => 0, 'type' => 'owned']
            );
            $inventory = Inventory::where('id', $inventory->id)->lockForUpdate()->first();

            if ($validated['direction'] === 'out' && $inventory->quantity < $validated['quantity']) {
                return response()->json(['message' => 'موجودی کافی برای خروج از انبار وجود ندارد'], 422);
            }

            $log = WarehouseLog::create([
                ...$validated,
                'user_id' => $request->user()->id,
            ]);

            if ($validated['direction'] === 'in') {
                $inventory->increment('quantity', $validated['quantity']);
            } else {
                $inventory->decrement('quantity', $validated['quantity']);
            }

            ActivityLogger::record(
                'warehouse',
                'created',
                "ثبت تراکنش انبار — {$validated['direction']} ×{$validated['quantity']}",
                $log,
                [
                    'book_id' => $validated['book_id'],
                    'direction' => $validated['direction'],
                    'quantity' => $validated['quantity'],
                    'reason' => $validated['reason'],
                ],
                (int) $validated['branch_id'],
            );

            return response()->json($log->load(['book', 'user']), 201);
        });
    }

    public function updateLog(Request $request, WarehouseLog $warehouseLog)
    {
        if ($warehouseLog->related_transfer_id) {
            return response()->json(['message' => 'این تراکنش از انتقال سیستمی است و قابل ویرایش نیست'], 422);
        }

        $validated = $request->validate([
            'quantity'      => 'sometimes|integer|min:1',
            'handler_name'  => 'sometimes|required|string|max:255',
            'handler_phone' => 'nullable|string|max:30',
            'reason'        => 'sometimes|in:received_from_supplier,transferred_to_branch,returned_from_branch,adjustment,other',
            'notes'         => 'nullable|string',
            'log_date'      => 'sometimes|date',
        ]);

        return DB::transaction(function () use ($warehouseLog, $validated) {
            if (isset($validated['quantity']) && (int) $validated['quantity'] !== (int) $warehouseLog->quantity) {
                $inventory = Inventory::where('branch_id', $warehouseLog->branch_id)
                    ->where('book_id', $warehouseLog->book_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    return response()->json(['message' => 'موجودی یافت نشد'], 422);
                }

                $oldQty = (int) $warehouseLog->quantity;
                $newQty = (int) $validated['quantity'];
                $diff = $newQty - $oldQty;

                if ($warehouseLog->direction === 'out') {
                    if ($diff > 0 && $inventory->quantity < $diff) {
                        return response()->json(['message' => 'موجودی کافی برای افزایش خروج وجود ندارد'], 422);
                    }
                    $inventory->decrement('quantity', $diff);
                } else {
                    if ($diff < 0 && $inventory->quantity < abs($diff)) {
                        return response()->json(['message' => 'موجودی کافی برای کاهش ورود وجود ندارد'], 422);
                    }
                    $inventory->increment('quantity', $diff);
                }
            }

            $warehouseLog->update($validated);

            ActivityLogger::record(
                'warehouse',
                'updated',
                "ویرایش تراکنش انبار #{$warehouseLog->id}",
                $warehouseLog,
                [
                    'book_id' => $warehouseLog->book_id,
                    'quantity' => $warehouseLog->quantity,
                    'direction' => $warehouseLog->direction,
                ],
                (int) $warehouseLog->branch_id,
            );

            return response()->json($warehouseLog->fresh()->load(['book', 'user']));
        });
    }

    public function show(WarehouseLog $warehouseLog)
    {
        return response()->json($warehouseLog->load(['book', 'user', 'branch']));
    }

    public function inventory(Request $request, $branchId)
    {
        $user = $request->user();
        if (!in_array($user?->role, ['super_admin', 'admin'], true)) {
            $allowed = [];
            if ($user?->branch_id) {
                $allowed[] = (int) $user->branch_id;
            }
            foreach ($user->iraq_only_visible_branches ?? [] as $id) {
                $allowed[] = (int) $id;
            }
            if ($user?->role === 'warehouse_staff') {
                $allowed = array_merge(
                    $allowed,
                    \App\Models\Branch::where('type', 'warehouse')->pluck('id')->map(fn ($id) => (int) $id)->all()
                );
            }
            $allowed = array_values(array_unique(array_filter($allowed)));
            if (!in_array((int) $branchId, $allowed, true)) {
                abort(403, 'اجازه دسترسی به موجودی این شعبه را ندارید');
            }
        }

        $query = Inventory::query()
            ->where('branch_id', $branchId)
            ->where('quantity', '>', 0);

        if ($request->boolean('lite') || $request->filled('search')) {
            $query->select([
                'id', 'branch_id', 'book_id', 'supplier_id', 'quantity', 'type',
                'price_toman', 'price_dinar', 'cost_price_toman', 'cost_price_dinar',
            ])->with([
                'book:id,title,author,isbn',
                'supplier:id,name',
            ]);
        } else {
            $query->with(['book', 'supplier']);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->whereHas('book', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%");
            });
        }

        if ($request->filled('book_id')) {
            $query->where('book_id', $request->book_id);
        }

        $query->orderByDesc('quantity');

        if ($request->boolean('lite') || $request->filled('search')) {
            $rows = $query->limit(25)->get();
        } else {
            $rows = $query->get();
        }

        $this->fillMissingPricesFromSiblings($rows);

        return response()->json($rows);
    }

    /**
     * When a POS row has no sell price (common after Iran→Iraq transfer + empty dinar field),
     * copy the best available price from another branch of the same book for display/POS use.
     */
    private function fillMissingPricesFromSiblings($rows): void
    {
        $needBookIds = $rows
            ->filter(fn ($inv) => !(float) ($inv->price_toman ?? 0) && !(float) ($inv->price_dinar ?? 0))
            ->pluck('book_id')
            ->unique()
            ->values();

        if ($needBookIds->isEmpty()) {
            return;
        }

        $siblings = Inventory::query()
            ->whereIn('book_id', $needBookIds)
            ->where(function ($q) {
                $q->where('price_toman', '>', 0)->orWhere('price_dinar', '>', 0);
            })
            ->get(['book_id', 'price_toman', 'price_dinar', 'cost_price_toman', 'cost_price_dinar'])
            ->groupBy('book_id');

        foreach ($rows as $inv) {
            if ((float) ($inv->price_toman ?? 0) || (float) ($inv->price_dinar ?? 0)) {
                continue;
            }
            $donor = $siblings->get($inv->book_id)?->first();
            if (!$donor) {
                continue;
            }
            // In-memory only for list display — keep currencies separate (never copy toman into dinar)
            if ((float) ($donor->price_dinar ?? 0) > 0) {
                $inv->setAttribute('price_dinar', $donor->price_dinar);
            }
            if ((float) ($donor->price_toman ?? 0) > 0) {
                $inv->setAttribute('price_toman', $donor->price_toman);
            }
            if (!(float) ($inv->cost_price_toman ?? 0) && $donor->cost_price_toman) {
                $inv->setAttribute('cost_price_toman', $donor->cost_price_toman);
            }
            if (!(float) ($inv->cost_price_dinar ?? 0) && $donor->cost_price_dinar) {
                $inv->setAttribute('cost_price_dinar', $donor->cost_price_dinar);
            }
        }
    }

    public function stats(Request $request, $branchId)
    {
        $totalIn = WarehouseLog::where('branch_id', $branchId)
            ->where('direction', 'in')->sum('quantity');
        $totalOut = WarehouseLog::where('branch_id', $branchId)
            ->where('direction', 'out')->sum('quantity');
        $currentStock = Inventory::where('branch_id', $branchId)->sum('quantity');

        return response()->json([
            'total_in'     => $totalIn,
            'total_out'    => $totalOut,
            'current_stock'=> $currentStock,
        ]);
    }

    /** Cash purchase — add owned inventory with cost */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'book_id'          => 'required|exists:books,id',
            'quantity'         => 'required|integer|min:1',
            'currency'         => 'required|in:toman,dinar',
            'cost_price'       => 'required|numeric|min:0',
            'selling_price'    => 'required|numeric|min:0',
            'price_toman'      => 'nullable|numeric|min:0',
            'price_dinar'      => 'nullable|numeric|min:0',
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'handler_name'     => 'nullable|string|max:255',
            'notes'            => 'nullable|string',
            'log_date'         => 'nullable|date',
        ]);

        $book = \App\Models\Book::find($validated['book_id']);
        if ($denied = IntakePolicy::assertIntakeAllowed(
            $request->user(),
            (int) $validated['branch_id'],
            (bool) ($book?->iraq_only)
        )) {
            return $denied;
        }

        return DB::transaction(function () use ($request, $validated) {
            $inventory = Inventory::firstOrCreate(
                ['branch_id' => $validated['branch_id'], 'book_id' => $validated['book_id']],
                ['quantity' => 0, 'type' => 'owned']
            );

            $inventory->increment('quantity', $validated['quantity']);
            $inventory->update([
                'type' => 'owned',
                'supplier_id'      => $validated['supplier_id'] ?? $inventory->supplier_id,
                'price_toman'      => $validated['price_toman']
                    ?? ($validated['currency'] === 'toman' ? $validated['selling_price'] : $inventory->price_toman),
                'price_dinar'      => $validated['price_dinar']
                    ?? ($validated['currency'] === 'dinar' ? $validated['selling_price'] : $inventory->price_dinar),
                'cost_price_toman' => $validated['currency'] === 'toman' ? $validated['cost_price'] : $inventory->cost_price_toman,
                'cost_price_dinar' => $validated['currency'] === 'dinar' ? $validated['cost_price'] : $inventory->cost_price_dinar,
            ]);

            $log = WarehouseLog::create([
                'branch_id'    => $validated['branch_id'],
                'book_id'      => $validated['book_id'],
                'direction'    => 'in',
                'quantity'     => $validated['quantity'],
                'handler_name' => $validated['handler_name'] ?? $request->user()->name,
                'reason'       => 'received_from_supplier',
                'notes'        => $validated['notes'] ?? 'خرید نقدی (مالکیت دارالمناهل)',
                'log_date'     => $validated['log_date'] ?? now()->toDateString(),
                'user_id'      => $request->user()->id,
            ]);

            ActivityLogger::record(
                'inventory',
                'created',
                "خرید نقدی — کتاب #{$validated['book_id']} ×{$validated['quantity']}",
                $inventory,
                [
                    'book_id' => $validated['book_id'],
                    'quantity' => $validated['quantity'],
                    'currency' => $validated['currency'],
                    'cost_price' => $validated['cost_price'],
                    'selling_price' => $validated['selling_price'],
                    'warehouse_log_id' => $log->id,
                ],
                (int) $validated['branch_id'],
            );

            return response()->json([
                'inventory' => $inventory->load('book'),
                'log'       => $log->load('book'),
            ], 201);
        });
    }

    /**
     * Create or update branch inventory pricing without requiring a stock intake.
     * Used so Qom / Mashhad / Najaf sell prices can be stored even when qty is 0.
     */
    public function upsertPricing(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'book_id'          => 'required|exists:books,id',
            'type'             => 'nullable|in:consignment,owned',
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'price_toman'      => 'nullable|numeric|min:0',
            'price_dinar'      => 'nullable|numeric|min:0',
            'cost_price_toman' => 'nullable|numeric|min:0',
            'cost_price_dinar' => 'nullable|numeric|min:0',
            'quantity'         => 'nullable|integer|min:0',
        ]);

        $type = $validated['type'] ?? 'owned';
        $supplierId = array_key_exists('supplier_id', $validated)
            ? $validated['supplier_id']
            : null;

        if ($type === 'consignment' && empty($supplierId)) {
            return response()->json(['message' => 'تأمین‌کننده برای کتاب امانی الزامی است'], 422);
        }

        $inventory = Inventory::firstOrCreate(
            [
                'branch_id' => $validated['branch_id'],
                'book_id'   => $validated['book_id'],
            ],
            [
                'quantity' => 0,
                'type'     => $type,
            ]
        );

        $updates = [
            'type' => $type,
        ];

        if (array_key_exists('supplier_id', $validated)) {
            $updates['supplier_id'] = $supplierId;
        }
        if (array_key_exists('price_toman', $validated)) {
            $updates['price_toman'] = $validated['price_toman'];
        }
        if (array_key_exists('price_dinar', $validated)) {
            $updates['price_dinar'] = $validated['price_dinar'];
        }
        if (array_key_exists('cost_price_toman', $validated)) {
            $updates['cost_price_toman'] = $validated['cost_price_toman'];
        }
        if (array_key_exists('cost_price_dinar', $validated)) {
            $updates['cost_price_dinar'] = $validated['cost_price_dinar'];
        }
        if (array_key_exists('quantity', $validated)) {
            $updates['quantity'] = $validated['quantity'];
        }

        $inventory->update($updates);

        ActivityLogger::record(
            'inventory',
            'priced',
            "ثبت قیمت موجودی — کتاب #{$inventory->book_id}",
            $inventory,
            [
                'book_id' => $inventory->book_id,
                'type' => $inventory->type,
                'price_toman' => $inventory->price_toman,
                'price_dinar' => $inventory->price_dinar,
            ],
            (int) $inventory->branch_id,
        );

        return response()->json($inventory->fresh()->load(['book', 'supplier', 'branch']));
    }

    public function updateInventory(Request $request, Inventory $inventory)
    {
        $validated = $request->validate([
            'quantity'         => 'sometimes|integer|min:0',
            'type'             => 'sometimes|in:consignment,owned',
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'price_toman'      => 'nullable|numeric|min:0',
            'price_dinar'      => 'nullable|numeric|min:0',
            'cost_price_toman' => 'nullable|numeric|min:0',
            'cost_price_dinar' => 'nullable|numeric|min:0',
        ]);

        $type = $validated['type'] ?? $inventory->type;
        $supplierId = array_key_exists('supplier_id', $validated)
            ? $validated['supplier_id']
            : $inventory->supplier_id;

        if ($type === 'consignment' && empty($supplierId)) {
            return response()->json(['message' => 'تأمین‌کننده برای کتاب امانی الزامی است'], 422);
        }

        $inventory->update($validated);

        ActivityLogger::record(
            'inventory',
            'updated',
            "ویرایش موجودی — کتاب #{$inventory->book_id}",
            $inventory,
            [
                'book_id' => $inventory->book_id,
                'quantity' => $inventory->quantity,
                'type' => $inventory->type,
            ],
            (int) $inventory->branch_id,
        );

        return response()->json($inventory->load(['book', 'supplier', 'branch']));
    }
}
