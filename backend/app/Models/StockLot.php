<?php

namespace App\Models;

use App\Support\Catalog\LotStatus;
use Illuminate\Database\Eloquent\Model;

class StockLot extends Model
{
    protected $fillable = [
        'book_id', 'branch_id', 'source_branch_id', 'consignment_receipt_item_id',
        'supplier_id', 'supplier_account_id', 'ownership_type', 'currency', 'unit_cost',
        'qty_original', 'qty_available', 'qty_reserved', 'origin',
        'parent_lot_id', 'legacy_uncertain', 'legacy_inventory_id', 'migration_source',
        'status',
        'payable_basis', 'payable_rate', 'payable_rule_source', 'payable_rule_stamped_at',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'legacy_uncertain' => 'boolean',
        'payable_rate' => 'decimal:4',
        'payable_rule_stamped_at' => 'datetime',
    ];

    public function book() { return $this->belongsTo(Book::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function supplierAccount() { return $this->belongsTo(SupplierAccount::class); }
    public function receiptItem() { return $this->belongsTo(ConsignmentReceiptItem::class, 'consignment_receipt_item_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_lot_id'); }
    public function movements() { return $this->hasMany(StockLotMovement::class); }

    public function scopeSellable($query)
    {
        return $query->whereIn('status', LotStatus::sellable());
    }
}
