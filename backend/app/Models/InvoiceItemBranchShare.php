<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItemBranchShare extends Model
{
    protected $fillable = [
        'invoice_id', 'invoice_item_id', 'branch_id', 'currency', 'quantity',
        'net_sales_amount', 'rate_bps', 'share_amount', 'rule_id', 'calculation_basis',
    ];

    protected $casts = [
        'net_sales_amount' => 'decimal:2',
        'share_amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function rule()
    {
        return $this->belongsTo(BranchSalesShareRule::class, 'rule_id');
    }

    public function returnAllocations()
    {
        return $this->hasMany(BranchShareReturnAllocation::class, 'sale_share_id');
    }
}
