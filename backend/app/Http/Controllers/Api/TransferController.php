<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\Inventory;
use App\Models\WarehouseLog;
use App\Support\ActivityLogger;
use App\Support\IntakePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $query = Transfer::with(['fromBranch', 'toBranch', 'user']);
        $this->scopeTransfersToUser($query, $request->user());

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->from_branch_id);
        }
        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->to_branch_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->latest()->paginate($perPage);

        $bookIds = collect($paginator->items())
            ->flatMap(fn (Transfer $t) => collect($t->lineItems())->pluck('book_id'))
            ->filter()
            ->unique()
            ->values();

        $books = $bookIds->isEmpty()
            ? collect()
            : Book::whereIn('id', $bookIds)->get(['id', 'title', 'author', 'isbn'])->keyBy('id');

        $paginator->getCollection()->transform(function (Transfer $transfer) use ($books) {
            $statusLog = $transfer->displayStatusLog();
            $items = collect($transfer->lineItems())->map(function ($item) use ($books) {
                $bookId = $item['book_id'] ?? null;
                $book = $bookId ? $books->get((int) $bookId) : null;
                return [
                    ...$item,
                    'book' => [
                        'id' => $bookId,
                        'title' => $book?->title,
                        'author' => $book?->author,
                        'isbn' => $book?->isbn,
                    ],
                ];
            })->values()->all();

            $transfer->setAttribute('items', $items);
            $transfer->setAttribute('status_log', $statusLog);
            return $transfer;
        });

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|exists:branches,id|different:to_branch_id',
            'to_branch_id'   => 'required|exists:branches,id',
            'notes'          => 'nullable|string',
            'items'          => 'required|array|min:1',
            'items.*.book_id'  => 'required|exists:books,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $user = $request->user();
            if ($denied = $this->assertTransferRouteAllowed($user, (int) $validated['from_branch_id'], (int) $validated['to_branch_id'])) {
                return $denied;
            }

            $aggregated = [];
            foreach ($validated['items'] as $item) {
                $bookId = (int) $item['book_id'];
                $aggregated[$bookId] = ($aggregated[$bookId] ?? 0) + (int) $item['quantity'];
            }
            $lineItems = [];
            foreach ($aggregated as $bookId => $qty) {
                $lineItems[] = ['book_id' => $bookId, 'quantity' => $qty];
            }

            foreach ($lineItems as $item) {
                $inv = Inventory::where('branch_id', $validated['from_branch_id'])
                    ->where('book_id', $item['book_id'])
                    ->whereNull('superseded_by_inventory_id')
                    ->lockForUpdate()
                    ->first();

                if (!$inv || $inv->quantity < $item['quantity']) {
                    throw new \App\Exceptions\DomainException('موجودی کافی در شعبه مبدأ وجود ندارد', 422, [
                        'book_id' => $item['book_id'],
                    ]);
                }
            }

            $items = array_values($lineItems);
            $items[] = ['_status_log' => [$this->statusEvent('shipped', $user)]];
            $transfer = Transfer::create([
                'from_branch_id' => $validated['from_branch_id'],
                'to_branch_id'   => $validated['to_branch_id'],
                'status'         => 'shipped',
                'user_id'        => $user->id,
                'items'          => $items,
            ]);

            $transfer->load(['toBranch', 'fromBranch']);

            $lotService = app(\App\Services\Stock\StockLotService::class);
            $lotService->reserveForTransfer($transfer, $lineItems);

            foreach ($lineItems as $item) {
                WarehouseLog::create([
                    'branch_id'           => $transfer->from_branch_id,
                    'book_id'             => $item['book_id'],
                    'direction'           => 'out',
                    'quantity'            => $item['quantity'],
                    'handler_name'        => $user->name,
                    'reason'              => 'transferred_to_branch',
                    'related_transfer_id' => $transfer->id,
                    'log_date'            => now()->toDateString(),
                    'user_id'             => $user->id,
                    'notes'               => $validated['notes']
                        ?? "ارسال به شعبه {$transfer->toBranch?->name} — در راه (منتظر دریافت مقصد)",
                ]);
            }

            $transfer->load(['fromBranch', 'toBranch', 'user']);
            $transfer->setAttribute('status_log', $transfer->statusLog());
            $transfer->setAttribute('items', $transfer->lineItems());

            ActivityLogger::record(
                'transfers',
                'created',
                "ایجاد انتقال #{$transfer->id} از {$transfer->fromBranch?->name} به {$transfer->toBranch?->name}",
                $transfer,
                [
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id' => $transfer->to_branch_id,
                    'items_count' => count($validated['items']),
                    'status' => $transfer->status,
                ],
                (int) $transfer->from_branch_id,
            );

            return response()->json($transfer, 201);
        });
    }

    public function updateStatus(Request $request, Transfer $transfer)
    {
        $validated = $request->validate([
            'status' => 'required|in:shipped,received,cancelled',
        ]);

        return DB::transaction(function () use ($request, $transfer, $validated) {
        $user = $request->user();
        $previousStatus = $transfer->status;
        $nextStatus = $validated['status'];
        $transfer->load(['toBranch', 'fromBranch', 'user']);

        $allowed = [
            'pending'   => ['shipped', 'received', 'cancelled'],
            'shipped'   => ['received', 'cancelled'],
            'received'  => [],
            'cancelled' => [],
        ];
        if (!in_array($nextStatus, $allowed[$previousStatus] ?? [], true)) {
            abort(422, 'این تغییر وضعیت برای این انتقال مجاز نیست');
        }

        if ($nextStatus === 'shipped' && !$this->canShip($user, $transfer)) {
            abort(403, 'فقط مبدأ یا انبار می‌تواند محموله را ارسال کند');
        }
        if ($nextStatus === 'received' && !$this->canReceive($user, $transfer)) {
            abort(403, 'فقط شعبه مقصد می‌تواند دریافت را تأیید کند');
        }
        if ($nextStatus === 'cancelled' && !$this->canShip($user, $transfer) && !$this->isAdmin($user)) {
            abort(403, 'اجازه لغو این انتقال را ندارید');
        }

        $log = collect($transfer->statusLog());
        if ($log->isEmpty()) {
            $log->push($this->statusEvent($previousStatus, $transfer->user ?? $user));
        }
        $log = $log->push($this->statusEvent($nextStatus, $user))->values()->all();
        $transfer->update([
            'status' => $nextStatus,
            'items' => $transfer->itemsWithStatusLog($log),
        ]);

        if ($nextStatus === 'shipped' && $previousStatus === 'pending') {
            WarehouseLog::where('related_transfer_id', $transfer->id)
                ->where('direction', 'out')
                ->update([
                    'notes' => "ارسال محموله به شعبه {$transfer->toBranch?->name} — در راه (توسط {$user->name})",
                ]);
        }

        if ($nextStatus === 'received' && $previousStatus !== 'received') {
            $lotService = app(\App\Services\Stock\StockLotService::class);
            foreach ($transfer->lineItems() as $item) {
                $srcInv = Inventory::where('branch_id', $transfer->from_branch_id)
                    ->where('book_id', $item['book_id'])
                    ->whereNull('superseded_by_inventory_id')
                    ->first();
                $destInv = $lotService->ensureAggregate(
                    (int) $transfer->to_branch_id,
                    (int) $item['book_id']
                );
                if ($srcInv) {
                    $lotService->setSellPrices($destInv, [
                        'price_toman' => $srcInv->price_toman ?? $destInv->price_toman,
                        'price_dinar' => $srcInv->price_dinar ?? $destInv->price_dinar,
                    ], 'transfer_price_copy', true);
                }
            }
            $lotService->receiveTransfer($transfer);
            foreach ($transfer->lineItems() as $item) {
                WarehouseLog::create([
                    'branch_id'           => $transfer->to_branch_id,
                    'book_id'             => $item['book_id'],
                    'direction'           => 'in',
                    'quantity'            => $item['quantity'],
                    'handler_name'        => $user->name,
                    'reason'              => 'transferred_to_branch',
                    'related_transfer_id' => $transfer->id,
                    'log_date'            => now()->toDateString(),
                    'user_id'             => $user->id,
                    'notes'               => "تأیید دریافت توسط {$user->name} از {$transfer->fromBranch?->name}",
                ]);
            }
        }

        if ($nextStatus === 'cancelled' && in_array($previousStatus, ['pending', 'shipped'], true)) {
            app(\App\Services\Stock\StockLotService::class)->cancelTransferReservation($transfer);
            foreach ($transfer->lineItems() as $item) {
                WarehouseLog::create([
                    'branch_id'           => $transfer->from_branch_id,
                    'book_id'             => $item['book_id'],
                    'direction'           => 'in',
                    'quantity'            => $item['quantity'],
                    'handler_name'        => $user->name,
                    'reason'              => 'adjustment',
                    'related_transfer_id' => $transfer->id,
                    'log_date'            => now()->toDateString(),
                    'user_id'             => $user->id,
                    'notes'               => "لغو انتقال توسط {$user->name} — بازگشت موجودی به مبدأ",
                ]);
            }
        }

        $transfer->load(['fromBranch', 'toBranch', 'user']);
        $transfer->setAttribute('status_log', $transfer->statusLog());
        $transfer->setAttribute('items', $transfer->lineItems());

        $action = match ($nextStatus) {
            'shipped' => 'shipped',
            'received' => 'received',
            default => 'status_changed',
        };

        ActivityLogger::record(
            'transfers',
            $action,
            "وضعیت انتقال #{$transfer->id}: {$previousStatus} → {$nextStatus}",
            $transfer,
            [
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
            ],
            (int) $transfer->from_branch_id,
        );

        return response()->json($transfer);
        });
    }

    public function show(Request $request, Transfer $transfer)
    {
        if (!$this->userCanViewTransfer($request->user(), $transfer)) {
            abort(403, 'اجازه مشاهده این انتقال را ندارید');
        }

        $transfer->load(['fromBranch', 'toBranch', 'user']);
        $transfer->setAttribute('status_log', $transfer->statusLog());
        $transfer->setAttribute('items', $transfer->lineItems());

        return response()->json($transfer);
    }

    private function isAdmin($user): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    private function visibleBranchIds($user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $ids = [];
        if ($user?->branch_id) {
            $ids[] = (int) $user->branch_id;
        }
        foreach ($user->iraq_only_visible_branches ?? [] as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function scopeTransfersToUser($query, $user): void
    {
        $ids = $this->visibleBranchIds($user);
        if ($ids === null) {
            return;
        }
        if (!$ids) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($ids, $user) {
            $q->whereIn('from_branch_id', $ids)
                ->orWhereIn('to_branch_id', $ids);
            if ($user?->id) {
                $q->orWhere('user_id', $user->id);
            }
        });
    }

    private function userCanViewTransfer($user, Transfer $transfer): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        if ((int) $transfer->user_id === (int) $user?->id) {
            return true;
        }

        $ids = $this->visibleBranchIds($user) ?? [];

        return in_array((int) $transfer->from_branch_id, $ids, true)
            || in_array((int) $transfer->to_branch_id, $ids, true);
    }

    private function canShip($user, Transfer $transfer): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $from = $transfer->fromBranch ?? Branch::find($transfer->from_branch_id);
        if ($from?->type === 'warehouse') {
            return $user->role === 'warehouse_staff';
        }

        if ($user->role === 'warehouse_staff') {
            return true;
        }

        return (int) $user->branch_id === (int) $transfer->from_branch_id;
    }

    private function canReceive($user, Transfer $transfer): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $to = $transfer->toBranch ?? Branch::find($transfer->to_branch_id);
        if ($to?->type === 'warehouse' && $user->role === 'warehouse_staff') {
            return true;
        }

        if (!$user?->branch_id) {
            return false;
        }

        if ((int) $user->id === (int) $transfer->user_id) {
            return false;
        }

        return (int) $user->branch_id === (int) $transfer->to_branch_id;
    }

    /**
     * POS branch managers may only ship from their own store to Qom or central warehouse.
     * Warehouse stock is controlled by admin / warehouse staff only.
     */
    private function assertTransferRouteAllowed($user, int $fromId, int $toId): ?\Illuminate\Http\JsonResponse
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $from = Branch::find($fromId);
        $to = Branch::find($toId);
        if (!$from || !$to) {
            return response()->json(['message' => 'شعبه یافت نشد'], 404);
        }

        if ($from->type === 'warehouse') {
            if ($user->role !== 'warehouse_staff') {
                return response()->json([
                    'message' => 'فقط مدیر یا انباردار می‌تواند از انبار مرکزی ارسال کند',
                ], 403);
            }

            return null;
        }

        if ($user->role === 'warehouse_staff') {
            return null;
        }

        // Branch POS / managers: only from own branch
        if (!(int) $user->branch_id || (int) $user->branch_id !== $fromId) {
            return response()->json([
                'message' => 'فقط می‌توانید از شعبه خودتان ارسال کنید',
            ], 403);
        }

        $allowedDest = $this->posAllowedDestinationIds();
        if (!in_array($toId, $allowedDest, true)) {
            return response()->json([
                'message' => 'شعبه‌ها فقط می‌توانند به شعبه قم یا انبار مرکزی ارسال کنند',
            ], 403);
        }

        return null;
    }

    /** @return int[] */
    private function posAllowedDestinationIds(): array
    {
        $ids = [];
        $qom = IntakePolicy::qomBranch();
        if ($qom) {
            $ids[] = (int) $qom->id;
        }
        // All warehouses (central hub)
        foreach (Branch::where('type', 'warehouse')->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function statusEvent(string $status, $user): array
    {
        return [
            'status' => $status,
            'at' => now()->toIso8601String(),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
        ];
    }
}
