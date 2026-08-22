<?php

namespace App\Models;

use App\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'ledger_account_id', 'currency', 'debit', 'credit',
        'branch_id', 'supplier_id', 'supplier_account_id', 'customer_id', 'origin_scope',
        'stock_lot_id', 'sale_lot_allocation_id', 'gift_lot_allocation_id',
        'customer_return_lot_allocation_id', 'settlement_allocation_id',
        'financial_account_id', 'check_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('سطرهای سند حسابداری پس از ثبت قابل ویرایش نیستند');
        });

        static::deleting(function () {
            throw new DomainException('حذف سطر سند حسابداری مجاز نیست');
        });
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
