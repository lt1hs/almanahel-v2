<?php

namespace App\Support\Catalog;

final class LotStatus
{
    public const AVAILABLE = 'available';

    public const QUARANTINED = 'quarantined';

    public const BLOCKED = 'blocked';

    /** @return list<string> */
    public static function sellable(): array
    {
        return [self::AVAILABLE];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::AVAILABLE,
            self::QUARANTINED,
            self::BLOCKED,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
