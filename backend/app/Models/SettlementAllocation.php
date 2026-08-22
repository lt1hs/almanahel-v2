<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementAllocation extends Model
{
    protected $fillable = [
        'settlement_id', 'consignment_receipt_id', 'consignment_receipt_item_id',
        'sale_lot_allocation_id', 'gift_lot_allocation_id',
        'amount', 'currency', 'quantity', 'unit_cost',
        'supplier_account_id',
        'payable_basis', 'payable_rate', 'gross_cost', 'publisher_payable',
        'rule_source', 'rule_stamped_at',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function receipt()
    {
        return $this->belongsTo(ConsignmentReceipt::class, 'consignment_receipt_id');
    }
}
