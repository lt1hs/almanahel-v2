<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeBatch extends Model
{
    public const TYPE_SELLING = 'selling_price';
    public const TYPE_CONSIGNMENT = 'consignment_cost';
    public const SCOPE_ALL = 'all_branches';
    public const SCOPE_SELECTED = 'selected_branches';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'scope', 'status', 'reason', 'effective_at', 'applied_at',
        'created_by', 'idempotency_key', 'request_hash', 'meta',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'applied_at' => 'datetime',
        'meta' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sellingRevisions()
    {
        return $this->hasMany(SellingPriceRevision::class);
    }

    public function consignmentRevisions()
    {
        return $this->hasMany(ConsignmentCostRevision::class);
    }
}
