<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'address', 'city', 'contact_info', 'type', 'status', 'identity_origin', 'origin_branch_id'];

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

    public function accounts()
    {
        return $this->hasMany(SupplierAccount::class);
    }
}
