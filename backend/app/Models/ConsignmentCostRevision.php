<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentCostRevision extends Model
{
    protected $fillable = [
        'price_change_batch_id', 'book_id', 'branch_id', 'supplier_account_id',
        'currency', 'new_cost', 'effective_at', 'created_by', 'reason',
    ];

    protected $casts = [
        'new_cost' => 'decimal:2',
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

    public function supplierAccount()
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function lots()
    {
        return $this->hasMany(ConsignmentCostRevisionLot::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
