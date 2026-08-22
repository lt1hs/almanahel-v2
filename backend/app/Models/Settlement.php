<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $fillable = [
        'supplier_id', 'supplier_account_id', 'branch_id', 'user_id', 'settlement_number',
        'period_type', 'period_start', 'period_end', 'amount',
        'currency', 'payment_method', 'notes',
        'check_number', 'bank_name', 'check_status', 'paid_at',
        'cleared_at', 'bounced_at', 'cancelled_at',
    ];
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
        'cleared_at' => 'datetime',
        'bounced_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function supplierAccount() { return $this->belongsTo(SupplierAccount::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function allocations() { return $this->hasMany(SettlementAllocation::class); }
}
