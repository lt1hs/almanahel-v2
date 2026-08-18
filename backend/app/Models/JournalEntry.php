<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    protected $fillable = [
        'uuid', 'occurred_at', 'memo', 'source_type', 'source_id',
        'posted_by', 'reverses_entry_id', 'is_backfill', 'event_type', 'currency',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_backfill' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (!$entry->uuid) {
                $entry->uuid = (string) Str::uuid();
            }
        });
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source()
    {
        return $this->morphTo();
    }
}
