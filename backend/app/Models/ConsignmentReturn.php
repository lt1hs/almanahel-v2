<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentReturn extends Model
{
    protected $fillable = ['supplier_id', 'supplier_account_id', 'branch_id', 'user_id', 'return_number', 'reason', 'idempotency_key'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function supplierAccount() { return $this->belongsTo(SupplierAccount::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(ConsignmentReturnItem::class); }
}
