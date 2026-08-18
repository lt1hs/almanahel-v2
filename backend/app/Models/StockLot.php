<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLot extends Model
{
    protected $fillable = [
        'book_id', 'branch_id', 'source_branch_id', 'consignment_receipt_item_id',
        'supplier_id', 'ownership_type', 'currency', 'unit_cost',
        'qty_original', 'qty_available', 'qty_reserved', 'origin',
        'parent_lot_id', 'legacy_uncertain', 'legacy_inventory_id', 'migration_source',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'legacy_uncertain' => 'boolean',
    ];

    public function book() { return $this->belongsTo(Book::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function receiptItem() { return $this->belongsTo(ConsignmentReceiptItem::class, 'consignment_receipt_item_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_lot_id'); }
    public function movements() { return $this->hasMany(StockLotMovement::class); }
}
