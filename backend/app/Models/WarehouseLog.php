<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLog extends Model
{
    protected $fillable = [
        'branch_id', 'book_id', 'user_id', 'direction', 'quantity',
        'handler_name', 'handler_phone', 'reason', 'related_transfer_id', 'notes', 'log_date'
    ];
    protected $casts = ['log_date' => 'date'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function relatedTransfer() { return $this->belongsTo(Transfer::class, 'related_transfer_id'); }
}
