export const ADMIN_ROLES = ["super_admin", "admin"] as const;

export type CatalogUser = {
    role?: string | null;
    branch_id?: number | null;
};

export type BranchResolution =
    | { branchId: number; error?: undefined }
    | { branchId: null; error: "branch_required" | "forbidden" };

export function isAdminRole(role?: string | null): boolean {
    return role === "super_admin" || role === "admin";
}

/** Resolve the single operational branch for catalog/inventory API calls. */
export function resolveOperationalBranchId(
    user: CatalogUser,
    explicitBranchId?: number | null
): BranchResolution {
    if (isAdminRole(user.role)) {
        if (explicitBranchId == null || !Number.isFinite(Number(explicitBranchId))) {
            return { branchId: null, error: "branch_required" };
        }
        return { branchId: Number(explicitBranchId) };
    }

    if (user.role === "branch_manager" || user.role === "warehouse_staff") {
        const own = user.branch_id != null ? Number(user.branch_id) : null;
        if (!own) {
            return { branchId: null, error: "branch_required" };
        }
        if (explicitBranchId != null && Number(explicitBranchId) !== own) {
            return { branchId: null, error: "forbidden" };
        }
        return { branchId: own };
    }

    if (user.role === "accountant") {
        const own = user.branch_id != null ? Number(user.branch_id) : null;
        if (!own) {
            return { branchId: null, error: "branch_required" };
        }
        if (explicitBranchId != null && Number(explicitBranchId) !== own) {
            return { branchId: null, error: "forbidden" };
        }
        return { branchId: own };
    }

    return { branchId: null, error: "forbidden" };
}

function withBranchQuery(path: string, branchId: number, params?: Record<string, string | undefined>): string {
    const qs = new URLSearchParams();
    qs.set("branch_id", String(branchId));
    if (params) {
        for (const [key, value] of Object.entries(params)) {
            if (value != null && value !== "") {
                qs.set(key, value);
            }
        }
    }
    return `${path}?${qs.toString()}`;
}

export function buildBooksListUrl(
    user: CatalogUser,
    options?: { branchId?: number | null; search?: string; lite?: boolean }
): string | null {
    const resolved = resolveOperationalBranchId(user, options?.branchId);
    if (resolved.error || resolved.branchId == null) {
        return null;
    }
    return withBranchQuery("/books", resolved.branchId, {
        search: options?.search,
        lite: options?.lite ? "1" : undefined,
    });
}

export function buildBookShowUrl(
    bookId: number | string,
    user: CatalogUser,
    branchId?: number | null
): string | null {
    const resolved = resolveOperationalBranchId(user, branchId);
    if (resolved.error || resolved.branchId == null) {
        return null;
    }
    return withBranchQuery(`/books/${bookId}`, resolved.branchId);
}

export function buildBookByBarcodeUrl(
    code: string,
    user: CatalogUser,
    branchId?: number | null
): string | null {
    const resolved = resolveOperationalBranchId(user, branchId);
    if (resolved.error || resolved.branchId == null) {
        return null;
    }
    return withBranchQuery(`/books/by-barcode/${encodeURIComponent(code)}`, resolved.branchId);
}

export function buildBookLowStockUrl(user: CatalogUser, branchId?: number | null): string | null {
    const resolved = resolveOperationalBranchId(user, branchId);
    if (resolved.error || resolved.branchId == null) {
        return null;
    }
    return withBranchQuery("/books/low-stock", resolved.branchId);
}

export function buildInventoryBookBranchesUrl(
    bookId: number | string,
    user: CatalogUser,
    branchId?: number | null
): string | null {
    const resolved = resolveOperationalBranchId(user, branchId);
    if (resolved.error || resolved.branchId == null) {
        return null;
    }
    return withBranchQuery(`/inventory/books/${bookId}/branches`, resolved.branchId);
}

export function buildPostBooksPayload(
    user: CatalogUser,
    body: Record<string, unknown>,
    branchId?: number | null
): { payload: Record<string, unknown> } | { error: BranchResolution["error"] } {
    const resolved = resolveOperationalBranchId(user, branchId);
    if (resolved.error || resolved.branchId == null) {
        return { error: resolved.error ?? "branch_required" };
    }
    return {
        payload: {
            ...body,
            branch_id: resolved.branchId,
        },
    };
}

/** Only a canonical-not-found 404 should pre-fill ISBN for new intake. */
export function isBarcodeNewBookCandidate(status: number, message?: string): boolean {
    if (status !== 404 || !message) {
        return false;
    }
    if (message.includes("کاتالوگ")) {
        return false;
    }
    return message.includes("بارکد") || message.includes("یافت نشد");
}

export function branchRequiredMessage(error: BranchResolution["error"]): string {
    if (error === "forbidden") {
        return "Branch access denied";
    }
    return "Branch selection required";
}
