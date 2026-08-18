<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $fillable = [
        'supplier_id', 'branch_id', 'user_id', 'settlement_number',
        'period_type', 'period_start', 'period_end', 'amount',
        'currency', 'payment_method', 'notes',
        'check_number', 'bank_name', 'check_status',
    ];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function allocations() { return $this->hasMany(SettlementAllocation::class); }
}
