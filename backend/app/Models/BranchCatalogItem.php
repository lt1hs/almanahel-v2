<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchCatalogItem extends Model
{
    protected $fillable = [
        'branch_id',
        'book_id',
        'source',
        'local_supplier_account_id',
        'active',
        'price_toman',
        'price_dinar',
        'created_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'price_toman' => 'decimal:2',
        'price_dinar' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function localSupplierAccount()
    {
        return $this->belongsTo(SupplierAccount::class, 'local_supplier_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
