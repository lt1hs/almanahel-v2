"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Users } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";
import {
    buildBulkSettlementPayload,
    mapUnsettledRowToBulkDebtRow,
    sumSelectedBalances,
    type BulkDebtRow,
} from "@/lib/bulkSettlementRows";
import { decimalStringToDisplayNumber } from "@/lib/decimalMoney";

interface BulkSettlementPanelProps {
    branchId: number | null;
    currency: "toman" | "dinar";
    currencySymbol: string;
    reloadKey?: number;
}

function monthStart(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export function BulkSettlementPanel({
    branchId,
    currency,
    currencySymbol,
    reloadKey = 0,
}: BulkSettlementPanelProps) {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [debts, setDebts] = useState<BulkDebtRow[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [fromDate, setFromDate] = useState(monthStart);
    const [toDate, setToDate] = useState(todayIso);
    const [isLoading, setIsLoading] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const loadDebts = useCallback(async () => {
        if (!branchId || !fromDate || !toDate) {
            setDebts([]);
            setSelected(new Set());
            return;
        }
        setIsLoading(true);
        try {
            const params = new URLSearchParams({
                branch_id: String(branchId),
                period_start: fromDate,
                period_end: toDate,
            });
            const data = await apiRequest(`/consignments/unsettled-by-supplier?${params.toString()}`);
            const rows = (Array.isArray(data?.rows) ? data.rows : [])
                .map((row: Parameters<typeof mapUnsettledRowToBulkDebtRow>[0]) =>
                    mapUnsettledRowToBulkDebtRow(row, currency)
                )
                .filter((row: BulkDebtRow | null): row is BulkDebtRow =>
                    row !== null && row.currency === currency
                );
            rows.sort((a: BulkDebtRow, b: BulkDebtRow) => a.name.localeCompare(b.name, "fa"));
            setDebts(rows);
            setSelected(new Set());
        } catch {
            setDebts([]);
            setSelected(new Set());
            notify.error("finance.loadError");
        } finally {
            setIsLoading(false);
        }
    }, [branchId, currency, fromDate, toDate, notify]);

    useEffect(() => {
        loadDebts();
    }, [loadDebts, reloadKey]);

    useEffect(() => {
        setSelected(new Set());
    }, [branchId, currency, fromDate, toDate]);

    const selectedRows = useMemo(
        () => debts.filter((d) => selected.has(d.supplierAccountId)),
        [debts, selected]
    );

    const totalSelected = sumSelectedBalances(selectedRows);

    const toggle = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const toggleAll = () => {
        if (selected.size === debts.length) setSelected(new Set());
        else setSelected(new Set(debts.map((d) => d.supplierAccountId)));
    };

    const handleSubmit = async () => {
        if (!branchId || selectedRows.length === 0 || !fromDate || !toDate) return;
        setIsSubmitting(true);
        try {
            await apiRequest("/consignments/settle-bulk", {
                method: "POST",
                body: JSON.stringify(buildBulkSettlementPayload(selectedRows, {
                    start: fromDate,
                    end: toDate,
                })),
            });
            notify.success("toast.settlementSuccess");
            await loadDebts();
        } catch (error) {
            console.error("Bulk settlement failed:", error);
            const message = error instanceof Error ? error.message : "";
            if (message) notify.rawError(message);
            else notify.error("toast.settlementError");
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Card className="border border-white/70 bg-white/60 backdrop-blur-md rounded-2xl overflow-hidden">
            <CardHeader className="px-5 py-4 border-b border-ink/5 flex flex-row items-center justify-between gap-3">
                <CardTitle className="text-[13px] font-black font-vazirmatn text-ink flex items-center gap-2">
                    <Users className="w-4 h-4 text-primary" />
                    {t("finance.bulkSettlement.title")}
                </CardTitle>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 text-[10px]"
                    onClick={loadDebts}
                    disabled={isLoading || !branchId}
                >
                    {t("common.refresh")}
                </Button>
            </CardHeader>
            <CardContent className="p-5 space-y-4">
                <p className="text-[11px] text-ink/45 font-vazirmatn leading-relaxed">
                    {t("finance.bulkSettlement.hint")}
                </p>

                {!branchId ? (
                    <p className="text-[11px] text-ink/35 font-vazirmatn text-center py-6">
                        {t("expenses.form.selectBranch")}
                    </p>
                ) : (
                    <>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <label className="text-[9px] font-black text-ink/40 uppercase tracking-widest">
                                    {t("finance.settlement.fromDate")}
                                </label>
                                <input
                                    type="date"
                                    value={fromDate}
                                    onChange={(e) => setFromDate(e.target.value)}
                                    className="w-full h-10 rounded-xl border border-ink/10 bg-white/70 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                                />
                            </div>
                            <div className="space-y-1">
                                <label className="text-[9px] font-black text-ink/40 uppercase tracking-widest">
                                    {t("finance.settlement.toDate")}
                                </label>
                                <input
                                    type="date"
                                    value={toDate}
                                    onChange={(e) => setToDate(e.target.value)}
                                    className="w-full h-10 rounded-xl border border-ink/10 bg-white/70 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                                />
                            </div>
                        </div>

                        {isLoading ? (
                            <div className="space-y-2">
                                {Array.from({ length: 3 }).map((_, i) => (
                                    <div key={i} className="h-11 rounded-xl bg-parchment/30 animate-pulse" />
                                ))}
                            </div>
                        ) : debts.length === 0 ? (
                            <p className="text-[11px] text-ink/35 font-vazirmatn text-center py-6">
                                {t("finance.bulkSettlement.empty")}
                            </p>
                        ) : (
                            <div className="space-y-2">
                                <button
                                    type="button"
                                    onClick={toggleAll}
                                    className="text-[10px] font-black text-primary hover:underline"
                                >
                                    {selected.size === debts.length
                                        ? t("finance.bulkSettlement.deselectAll")
                                        : t("finance.bulkSettlement.selectAll")}
                                </button>
                                <ul className="divide-y divide-ink/5 rounded-xl border border-ink/5 bg-white/50 overflow-hidden">
                                    {debts.map((row) => {
                                        const checked = selected.has(row.supplierAccountId);
                                        return (
                                            <li key={row.supplierAccountId}>
                                                <label
                                                    className={cn(
                                                        "flex items-center gap-3 px-3.5 py-3 cursor-pointer transition-colors",
                                                        checked ? "bg-primary/[0.04]" : "hover:bg-parchment/30"
                                                    )}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggle(row.supplierAccountId)}
                                                        className="rounded border-ink/20 text-primary focus:ring-primary/30"
                                                    />
                                                    <span className="flex-1 text-[12px] font-black font-vazirmatn text-ink truncate">
                                                        {row.name}
                                                    </span>
                                                    <span className="text-[12px] font-black tabular-nums text-primary shrink-0">
                                                        {formatNumber(decimalStringToDisplayNumber(row.balance))} {currencySymbol}
                                                    </span>
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}

                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
                            <p className="text-[11px] font-vazirmatn text-ink/50">
                                {t("finance.bulkSettlement.selectedTotal")}:{" "}
                                <span className="font-black text-ink tabular-nums">
                                    {formatNumber(decimalStringToDisplayNumber(totalSelected))} {currencySymbol}
                                </span>
                                {selectedRows.length > 0 && (
                                    <span className="text-ink/35 ms-1">
                                        ({formatNumber(selectedRows.length)})
                                    </span>
                                )}
                            </p>
                            <Button
                                type="button"
                                variant="primary"
                                className="h-10 rounded-xl text-[11px] font-black"
                                disabled={!branchId || selectedRows.length === 0 || !fromDate || !toDate || isSubmitting}
                                onClick={handleSubmit}
                            >
                                {isSubmitting
                                    ? t("common.submitting")
                                    : t("finance.bulkSettlement.confirm")}
                            </Button>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
