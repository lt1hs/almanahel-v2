<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{
    protected $fillable = ['code', 'name', 'type', 'branch_id', 'currency', 'is_active'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }
}
