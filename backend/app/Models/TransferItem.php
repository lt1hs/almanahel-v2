<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferItem extends Model
{
    protected $fillable = ['transfer_id', 'book_id', 'quantity'];

    public function transfer() { return $this->belongsTo(Transfer::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function lotSplits() { return $this->hasMany(TransferItemLotSplit::class); }
}
