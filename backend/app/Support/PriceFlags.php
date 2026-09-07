<?php

namespace App\Support;

use App\Models\AppSetting;

final class PriceFlags
{
    public const SELLING_KEY = 'almanahel.selling_price_versioning_enabled';
    public const CONSIGNMENT_KEY = 'almanahel.consignment_cost_revision_enabled';

    public static function sellingVersioningEnabled(): bool
    {
        return self::storedOrConfig(self::SELLING_KEY, 'almanahel.selling_price_versioning_enabled');
    }

    public static function consignmentCostRevisionEnabled(): bool
    {
        return self::storedOrConfig(self::CONSIGNMENT_KEY, 'almanahel.consignment_cost_revision_enabled');
    }

    public static function setSellingVersioningEnabled(bool $enabled): void
    {
        AppSetting::put(self::SELLING_KEY, $enabled);
    }

    public static function setConsignmentCostRevisionEnabled(bool $enabled): void
    {
        AppSetting::put(self::CONSIGNMENT_KEY, $enabled);
    }

    private static function storedOrConfig(string $settingKey, string $configKey): bool
    {
        $stored = AppSetting::get($settingKey);
        if ($stored !== null) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config($configKey);
    }
}
