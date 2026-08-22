/**
 * Build consignment settlement deep links. Never substitutes canonical supplier_id
 * for supplier_account_id — those are separate identity domains.
 */
export function buildConsignmentSettleHref(
  receiptId: number,
  supplierAccountId: number | null | undefined
): string | null {
  if (supplierAccountId == null || supplierAccountId <= 0) {
    return null;
  }

  const params = new URLSearchParams({
    receipt_id: String(receiptId),
    supplier_account_id: String(supplierAccountId),
  });

  return `/dashboard/consignment/settle?${params.toString()}`;
}

/** @internal Exported for regression tests — canonical supplier id must never be coerced. */
export function mustNotSubstituteCanonicalSupplierId(
  supplierAccountId: number | null | undefined,
  canonicalSupplierId: number | null | undefined
): boolean {
  if (supplierAccountId == null || supplierAccountId <= 0) {
    return buildConsignmentSettleHref(1, supplierAccountId) === null;
  }

  return (
    buildConsignmentSettleHref(1, supplierAccountId) !==
    buildConsignmentSettleHref(1, canonicalSupplierId)
  );
}
