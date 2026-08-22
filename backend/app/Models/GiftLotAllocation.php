<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftLotAllocation extends Model
{
    protected $fillable = [
        'gift_id', 'stock_lot_id', 'quantity', 'unit_cost', 'currency',
        'ownership_type', 'supplier_id', 'supplier_account_id', 'settled_publisher_amount',
        'payable_basis', 'payable_rate', 'gross_cost', 'publisher_payable',
        'rule_source', 'rule_stamped_at',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'settled_publisher_amount' => 'decimal:2',
        'payable_rate' => 'decimal:4',
        'gross_cost' => 'decimal:2',
        'publisher_payable' => 'decimal:2',
        'rule_stamped_at' => 'datetime',
    ];

    public function gift() { return $this->belongsTo(Gift::class); }
    public function lot() { return $this->belongsTo(StockLot::class, 'stock_lot_id'); }
}
