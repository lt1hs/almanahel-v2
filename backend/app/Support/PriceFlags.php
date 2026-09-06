<?php

namespace App\Support;

final class PriceFlags
{
    public static function sellingVersioningEnabled(): bool
    {
        return (bool) config('almanahel.selling_price_versioning_enabled');
    }

    public static function consignmentCostRevisionEnabled(): bool
    {
        return (bool) config('almanahel.consignment_cost_revision_enabled');
    }
}
