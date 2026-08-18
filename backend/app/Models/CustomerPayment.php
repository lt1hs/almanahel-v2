<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $fillable = [
        'customer_id', 'invoice_id', 'branch_id', 'user_id',
        'amount', 'currency', 'method', 'notes', 'paid_at',
    ];

    protected $casts = ['paid_at' => 'datetime', 'amount' => 'decimal:2'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
