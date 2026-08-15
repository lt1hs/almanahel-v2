<?php

namespace App\Support;

class ConsignmentFinance
{
    public static function commissionRate(): float
    {
        return max(0.0, min(0.5, (float) config('almanahel.consignment_commission_rate', 0.1)));
    }

    /** Amount owed to the publisher after store commission. */
    public static function publisherShare(float $soldCost): float
    {
        return round($soldCost * (1 - self::commissionRate()), 2);
    }

    public static function commissionAmount(float $soldCost): float
    {
        return round($soldCost * self::commissionRate(), 2);
    }
}
