<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ConsignmentFinance
{
    public static function commissionRate(): string
    {
        $rate = Cache::get(
            'almanahel.consignment_commission_rate',
            config('almanahel.consignment_commission_rate', 0.1)
        );
        $normalized = Money::of($rate);
        if (Money::cmp($normalized, '0') < 0) {
            return '0.00';
        }
        if (Money::cmp($normalized, '0.50') > 0) {
            return '0.50';
        }

        return $normalized;
    }

    public static function publisherShare(mixed $soldCost): string
    {
        $keep = Money::sub('1', self::commissionRate());

        return Money::mul($soldCost, $keep);
    }

    public static function commissionAmount(mixed $soldCost): string
    {
        return Money::mul($soldCost, self::commissionRate());
    }

    /** @deprecated use commissionRate() string */
    public static function commissionRateFloat(): float
    {
        return (float) self::commissionRate();
    }
}
