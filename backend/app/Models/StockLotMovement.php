<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLotMovement extends Model
{
    protected $fillable = ['stock_lot_id', 'type', 'quantity', 'reference_type', 'reference_id', 'meta'];
    protected $casts = ['meta' => 'array'];

    public function lot() { return $this->belongsTo(StockLot::class, 'stock_lot_id'); }
}
