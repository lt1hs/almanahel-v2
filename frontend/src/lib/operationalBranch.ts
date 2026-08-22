/** Operational branch selection for consignment / returns / settlements. */

const STORAGE_KEY = "almanahel.operational_branch_id";

export type OperationalUser = {
    role?: string | null;
    branch_id?: number | null;
    branch?: { id?: number | null } | null;
};

export function isAdminOperationalRole(role?: string | null): boolean {
    return role === "admin" || role === "super_admin";
}

export function readPersistedOperationalBranchId(): number | null {
    if (typeof window === "undefined") return null;
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const n = Number(raw);
        return Number.isFinite(n) && n > 0 ? n : null;
    } catch {
        return null;
    }
}

export function persistOperationalBranchId(branchId: number | null): void {
    if (typeof window === "undefined") return;
    try {
        if (branchId == null || !Number.isFinite(branchId) || branchId <= 0) {
            window.localStorage.removeItem(STORAGE_KEY);
            return;
        }
        window.localStorage.setItem(STORAGE_KEY, String(branchId));
    } catch {
        /* ignore quota / private mode */
    }
}

/**
 * Resolve default operational branch for UI.
 * Backend requests must still send explicit branch_id once selected.
 */
export function resolveDefaultOperationalBranchId(
    user: OperationalUser,
    options?: {
        availableBranchIds?: number[];
        centralBranchId?: number | null;
        persistedBranchId?: number | null;
    }
): number | null {
    const assigned = user.branch_id ?? user.branch?.id ?? null;
    const assignedId = assigned != null && Number.isFinite(Number(assigned)) ? Number(assigned) : null;
    const available = options?.availableBranchIds?.filter((id) => Number.isFinite(id) && id > 0) ?? null;
    const isAllowed = (id: number | null) =>
        id != null && (available == null || available.length === 0 || available.includes(id));

    if (!isAdminOperationalRole(user.role)) {
        return isAllowed(assignedId) ? assignedId : null;
    }

    const persisted =
        options?.persistedBranchId !== undefined
            ? options.persistedBranchId
            : readPersistedOperationalBranchId();
    if (isAllowed(persisted)) return persisted;
    if (isAllowed(assignedId)) return assignedId;
    const central = options?.centralBranchId ?? null;
    if (isAllowed(central)) return central;
    return null;
}

export function shouldLockBranchSelector(user: OperationalUser): boolean {
    return !isAdminOperationalRole(user.role);
}
