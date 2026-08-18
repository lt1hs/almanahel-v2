<?php

namespace App\Support;

final class Money
{
    public const SCALE = 2;

    public static function of(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (is_int($value)) {
            return number_format($value, self::SCALE, '.', '');
        }
        $normalized = str_replace(',', '', (string) $value);
        if (!is_numeric($normalized)) {
            return '0.00';
        }

        return bcadd($normalized, '0', self::SCALE);
    }

    public static function add(mixed $a, mixed $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(mixed $a, mixed $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function max(mixed $a, mixed $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function min(mixed $a, mixed $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function isNegative(mixed $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function isZero(mixed $a): bool
    {
        return self::cmp($a, '0') === 0;
    }
}
