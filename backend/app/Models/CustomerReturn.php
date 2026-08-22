<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturn extends Model
{
    protected $fillable = [
        'invoice_id', 'branch_id', 'user_id', 'return_number', 'refund_amount', 'refund_method', 'reason', 'returned_at',
        'receivable_reduction', 'cash_refund', 'customer_credit_created', 'currency',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'receivable_reduction' => 'decimal:2',
        'cash_refund' => 'decimal:2',
        'customer_credit_created' => 'decimal:2',
    ];
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(CustomerReturnItem::class); }
    public function lotAllocations() { return $this->hasMany(CustomerReturnLotAllocation::class); }
}
