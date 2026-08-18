<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'branch_id', 'notes', 'archived_at'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function payments() { return $this->hasMany(CustomerPayment::class); }
}
