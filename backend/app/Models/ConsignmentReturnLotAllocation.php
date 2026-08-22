<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentReturnLotAllocation extends Model
{
    protected $fillable = [
        'consignment_return_item_id', 'stock_lot_id', 'quantity', 'unit_cost', 'currency',
        'supplier_account_id',
    ];

    protected $casts = ['unit_cost' => 'decimal:2'];
}
