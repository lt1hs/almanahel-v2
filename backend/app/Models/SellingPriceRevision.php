<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellingPriceRevision extends Model
{
    protected $fillable = [
        'price_change_batch_id', 'book_id', 'branch_id', 'currency',
        'old_price', 'new_price', 'version', 'previous_revision_id',
        'effective_at', 'created_by', 'reason',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'version' => 'integer',
        'effective_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(PriceChangeBatch::class, 'price_change_batch_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_revision_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
