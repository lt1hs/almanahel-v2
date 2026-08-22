<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturnLotAllocation extends Model
{
    protected $fillable = [
        'customer_return_id',
        'customer_return_item_id',
        'sale_lot_allocation_id',
        'stock_lot_id',
        'quantity',
        'unit_cost',
        'currency',
        'ownership_type',
        'supplier_id',
        'supplier_account_id',
        'payable_basis',
        'payable_rate',
        'publisher_payable_reversed',
        'unsettled_payable_reversed',
        'settled_payable_reversed',
        'origin_scope',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'payable_rate' => 'decimal:4',
        'publisher_payable_reversed' => 'decimal:2',
        'unsettled_payable_reversed' => 'decimal:2',
        'settled_payable_reversed' => 'decimal:2',
    ];

    public function customerReturn()
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function item()
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function saleAllocation()
    {
        return $this->belongsTo(SaleLotAllocation::class, 'sale_lot_allocation_id');
    }

    public function lot()
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}
