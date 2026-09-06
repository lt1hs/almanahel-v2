<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleLotAllocation extends Model
{
    protected $fillable = [
        'invoice_item_id', 'stock_lot_id', 'quantity', 'unit_cost', 'currency',
        'supplier_account_id',
        'quantity_returned', 'settled_publisher_amount',
        'payable_basis', 'payable_rate', 'gross_cost', 'publisher_payable',
        'rule_source', 'rule_stamped_at',
        'consignment_cost_revision_id',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'payable_rate' => 'decimal:4',
        'gross_cost' => 'decimal:2',
        'publisher_payable' => 'decimal:2',
        'settled_publisher_amount' => 'decimal:2',
        'rule_stamped_at' => 'datetime',
    ];

    public function invoiceItem() { return $this->belongsTo(InvoiceItem::class); }
    public function lot() { return $this->belongsTo(StockLot::class, 'stock_lot_id'); }
    public function settlementAllocations() { return $this->hasMany(SettlementAllocation::class); }
    public function returnLotAllocations() { return $this->hasMany(CustomerReturnLotAllocation::class); }
}
