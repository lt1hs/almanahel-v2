<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'isbn', 'title', 'author', 'publisher', 'size', 'cover', 'publication_year',
        'cover_image', 'weight', 'weight_with_packaging', 'volume_count',
        'category', 'description', 'language', 'iraq_only', 'low_stock_threshold',
    ];

    protected $casts = [
        'iraq_only' => 'boolean',
        'weight' => 'decimal:2',
        'weight_with_packaging' => 'decimal:2',
        'volume_count' => 'integer',
    ];

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
