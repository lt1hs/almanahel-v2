<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'book_id', 'quantity', 'unit_price', 'actual_price', 'discount', 'list_price', 'override_by', 'override_reason'];
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function overrideBy() { return $this->belongsTo(User::class, 'override_by'); }
}
