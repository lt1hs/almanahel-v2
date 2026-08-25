<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\Book;
use App\Models\Check;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\StockLot;
use App\Models\Transfer;

class AlertInbox
{
    public function sync(): int
    {
        return $this->syncStockAndPayables() + $this->syncTransfers();
    }

    public function syncStockAndPayables(): int
    {
        $created = 0;
        $threshold = (int) AppSetting::get(
            'almanahel.low_stock_threshold',
            config('almanahel.low_stock_threshold', 5)
        );

        Inventory::with(['book:id,title,low_stock_threshold', 'branch:id,name'])
            ->where('quantity', '>=', 0)
            ->whereNull('superseded_by_inventory_id')
            ->chunkById(100, function ($rows) use ($threshold, &$created) {
                $zeroPairs = $rows
                    ->filter(fn (Inventory $inv) => (int) $inv->quantity === 0)
                    ->map(fn (Inventory $inv) => [
                        'branch_id' => (int) $inv->branch_id,
                        'book_id' => (int) $inv->book_id,
                    ])
                    ->values();

                $stockedKeys = $this->keysWithPriorStock($zeroPairs);

                foreach ($rows as $inv) {
                    $qty = (int) $inv->quantity;
                    $bookThreshold = $inv->book?->low_stock_threshold ?? $threshold;
                    if ($qty > (int) $bookThreshold) {
                        continue;
                    }

                    // Pricing-only shells (qty 0, never received stock at this branch)
                    // must not fire "low stock" — that happens when hub saves sell prices
                    // for POS branches without transferring stock there.
                    if ($qty === 0) {
                        $pairKey = (int) $inv->branch_id . ':' . (int) $inv->book_id;
                        if (!isset($stockedKeys[$pairKey])) {
                            continue;
                        }
                    }

                    $key = "low_stock:{$inv->id}:{$qty}";
                    $created += $this->upsert(
                        $key,
                        'low_stock',
                        'موجودی کم',
                        "موجودی «{$inv->book?->title}» در «{$inv->branch?->name}» به {$qty} رسید",
                        (int) $inv->branch_id,
                        [
                            'inventory_id' => $inv->id,
                            'book_id' => $inv->book_id,
                            'quantity' => $qty,
                        ]
                    );
                }
            });

        Check::where('status', 'pending')
            ->where('due_date', '<=', now()->addDays(3))
            ->each(function (Check $chk) use (&$created) {
                $key = "check_due:{$chk->id}:" . optional($chk->due_date)->toDateString();
                $created += $this->upsert(
                    $key,
                    'check_due',
                    'چک سررسید',
                    "چک {$chk->check_number} از {$chk->payer_name}",
                    (int) $chk->branch_id,
                    ['check_id' => $chk->id]
                );
            });

        Invoice::where('payment_method', 'credit')
            ->where('payment_status', 'pending')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays(3))
            ->each(function (Invoice $inv) use (&$created) {
                $key = "credit_due:{$inv->id}:" . optional($inv->due_date)->toDateString();
                $created += $this->upsert(
                    $key,
                    'credit_due',
                    'نسیه سررسید',
                    "فاکتور {$inv->invoice_number}",
                    (int) $inv->branch_id,
                    ['invoice_id' => $inv->id]
                );
            });

        return $created;
    }

    public function syncTransfers(): int
    {
        $created = 0;
        $rows = Transfer::with(['fromBranch', 'toBranch'])
            ->whereIn('status', ['pending', 'shipped', 'received'])
            ->latest()
            ->limit(80)
            ->get();

        $bookIds = $rows->flatMap(fn (Transfer $t) => collect($t->lineItems())->pluck('book_id'))
            ->filter()
            ->unique()
            ->values();
        $titles = $bookIds->isEmpty()
            ? collect()
            : Book::whereIn('id', $bookIds)->pluck('title', 'id');

        foreach ($rows as $transfer) {
            $items = $transfer->lineItems();
            $qty = (int) collect($items)->sum(fn ($item) => (int) ($item['quantity'] ?? 0));
            $firstId = $items[0]['book_id'] ?? null;
            $bookTitle = $firstId ? (string) ($titles->get((int) $firstId) ?? 'کتاب') : 'کتاب';
            if (count($items) > 1) {
                $bookTitle .= ' (+'.(count($items) - 1).')';
            }
            $fromName = $transfer->fromBranch?->name ?? 'مبدأ';
            $toName = $transfer->toBranch?->name ?? 'مقصد';
            $payload = [
                'transfer_id' => $transfer->id,
                'book_title' => $bookTitle,
                'quantity' => $qty,
                'from_branch' => $fromName,
                'to_branch' => $toName,
                'status' => $transfer->status,
            ];

            if (in_array($transfer->status, ['pending', 'shipped'], true)) {
                $created += $this->upsert(
                    "transfer:{$transfer->id}:sending",
                    'transfer_sending',
                    'در حال ارسال',
                    "محموله «{$bookTitle}» از {$fromName} در راه است — تأیید دریافت کنید",
                    (int) $transfer->to_branch_id,
                    $payload
                );
            }
            if (
                $transfer->status === 'received'
                && $transfer->updated_at
                && $transfer->updated_at->gte(now()->subDays(14))
            ) {
                $created += $this->upsert(
                    "transfer:{$transfer->id}:received",
                    'transfer_received',
                    'دریافت شد',
                    "{$toName} دریافت «{$bookTitle}» را تأیید کرد",
                    (int) $transfer->from_branch_id,
                    $payload
                );
            }
        }

        return $created;
    }

    public function upsert(string $key, string $type, string $title, string $body, ?int $branchId, array $data): int
    {
        $existing = AppNotification::where('dedupe_key', $key)->first();
        if ($existing) {
            return 0;
        }
        AppNotification::create([
            'dedupe_key' => $key,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'branch_id' => $branchId ?: null,
            'data' => $data,
        ]);

        return 1;
    }

    /**
     * Remove false-positive low-stock alerts for pricing-only inventory shells
     * (qty 0 with no stock lots ever received at that branch).
     */
    public function purgePricingOnlyLowStockAlerts(): int
    {
        $deleted = 0;
        AppNotification::query()
            ->where('type', 'low_stock')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$deleted) {
                foreach ($rows as $row) {
                    $data = is_array($row->data) ? $row->data : [];
                    $qty = (int) ($data['quantity'] ?? -1);
                    $bookId = (int) ($data['book_id'] ?? 0);
                    $branchId = (int) ($row->branch_id ?? $data['branch_id'] ?? 0);
                    if ($qty !== 0 || $bookId <= 0 || $branchId <= 0) {
                        continue;
                    }
                    $hadLots = StockLot::query()
                        ->where('branch_id', $branchId)
                        ->where('book_id', $bookId)
                        ->exists();
                    if ($hadLots) {
                        continue;
                    }
                    $row->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{branch_id:int,book_id:int}>  $pairs
     * @return array<string, true>
     */
    private function keysWithPriorStock($pairs): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $branchIds = $pairs->pluck('branch_id')->unique()->values()->all();
        $bookIds = $pairs->pluck('book_id')->unique()->values()->all();

        $keys = [];
        StockLot::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('book_id', $bookIds)
            ->select(['branch_id', 'book_id'])
            ->distinct()
            ->get()
            ->each(function ($lot) use (&$keys) {
                $keys[(int) $lot->branch_id . ':' . (int) $lot->book_id] = true;
            });

        return $keys;
    }
}
