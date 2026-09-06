<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentCostRevisionLot extends Model
{
    protected $fillable = [
        'consignment_cost_revision_id', 'stock_lot_id',
        'old_payable_unit_cost', 'new_payable_unit_cost',
        'quantity_available_at_change',
    ];

    protected $casts = [
        'old_payable_unit_cost' => 'decimal:2',
        'new_payable_unit_cost' => 'decimal:2',
        'quantity_available_at_change' => 'integer',
    ];

    public function revision()
    {
        return $this->belongsTo(ConsignmentCostRevision::class, 'consignment_cost_revision_id');
    }

    public function lot()
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}
