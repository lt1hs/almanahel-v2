<?php

namespace App\Support;

use App\Models\AppSetting;

final class BranchShareFlags
{
    public const SETTING_KEY = 'almanahel.branch_sales_share_enabled';

    public static function enabled(): bool
    {
        $stored = AppSetting::get(self::SETTING_KEY);
        if ($stored !== null) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('almanahel.branch_sales_share_enabled');
    }

    public static function setEnabled(bool $enabled): void
    {
        AppSetting::put(self::SETTING_KEY, $enabled);
    }
}
