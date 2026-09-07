<?php

namespace App\Models;

use App\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;

class BranchSalesShareRule extends Model
{
    public const BASIS_NET_REALIZED = 'net_realized_sales';
    public const GLOBAL_SCOPE = 'global';

    protected $fillable = [
        'batch_id', 'branch_id', 'scope_key', 'rate_bps', 'calculation_basis',
        'effective_from', 'effective_to', 'reason', 'created_by', 'idempotency_key',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(BranchSalesShareBatch::class, 'batch_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function scopeKey(?int $branchId): string
    {
        return $branchId === null ? self::GLOBAL_SCOPE : (string) $branchId;
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            $from = $rule->effective_from;
            $to = $rule->effective_to;
            if ($from && $to && $to->lte($from)) {
                throw new DomainException('پایان بازه سهم شعبه باید بعد از شروع آن باشد', 422, [
                    'error' => 'invalid_rule_window',
                ]);
            }
        });
    }
}
