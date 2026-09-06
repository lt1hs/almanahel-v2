<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookBranchPrice extends Model
{
    protected $fillable = [
        'book_id', 'branch_id', 'price_toman', 'price_dinar',
        'price_toman_version', 'price_dinar_version',
        'toman_revision_id', 'dinar_revision_id',
    ];

    protected $casts = [
        'price_toman' => 'decimal:2',
        'price_dinar' => 'decimal:2',
        'price_toman_version' => 'integer',
        'price_dinar_version' => 'integer',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function tomanRevision()
    {
        return $this->belongsTo(SellingPriceRevision::class, 'toman_revision_id');
    }

    public function dinarRevision()
    {
        return $this->belongsTo(SellingPriceRevision::class, 'dinar_revision_id');
    }

    public function versionColumn(string $currency): string
    {
        return $currency === 'dinar' ? 'price_dinar_version' : 'price_toman_version';
    }

    public function priceColumn(string $currency): string
    {
        return $currency === 'dinar' ? 'price_dinar' : 'price_toman';
    }

    public function revisionColumn(string $currency): string
    {
        return $currency === 'dinar' ? 'dinar_revision_id' : 'toman_revision_id';
    }
}
