<?php

namespace App\Models;

use App\Services\Settlement\SnapshotPayable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class ConsignmentReceipt extends Model
{
    protected $fillable = [
        'supplier_id', 'supplier_account_id', 'branch_id', 'user_id', 'receipt_number',
        'status', 'currency', 'total_value', 'settled_amount', 'received_at', 'notes',
        'payable_basis', 'payable_rate', 'payable_rule_source', 'payable_rule_stamped_at',
    ];
    protected $casts = ['received_at' => 'date'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function supplierAccount() { return $this->belongsTo(SupplierAccount::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(ConsignmentReceiptItem::class); }

    /**
     * Recalculate totals/status after items change (e.g. book cascade delete).
     * Empty receipts are auto-closed as settled so they don't block the UI.
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

        $totalValue = '0.00';
        foreach ($this->items as $item) {
            $totalValue = Money::add($totalValue, Money::mul($item->cost_price, (int) $item->quantity_received));
        }
        $this->forceFill(['total_value' => $totalValue])->save();
        app(SnapshotPayable::class)->syncReceipt($this->fresh('items'));
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
