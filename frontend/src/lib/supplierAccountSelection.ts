export type SupplierAccountSelection = {
    accountId: number;
    canonicalSupplierId: number;
    branchId: number;
    name: string;
};

/** Map API supplier-account row to explicit frontend selection. */
export function parseSupplierAccountRow(
    row: Record<string, unknown>,
    fallbackBranchId?: number | null
): SupplierAccountSelection {
    const accountId = Number(row.id);
    return {
        accountId,
        canonicalSupplierId: Number(row.supplier_id),
        branchId: Number(row.branch_id ?? fallbackBranchId),
        name: String(row.display_name ?? row.name ?? `#${accountId}`),
    };
}

/**
 * Mutations must never send accountId as supplier_id.
 * Same-branch: supplier_account_id only (server derives canonical supplier_id).
 * Cross-branch: canonical supplier_id only (server resolves/creates per-branch account).
 */
export function intakeSupplierPayload(
    selection: SupplierAccountSelection | null | undefined,
    targetBranchId: number
): { supplier_account_id?: number; supplier_id?: number } {
    if (!selection) {
        return {};
    }
    if (Number(selection.branchId) === Number(targetBranchId)) {
        return { supplier_account_id: selection.accountId };
    }

    return { supplier_id: selection.canonicalSupplierId };
}

export function supplierAccountsUrl(branchId: number, financial = false): string {
    const params = new URLSearchParams({ branch_id: String(branchId) });
    if (financial) {
        params.set("financial", "1");
    }
    return `/supplier-accounts?${params.toString()}`;
}
