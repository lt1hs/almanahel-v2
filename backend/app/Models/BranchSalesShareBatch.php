<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchSalesShareBatch extends Model
{
    public const SCOPE_ALL = 'all_branches';
    public const SCOPE_SELECTED = 'selected_branches';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scope', 'status', 'rate_bps', 'calculation_basis', 'reason',
        'effective_from', 'applied_at', 'created_by', 'idempotency_key',
        'request_hash', 'meta',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'applied_at' => 'datetime',
        'meta' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rules()
    {
        return $this->hasMany(BranchSalesShareRule::class, 'batch_id');
    }
}
