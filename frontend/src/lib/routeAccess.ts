import type { UserRole } from "@/contexts/AuthContext";

const HQ: UserRole[] = ["super_admin", "admin"];
const BRANCH_OPS: UserRole[] = ["super_admin", "admin", "branch_manager"];
const WAREHOUSE_OPS: UserRole[] = ["super_admin", "admin", "warehouse_staff"];
const FINANCE_VIEW: UserRole[] = ["super_admin", "admin", "branch_manager", "accountant"];
const REPORTS: UserRole[] = ["super_admin", "admin", "accountant"];

/**
 * Longest-prefix role map for dashboard routes.
 * `undefined` = any authenticated role.
 * Accountant is included on finance/reports (existing RequireRole), not on POS.
 */
export const ROUTE_ROLES: Record<string, readonly UserRole[] | undefined> = {
    "/dashboard": undefined,
    "/dashboard/inventory/add-stock": HQ,
    "/dashboard/prices": HQ,
    "/dashboard/inventory": undefined,
    "/dashboard/warehouse": WAREHOUSE_OPS,
    "/dashboard/distribution": undefined,
    "/dashboard/consignment": BRANCH_OPS,
    "/dashboard/gifts": BRANCH_OPS,
    "/dashboard/returns": undefined,
    "/dashboard/sales": BRANCH_OPS,
    "/dashboard/invoices": BRANCH_OPS,
    "/dashboard/checks": BRANCH_OPS,
    "/dashboard/credits": BRANCH_OPS,
    "/dashboard/customers": BRANCH_OPS,
    "/dashboard/finance": FINANCE_VIEW,
    "/dashboard/finance/branch-profit": HQ,
    "/dashboard/finance/branch-shares": HQ,
    "/dashboard/expenses": BRANCH_OPS,
    "/dashboard/suppliers": BRANCH_OPS,
    "/dashboard/admin": HQ,
    "/dashboard/reports": REPORTS,
    "/dashboard/notifications": undefined,
};

export function normalizeDashboardPath(pathname: string): string {
    let path = pathname.trim() || "/";
    if (!path.startsWith("/")) path = `/${path}`;
    path = path.replace(/^\/(fa|ar)(?=\/|$)/, "") || "/";
    if (path.length > 1) path = path.replace(/\/+$/, "");
    return path;
}

export function canAccessRoute(role: UserRole | null | undefined, pathname: string): boolean {
    if (!role) return false;
    const path = normalizeDashboardPath(pathname);
    const prefixes = Object.keys(ROUTE_ROLES).sort((a, b) => b.length - a.length);
    for (const prefix of prefixes) {
        if (path === prefix || path.startsWith(`${prefix}/`)) {
            const roles = ROUTE_ROLES[prefix];
            if (!roles) return true;
            return roles.includes(role);
        }
    }
    return true;
}
