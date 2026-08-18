<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleLotAllocation extends Model
{
    protected $fillable = [
        'invoice_item_id', 'stock_lot_id', 'quantity', 'unit_cost', 'currency',
        'quantity_returned', 'settled_publisher_amount',
    ];

    protected $casts = ['unit_cost' => 'decimal:2'];

    public function invoiceItem() { return $this->belongsTo(InvoiceItem::class); }
    public function lot() { return $this->belongsTo(StockLot::class, 'stock_lot_id'); }
}
