<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'branch_id',
        'type',
        'total_toman',
        'total_dinar',
        'payment_method',
        'payment_status',
        'user_id',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'json'
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
