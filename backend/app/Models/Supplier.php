<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'city', 'contact_info', 'type', 'status'];

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function consignmentReceipts()
    {
        return $this->hasMany(ConsignmentReceipt::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }
}
