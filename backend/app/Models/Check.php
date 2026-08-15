<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Check extends Model
{
    protected $fillable = [
        'invoice_id', 'branch_id', 'check_number', 'bank_name',
        'payer_name', 'payer_phone', 'amount', 'currency', 'due_date', 'status', 'notes'
    ];
    protected $casts = ['due_date' => 'date'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
