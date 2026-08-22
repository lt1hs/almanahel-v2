<?php

namespace App\Models;

use App\Services\Treasury\FinancialAccountResolver;
use Illuminate\Database\Eloquent\Model;

class FinancialAccount extends Model
{
    public const TREASURY_TYPES = [
        'cash_drawer',
        'bank',
        'card_clearing',
        'checks_receivable',
        'accounts_receivable',
        'supplier_payable',
        'checks_payable',
    ];

    protected $fillable = [
        'code', 'branch_id', 'currency', 'type', 'name', 'ledger_account_id',
        'is_default', 'is_active', 'default_scope_key',
        'bank_name', 'account_number', 'iban', 'notes',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account) {
            if (!$account->is_active) {
                $account->is_default = false;
                $account->default_scope_key = null;

                return;
            }
            if ($account->is_default) {
                $account->default_scope_key = FinancialAccountResolver::scopeKey(
                    $account->branch_id !== null ? (int) $account->branch_id : null,
                    $account->currency,
                    $account->type
                );
            } else {
                $account->default_scope_key = null;
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function ledgerAccount()
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
