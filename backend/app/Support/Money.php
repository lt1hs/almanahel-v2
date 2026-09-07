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

    public static function roundHalfUp(mixed $value, int $scale = self::SCALE): string
    {
        $raw = str_replace(',', '', (string) $value);
        if (!is_numeric($raw)) {
            $raw = '0';
        }
        $negative = bccomp($raw, '0', $scale + 4) < 0;
        $abs = $negative ? bcmul($raw, '-1', $scale + 4) : $raw;
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), $scale + 1);
        $rounded = bcadd($abs, $half, $scale);

        return $negative ? bcmul($rounded, '-1', $scale) : $rounded;
    }

    public static function percentOf(mixed $amount, mixed $percent): string
    {
        $product = bcmul(self::of($amount), self::of($percent), 8);
        $raw = bcdiv($product, '100', 8);

        return self::roundHalfUp($raw);
    }

    /** $bps is integer basis points (1000 = 10%). */
    public static function mulBps(mixed $amount, int $bps): string
    {
        if ($bps <= 0) {
            return self::of(0);
        }
        $raw = bcdiv(bcmul(self::of($amount), (string) $bps, 8), '10000', 8);

        return self::roundHalfUp($raw);
    }

    public static function proportion(mixed $amount, int $part, int $whole): string
    {
        if ($part <= 0 || $whole <= 0) {
            return self::of(0);
        }
        $raw = bcdiv(bcmul(self::of($amount), (string) $part, 8), (string) $whole, 8);

        return self::roundHalfUp($raw);
    }
}
