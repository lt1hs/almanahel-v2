<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'city', 'country', 'type', 'status',
        'is_central_warehouse', 'is_intake_hub', 'is_iraq_store',
        'supports_dinar', 'supports_toman',
        'can_receive_inventory', 'can_set_pricing', 'can_sell',
        'can_transfer', 'can_report', 'can_manage_alerts',
        'archived_at',
    ];

    protected $casts = [
        'is_central_warehouse' => 'boolean',
        'is_intake_hub' => 'boolean',
        'is_iraq_store' => 'boolean',
        'supports_dinar' => 'boolean',
        'supports_toman' => 'boolean',
        'can_receive_inventory' => 'boolean',
        'can_set_pricing' => 'boolean',
        'can_sell' => 'boolean',
        'can_transfer' => 'boolean',
        'can_report' => 'boolean',
        'can_manage_alerts' => 'boolean',
        'archived_at' => 'datetime',
    ];

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
