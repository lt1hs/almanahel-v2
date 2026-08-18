<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\Book;
use App\Models\Check;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Transfer;
use Illuminate\Support\Facades\Cache;

class AlertInbox
{
    public function sync(): int
    {
        return $this->syncStockAndPayables() + $this->syncTransfers();
    }

    public function syncStockAndPayables(): int
    {
        $created = 0;
        $threshold = (int) Cache::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));

        Inventory::with(['book:id,title,low_stock_threshold', 'branch:id,name'])
            ->where('quantity', '>=', 0)
            ->chunkById(100, function ($rows) use ($threshold, &$created) {
                foreach ($rows as $inv) {
                    $bookThreshold = $inv->book?->low_stock_threshold ?? $threshold;
                    if ((int) $inv->quantity > (int) $bookThreshold) {
                        continue;
                    }
                    $key = "low_stock:{$inv->id}:{$inv->quantity}";
                    $created += $this->upsert(
                        $key,
                        'low_stock',
                        'موجودی کم',
                        "موجودی «{$inv->book?->title}» در «{$inv->branch?->name}» به {$inv->quantity} رسید",
                        (int) $inv->branch_id,
                        [
                            'inventory_id' => $inv->id,
                            'book_id' => $inv->book_id,
                            'quantity' => $inv->quantity,
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
}
