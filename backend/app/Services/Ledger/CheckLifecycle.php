<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\Check;
use App\Models\Settlement;
use Carbon\Carbon;

final class CheckLifecycle
{
    /** @var array<string, list<string>> */
    public const INCOMING = [
        'pending' => ['cleared', 'bounced'],
    ];

    /** @var array<string, list<string>> */
    public const SUPPLIER = [
        'pending' => ['cleared', 'bounced', 'cancelled'],
    ];

    public static function assertIncoming(string $from, string $to): void
    {
        self::assert(self::INCOMING, $from, $to);
    }

    public static function assertSupplier(string $from, string $to): void
    {
        self::assert(self::SUPPLIER, $from, $to);
    }

    /**
     * Reconstruct incoming-check status at asOf. Null means the check did not exist yet.
     */
    public static function incomingStatusAsOf(Check $check, Carbon $asOf): ?string
    {
        $issuedAt = $check->invoice?->sold_at;
        if ($issuedAt === null || $issuedAt->gt($asOf)) {
            return null;
        }
        if ($check->bounced_at && $check->bounced_at->lte($asOf)) {
            return 'bounced';
        }
        if ($check->cleared_at && $check->cleared_at->lte($asOf)) {
            return 'cleared';
        }

        return 'pending';
    }

    /**
     * Reconstruct supplier-check status at asOf. Null means the check was not issued yet.
     */
    public static function supplierStatusAsOf(Settlement $settlement, Carbon $asOf): ?string
    {
        if ((string) $settlement->payment_method !== 'check') {
            return null;
        }
        $issuedAt = $settlement->paid_at;
        if ($issuedAt === null || $issuedAt->gt($asOf)) {
            return null;
        }
        if ($settlement->cancelled_at && $settlement->cancelled_at->lte($asOf)) {
            return 'cancelled';
        }
        if ($settlement->bounced_at && $settlement->bounced_at->lte($asOf)) {
            return 'bounced';
        }
        if ($settlement->cleared_at && $settlement->cleared_at->lte($asOf)) {
            return 'cleared';
        }

        return 'pending';
    }

    /**
     * @param  array<string, list<string>>  $allowed
     */
    private static function assert(array $allowed, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new DomainException('گذار وضعیت چک نامعتبر است', 409, [
                'from' => $from,
                'to' => $to,
            ]);
        }
    }
}
