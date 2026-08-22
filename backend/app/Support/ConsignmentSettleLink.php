<?php

namespace App\Support;

/**
 * Consignment settlement deep-link builder.
 * Mirrors frontend/src/lib/consignmentSettlementLink.ts — keep in sync.
 */
final class ConsignmentSettleLink
{
    public static function build(int $receiptId, ?int $supplierAccountId): ?string
    {
        if ($supplierAccountId === null || $supplierAccountId <= 0) {
            return null;
        }

        return '/dashboard/consignment/settle?'
            .http_build_query([
                'receipt_id' => $receiptId,
                'supplier_account_id' => $supplierAccountId,
            ]);
    }

    /**
     * Canonical supplier_id must never be substituted when supplier_account_id is missing.
     */
    public static function mustNotSubstituteCanonicalSupplierId(?int $supplierAccountId, ?int $canonicalSupplierId): bool
    {
        if ($supplierAccountId === null || $supplierAccountId <= 0) {
            return self::build(1, $supplierAccountId) === null;
        }

        return self::build(1, $supplierAccountId) !== self::build(1, $canonicalSupplierId);
    }
}
