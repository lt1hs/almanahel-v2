<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentReturnItem extends Model
{
    protected $fillable = ['consignment_return_id', 'book_id', 'quantity', 'cost_price'];
    public function consignmentReturn() { return $this->belongsTo(ConsignmentReturn::class); }
    public function book() { return $this->belongsTo(Book::class); }
}
