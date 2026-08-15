<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $fillable = [
        'branch_id', 'user_id', 'book_id', 'quantity', 'recipient_name',
        'recipient_phone', 'reason', 'cost_value', 'currency',
        'is_consignment', 'supplier_id', 'accounting_status', 'gifted_at'
    ];
    protected $casts = ['is_consignment' => 'boolean', 'gifted_at' => 'date'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
