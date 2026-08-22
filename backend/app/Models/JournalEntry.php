<?php

namespace App\Models;

use App\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    public const LIFECYCLE_COLUMNS = [
        'status',
        'reversed_at',
        'reverses_entry_id',
        'supersedes_entry_id',
        'updated_at',
    ];

    protected $fillable = [
        'uuid', 'occurred_at', 'memo', 'source_type', 'source_id',
        'posted_by', 'reverses_entry_id', 'is_backfill', 'event_type', 'currency',
        'status', 'version', 'reversed_at', 'supersedes_entry_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reversed_at' => 'datetime',
        'is_backfill' => 'boolean',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (!$entry->uuid) {
                $entry->uuid = (string) Str::uuid();
            }
            if (!$entry->status) {
                $entry->status = 'active';
            }
            if (!$entry->version) {
                $entry->version = 1;
            }
        });

        static::updating(function (self $entry) {
            foreach (array_keys($entry->getDirty()) as $column) {
                if (!in_array($column, self::LIFECYCLE_COLUMNS, true)) {
                    throw new DomainException('فیلدهای پولی و هویتی سند حسابداری پس از ثبت قابل ویرایش نیستند');
                }
            }
            foreach (['reverses_entry_id', 'supersedes_entry_id'] as $link) {
                if (array_key_exists($link, $entry->getDirty())) {
                    $previous = $entry->getOriginal($link);
                    if ($previous !== null && (int) $previous !== (int) $entry->getAttribute($link)) {
                        throw new DomainException('ارجاع برگشت یا جایگزینی سند پس از ثبت قابل تغییر نیست');
                    }
                }
            }
        });

        static::deleting(function () {
            throw new DomainException('حذف سند حسابداری مجاز نیست؛ از برگشت استفاده کنید');
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
