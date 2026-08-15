<?php

namespace App\Support;

use App\Models\WarehouseLog;
use Illuminate\Support\Facades\Auth;

class StockMovementLogger
{
    public static function log(
        int $branchId,
        int $bookId,
        string $direction,
        int $quantity,
        string $reason,
        ?string $handlerName = null,
        ?string $notes = null,
        ?int $relatedTransferId = null,
        ?string $logDate = null,
    ): WarehouseLog {
        $user = Auth::user();

        return WarehouseLog::create([
            'branch_id'           => $branchId,
            'book_id'             => $bookId,
            'direction'           => $direction,
            'quantity'            => $quantity,
            'handler_name'        => $handlerName ?? $user?->name ?? 'سیستم',
            'reason'              => $reason,
            'notes'               => $notes,
            'related_transfer_id' => $relatedTransferId,
            'log_date'            => $logDate ?? now()->toDateString(),
            'user_id'             => $user?->id,
        ]);
    }
}
