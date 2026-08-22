<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'branch_id',
        'amount',
        'currency',
        'category',
        'description',
        'date',
        'user_id',
        'archived_at',
        'reversed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'archived_at' => 'datetime',
        'reversed_at' => 'datetime',
        'amount' => 'decimal:2',
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
