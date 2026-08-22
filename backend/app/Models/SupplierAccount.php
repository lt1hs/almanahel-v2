<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierAccount extends Model
{
    protected $fillable = [
        'supplier_id', 'branch_id', 'display_name', 'local_code',
        'phone', 'email', 'address', 'city', 'type', 'payment_terms', 'status',
        'created_canonical_supplier',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
