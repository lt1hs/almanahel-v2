<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentReceiptItem extends Model
{
    protected $fillable = [
        'consignment_receipt_id', 'book_id', 'quantity_received',
        'quantity_sold', 'quantity_returned', 'cost_price', 'selling_price'
    ];
    public function consignmentReceipt() { return $this->belongsTo(ConsignmentReceipt::class); }
    public function book() { return $this->belongsTo(Book::class); }
}
