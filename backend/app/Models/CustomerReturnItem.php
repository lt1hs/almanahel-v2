<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturnItem extends Model
{
    protected $fillable = ['customer_return_id', 'book_id', 'invoice_item_id', 'quantity', 'unit_price'];
    public function customerReturn() { return $this->belongsTo(CustomerReturn::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function invoiceItem() { return $this->belongsTo(InvoiceItem::class); }
}
