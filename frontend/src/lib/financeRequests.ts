import { addDecimalStrings, decimalStringToDisplayNumber, normalizeDecimalString } from "@/lib/decimalMoney";

export const ALL_TIME_DEBT_PERIOD_START = "1970-01-01";

export type OperationalFinanceRole =
    | "admin"
    | "super_admin"
    | "branch_manager"
    | "accountant";

export function todayIsoDate(reference = new Date()): string {
    return reference.toISOString().slice(0, 10);
}

export function buildUnsettledDebtUrl(options: {
    role: OperationalFinanceRole;
    branchId?: number | null;
    today?: string;
}): string | null {
    const today = options.today ?? todayIsoDate();
    const periodQs = new URLSearchParams({
        period_start: ALL_TIME_DEBT_PERIOD_START,
        period_end: today,
    }).toString();

    if (options.role === "admin" || options.role === "super_admin") {
        return `/consignments/unsettled-by-supplier?aggregate=1&${periodQs}`;
    }

    if (options.role === "branch_manager" || options.role === "accountant") {
        if (!options.branchId) {
            return null;
        }
        return `/consignments/unsettled-by-supplier?branch_id=${options.branchId}&${periodQs}`;
    }

    return null;
}

export function buildSettlementHistoryUrl(scope: {
    aggregate: boolean;
    branchId?: string | number | null;
}): string | null {
    if (scope.aggregate) {
        return "/consignments/settlements?aggregate=1";
    }
    if (!scope.branchId) {
        return null;
    }
    return `/consignments/settlements?branch_id=${scope.branchId}`;
}

export function buildSettlementHistoryScopeKey(scope: {
    aggregate: boolean;
    branchId?: string | number | null;
}): string | null {
    if (scope.aggregate) {
        return "aggregate";
    }
    if (!scope.branchId) {
        return null;
    }
    return `branch:${scope.branchId}`;
}

export function sumDebtRowsForCurrency(
    rows: Array<{ currency?: string; balance?: string | number }>,
    currency: "toman" | "dinar"
): number {
    const target = currency === "dinar" ? "dinar" : "toman";
    const balances = rows
        .filter((row) => row.currency === target)
        .map((row) => normalizeDecimalString(row.balance ?? "0"));
    if (balances.length === 0) {
        return 0;
    }
    return decimalStringToDisplayNumber(addDecimalStrings(...balances));
}
