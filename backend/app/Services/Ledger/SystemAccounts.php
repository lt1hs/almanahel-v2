<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\LedgerAccount;

final class SystemAccounts
{
    public static function get(string $code, string $currency): LedgerAccount
    {
        $full = 'sys.' . $code . '.' . $currency;
        $account = LedgerAccount::where('code', $full)->first();
        if (!$account) {
            throw new DomainException('حساب سیستمی دفتر یافت نشد: ' . $full);
        }

        return $account;
    }
}
