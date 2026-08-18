<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'branch_id', 'customer_id', 'user_id', 'invoice_number', 'payment_method',
        'payment_status', 'currency', 'subtotal', 'discount_amount',
        'total', 'customer_name', 'customer_phone', 'notes', 'due_date', 'type'
    ];

    protected $casts = ['due_date' => 'date'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function check() { return $this->hasOne(Check::class); }
    public function customerReturn() { return $this->hasOne(CustomerReturn::class); }
    public function customerReturns() { return $this->hasMany(CustomerReturn::class); }
}
