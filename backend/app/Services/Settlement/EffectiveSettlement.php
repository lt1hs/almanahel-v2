<?php

namespace App\Services\Settlement;

use App\Models\Settlement;
use App\Services\Ledger\CheckLifecycle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * A settlement allocation is financially effective when it still reduces supplier AP.
 *
 * cash / bank_transfer => effective
 * supplier check pending => effective (AP already moved to checks_payable)
 * supplier check cleared => effective
 * supplier check bounced / cancelled => not effective
 */
final class EffectiveSettlement
{
    public static function isEffective(Settlement $settlement): bool
    {
        return self::isEffectiveAsOf($settlement, now());
    }

    public static function isEffectiveAsOf(Settlement $settlement, Carbon $asOf): bool
    {
        $paidAt = $settlement->paid_at;
        if ($paidAt === null || $paidAt->gt($asOf)) {
            return false;
        }
        $method = (string) $settlement->payment_method;
        if (in_array($method, ['cash', 'bank_transfer'], true)) {
            return true;
        }
        if ($method === 'check') {
            $status = CheckLifecycle::supplierStatusAsOf($settlement, $asOf);

            return in_array($status, ['pending', 'cleared'], true);
        }

        return false;
    }

    public static function scopeAllocations(Builder $query): Builder
    {
        return $query->whereHas('settlement', function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->whereIn('payment_method', ['cash', 'bank_transfer'])
                    ->orWhere(function (Builder $check) {
                        $check->where('payment_method', 'check')
                            ->whereIn('check_status', ['pending', 'cleared']);
                    });
            });
        });
    }
}
