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

export const ALL_BRANCHES_VALUE = "all";

export function supplierAccountsUrl(branchId: number, financial = false): string {
    const params = new URLSearchParams({ branch_id: String(branchId) });
    if (financial) {
        params.set("financial", "1");
    }
    return `/supplier-accounts?${params.toString()}`;
}

export function supplierAccountsAggregateUrl(financial = false): string {
    const params = new URLSearchParams({ aggregate: "1" });
    if (financial) {
        params.set("financial", "1");
    }
    return `/supplier-accounts?${params.toString()}`;
}

/** Deduplicate per-branch accounts onto one row per canonical supplier. */
export function uniqueCanonicalSuppliers(
    rows: Array<{ id: number; supplier_id?: number | null; display_name?: string; name?: string }>
): { id: number; name: string }[] {
    const seen = new Set<number>();
    const out: { id: number; name: string }[] = [];
    for (const row of rows) {
        const supplierId = Number(row.supplier_id);
        if (!Number.isFinite(supplierId) || supplierId <= 0 || seen.has(supplierId)) {
            continue;
        }
        seen.add(supplierId);
        out.push({
            id: supplierId,
            name: String(row.display_name || row.name || `#${supplierId}`),
        });
    }
    return out;
}

type InventorySupplierRow = {
    branch_id?: number | null;
    supplier_id?: number | null;
    supplier?: { id?: number | null; name?: string | null } | null;
};

function positiveId(value: unknown): number | null {
    const id = Number(value);
    return Number.isFinite(id) && id > 0 ? id : null;
}

/** Canonical supplier id from a book inventory row (column first, then relation). */
export function inventoryCanonicalSupplierId(
    inventory: InventorySupplierRow | null | undefined
): number | null {
    return positiveId(inventory?.supplier_id) ?? positiveId(inventory?.supplier?.id);
}

/** Prefer the selected branch's inventory; otherwise any row that already has a supplier. */
export function pickInventoryForSupplierPrefill<T extends InventorySupplierRow>(
    inventories: T[] | null | undefined,
    branchId?: number | null
): T | null {
    const rows = Array.isArray(inventories) ? inventories : [];
    if (!rows.length) return null;
    const preferredId = positiveId(branchId);
    if (preferredId != null) {
        const preferred = rows.find((row) => Number(row.branch_id) === preferredId) ?? null;
        if (preferred && inventoryCanonicalSupplierId(preferred)) return preferred;
    }
    return rows.find((row) => inventoryCanonicalSupplierId(row) != null) ?? rows[0] ?? null;
}

export function selectionMatchingCanonicalSupplier(
    accounts: SupplierAccountSelection[],
    canonicalSupplierId?: number | null
): SupplierAccountSelection | null {
    const id = positiveId(canonicalSupplierId);
    if (id == null) return null;
    return accounts.find((row) => Number(row.canonicalSupplierId) === id) ?? null;
}

