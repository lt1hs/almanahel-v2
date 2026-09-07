<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchShareReturnAllocation extends Model
{
    protected $fillable = [
        'customer_return_id', 'return_allocation_id', 'sale_share_id', 'branch_id',
        'currency', 'returned_quantity', 'reversed_base_amount', 'reversed_share_amount',
    ];

    protected $casts = [
        'reversed_base_amount' => 'decimal:2',
        'reversed_share_amount' => 'decimal:2',
    ];

    public function customerReturn()
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function returnAllocation()
    {
        return $this->belongsTo(CustomerReturnLotAllocation::class, 'return_allocation_id');
    }

    public function saleShare()
    {
        return $this->belongsTo(InvoiceItemBranchShare::class, 'sale_share_id');
    }
}
