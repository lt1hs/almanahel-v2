<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturn extends Model
{
    protected $fillable = ['invoice_id', 'branch_id', 'user_id', 'return_number', 'refund_amount', 'refund_method', 'reason'];
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(CustomerReturnItem::class); }
}
