import {
    addDecimalStrings,
    isPositiveDecimalString,
    normalizeDecimalString,
} from "@/lib/decimalMoney";

export interface BulkDebtRow {
    supplierAccountId: number;
    supplierId: number;
    branchId: number;
    name: string;
    balance: string;
    expectedTotal: string;
    currency: string;
    settleable: boolean;
}

export interface UnsettledSupplierApiRow {
    supplier_account_id: number;
    supplier_id: number;
    branch_id: number;
    display_name?: string;
    currency?: string;
    balance?: string | number;
    expected_total?: string | number;
}

export function mapUnsettledRowToBulkDebtRow(
    row: UnsettledSupplierApiRow,
    defaultCurrency: string
): BulkDebtRow | null {
    const supplierAccountId = Number(row.supplier_account_id);
    if (!supplierAccountId || supplierAccountId <= 0) {
        return null;
    }

    const balance = normalizeDecimalString(row.balance ?? "0");
    const expectedTotal = normalizeDecimalString(row.expected_total ?? row.balance ?? "0");
    if (!isPositiveDecimalString(balance)) {
        return null;
    }

    const currency = row.currency || defaultCurrency;

    return {
        supplierAccountId,
        supplierId: Number(row.supplier_id),
        branchId: Number(row.branch_id),
        name: row.display_name || `#${supplierAccountId}`,
        balance,
        expectedTotal,
        currency,
        settleable: true,
    };
}

export function buildBulkSettlementPayload(
    rows: BulkDebtRow[],
    period: { start: string; end: string }
) {
    return {
        atomic: true,
        period_type: "custom" as const,
        period_start: period.start,
        period_end: period.end,
        payment_method: "bank_transfer" as const,
        settlements: rows.map((row) => ({
            supplier_account_id: row.supplierAccountId,
            branch_id: row.branchId,
            amount: row.balance,
            expected_total: row.expectedTotal,
            currency: row.currency,
        })),
    };
}

export function sumSelectedBalances(rows: BulkDebtRow[]): string {
    return addDecimalStrings(...rows.map((row) => row.balance));
}
