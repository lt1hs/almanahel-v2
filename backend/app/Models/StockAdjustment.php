<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $fillable = [
        'branch_id', 'book_id', 'user_id', 'quantity_delta', 'reason', 'meta',
    ];

    protected $casts = ['meta' => 'array'];
}
