<?php

namespace App\Models;

use App\Support\ConsignmentFinance;
use Illuminate\Database\Eloquent\Model;

class ConsignmentReceipt extends Model
{
    protected $fillable = [
        'supplier_id', 'branch_id', 'user_id', 'receipt_number',
        'status', 'currency', 'total_value', 'settled_amount', 'received_at', 'notes'
    ];
    protected $casts = ['received_at' => 'date'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(ConsignmentReceiptItem::class); }

    /**
     * Recalculate totals/status after items change (e.g. book cascade delete).
     * Empty receipts are auto-closed as settled so they don't block the UI.
     * Settled target is publisher share of sold cost (after store commission).
     */
    public function recalculateFromItems(): void
    {
        $this->loadMissing('items');

        if ($this->items->isEmpty()) {
            $this->forceFill([
                'total_value'    => 0,
                'settled_amount' => 0,
                'status'         => 'settled',
            ])->save();

            return;
        }

        $totalValue = (float) $this->items->sum(
            fn ($item) => (float) $item->cost_price * (int) $item->quantity_received
        );
        $soldCost = (float) $this->items->sum(
            fn ($item) => (float) $item->cost_price * (int) $item->quantity_sold
        );
        $owed = ConsignmentFinance::publisherShare($soldCost);
        $settled = min((float) $this->settled_amount, max($owed, $totalValue));

        if ($owed <= 0 && $settled <= 0) {
            $status = 'unsettled';
        } elseif ($owed > 0 && $settled >= $owed) {
            $status = 'settled';
        } elseif ($settled > 0) {
            $status = 'partially_settled';
        } else {
            $status = 'unsettled';
        }

        $this->forceFill([
            'total_value'    => $totalValue,
            'settled_amount' => $settled,
            'status'         => $status,
        ])->save();
    }

    /** Close empty / orphan unsettled receipts left after book deletions. */
    public static function closeOrphans(): int
    {
        $orphans = static::query()
            ->where('status', '!=', 'settled')
            ->whereDoesntHave('items')
            ->get();

        foreach ($orphans as $receipt) {
            $receipt->recalculateFromItems();
        }

        return $orphans->count();
    }
}
