<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'branch_id',
        'book_id',
        'quantity',
        'type',
        'supplier_id',
        'price_toman',
        'price_dinar',
        'cost_price_toman',
        'cost_price_dinar',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
