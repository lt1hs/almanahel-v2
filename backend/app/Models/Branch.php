<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['name', 'city', 'country', 'type', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(Transfer::class, 'from_branch_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(Transfer::class, 'to_branch_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
