<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftLotAllocation extends Model
{
    protected $fillable = [
        'gift_id', 'stock_lot_id', 'quantity', 'unit_cost', 'currency',
        'ownership_type', 'supplier_id', 'settled_publisher_amount',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'settled_publisher_amount' => 'decimal:2',
    ];

    public function gift() { return $this->belongsTo(Gift::class); }
    public function lot() { return $this->belongsTo(StockLot::class, 'stock_lot_id'); }
}
