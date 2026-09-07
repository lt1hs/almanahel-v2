import { ALL_TIME_DEBT_PERIOD_START, todayIsoDate } from "@/lib/financeRequests";

export type SettlementPreset = "all" | "month" | "90d" | "year" | "custom";

export function parseOptionalBranchId(value: string | number | null | undefined): number | null {
    if (value === "" || value == null) return null;
    const n = typeof value === "number" ? value : Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
}

export function toDateInput(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

export function defaultSettlementPeriod(now = new Date()): {
    from: string;
    to: string;
    preset: "all";
} {
    return { from: "", to: toDateInput(now), preset: "all" };
}

export function periodPreset(kind: "month" | "90d" | "year", now = new Date()): { from: string; to: string } {
    const to = toDateInput(now);
    if (kind === "month") {
        return { from: toDateInput(new Date(now.getFullYear(), now.getMonth(), 1)), to };
    }
    if (kind === "90d") {
        const from = new Date(now);
        from.setDate(from.getDate() - 89);
        return { from: toDateInput(from), to };
    }
    return { from: toDateInput(new Date(now.getFullYear(), 0, 1)), to };
}

export function buildSettlementPreviewUrl(options: {
    currency: string;
    supplierAccountId?: number | null;
    supplierId?: number | null;
    fromDate?: string;
    toDate?: string;
    branchId?: number | string | null;
    aggregate?: boolean;
    allOpen?: boolean;
}): string {
    const q = new URLSearchParams();
    if (options.aggregate) q.set("aggregate", "1");
    if (options.supplierId) q.set("supplier_id", String(options.supplierId));
    if (options.supplierAccountId) q.set("supplier_account_id", String(options.supplierAccountId));
    if (options.allOpen) {
        q.set("all_open", "1");
        q.set("period_start", ALL_TIME_DEBT_PERIOD_START);
        q.set("period_end", options.toDate || todayIsoDate());
    } else {
        if (options.fromDate) q.set("period_start", options.fromDate);
        if (options.toDate) q.set("period_end", options.toDate);
    }
    q.set("currency", options.currency);
    const branchId = parseOptionalBranchId(options.branchId);
    if (branchId) q.set("branch_id", String(branchId));
    return `/consignments/settlement-preview?${q.toString()}`;
}

export function buildSettlementBody(options: {
    amount: number;
    currency: string;
    supplierAccountId?: number | null;
    supplierId?: number | null;
    fromDate?: string;
    toDate?: string;
    branchId?: number | string | null;
    allOpen?: boolean;
    expectedTotal?: string | number | null;
    aggregate?: boolean;
    paymentMethod?: string;
    notes?: string | null;
}): Record<string, unknown> {
    const body: Record<string, unknown> = {
        period_type: "custom",
        amount: options.amount,
        currency: options.currency,
        payment_method: options.paymentMethod ?? "bank_transfer",
    };
    if (options.aggregate) body.aggregate = 1;
    if (options.supplierId) body.supplier_id = options.supplierId;
    if (options.supplierAccountId) body.supplier_account_id = options.supplierAccountId;
    const branchId = parseOptionalBranchId(options.branchId);
    if (branchId) body.branch_id = branchId;
    if (options.expectedTotal != null && options.expectedTotal !== "") {
        body.expected_total = options.expectedTotal;
    }
    if (options.notes) body.notes = options.notes;
    if (options.allOpen) {
        body.all_open = true;
        body.period_start = ALL_TIME_DEBT_PERIOD_START;
        body.period_end = options.toDate || todayIsoDate();
    } else {
        body.period_start = options.fromDate;
        body.period_end = options.toDate;
    }
    return body;
}

export function invoicePeriodFromSettlement(
    allOpen: boolean,
    fromDate: string,
    toDate: string,
    result?: { period_start?: string | null; period_end?: string | null } | null
): { from: string; to: string } {
    return {
        from: result?.period_start || fromDate || todayIsoDate(),
        to: result?.period_end || toDate || todayIsoDate(),
    };
}
