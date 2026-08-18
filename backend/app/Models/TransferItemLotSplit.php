<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferItemLotSplit extends Model
{
    protected $fillable = ['transfer_item_id', 'source_lot_id', 'dest_lot_id', 'quantity'];

    public function transferItem() { return $this->belongsTo(TransferItem::class); }
    public function sourceLot() { return $this->belongsTo(StockLot::class, 'source_lot_id'); }
    public function destLot() { return $this->belongsTo(StockLot::class, 'dest_lot_id'); }
}
