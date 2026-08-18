<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookBranchPrice extends Model
{
    protected $fillable = ['book_id', 'branch_id', 'price_toman', 'price_dinar'];

    protected $casts = [
        'price_toman' => 'decimal:2',
        'price_dinar' => 'decimal:2',
    ];
}
