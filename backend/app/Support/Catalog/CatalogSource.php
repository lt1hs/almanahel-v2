<?php

namespace App\Support\Catalog;

final class CatalogSource
{
    public const CENTRAL = 'central';

    public const TRANSFERRED = 'transferred';

    public const LOCAL = 'local';

    public const LEGACY_UNKNOWN = 'legacy_unknown';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CENTRAL,
            self::TRANSFERRED,
            self::LOCAL,
            self::LEGACY_UNKNOWN,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
