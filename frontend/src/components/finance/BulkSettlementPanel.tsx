"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Users } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";

interface SupplierOption {
    id: number;
    name: string;
}

interface DebtRow {
    supplierId: number;
    name: string;
    balance: number;
    currency: string;
}

interface BulkSettlementPanelProps {
    suppliers: SupplierOption[];
    currency: "toman" | "dinar";
    currencySymbol: string;
}

function monthStart(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export function BulkSettlementPanel({
    suppliers,
    currency,
    currencySymbol,
}: BulkSettlementPanelProps) {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [debts, setDebts] = useState<DebtRow[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [fromDate, setFromDate] = useState(monthStart);
    const [toDate, setToDate] = useState(todayIso);
    const [isLoading, setIsLoading] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const supplierName = useCallback(
        (id: number) => suppliers.find((s) => s.id === id)?.name || `#${id}`,
        [suppliers]
    );

    const loadDebts = useCallback(async () => {
        setIsLoading(true);
        try {
            const data = await apiRequest("/consignments/unsettled-by-supplier");
            const rows: DebtRow[] = [];
            Object.entries(data || {}).forEach(([sid, entries]) => {
                if (!Array.isArray(entries)) return;
                const supplierId = Number(sid);
                entries.forEach((entry: { currency?: string; balance?: number }) => {
                    if (entry.currency !== currency) return;
                    const balance = Number(entry.balance || 0);
                    if (balance <= 0) return;
                    rows.push({
                        supplierId,
                        name: supplierName(supplierId),
                        balance,
                        currency: entry.currency || currency,
                    });
                });
            });
            rows.sort((a, b) => a.name.localeCompare(b.name, "fa"));
            setDebts(rows);
            setSelected(new Set());
        } catch {
            setDebts([]);
            notify.error("finance.loadError");
        } finally {
            setIsLoading(false);
        }
    }, [currency, supplierName, notify]);

    useEffect(() => {
        loadDebts();
    }, [loadDebts]);

    const selectedRows = useMemo(
        () => debts.filter((d) => selected.has(d.supplierId)),
        [debts, selected]
    );

    const totalSelected = selectedRows.reduce((sum, row) => sum + row.balance, 0);

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
        else setSelected(new Set(debts.map((d) => d.supplierId)));
    };

    const handleSubmit = async () => {
        if (selectedRows.length === 0 || !fromDate || !toDate) return;
        setIsSubmitting(true);
        try {
            await apiRequest("/consignments/settle-bulk", {
                method: "POST",
                body: JSON.stringify({
                    atomic: true,
                    period_type: "custom",
                    period_start: fromDate,
                    period_end: toDate,
                    payment_method: "bank_transfer",
                    settlements: selectedRows.map((row) => ({
                        supplier_id: row.supplierId,
                        amount: row.balance,
                        currency: row.currency,
                    })),
                }),
            });
            notify.success("toast.settlementSuccess");
            await loadDebts();
        } catch (error) {
            console.error("Bulk settlement failed:", error);
            notify.error("toast.settlementError");
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
                    disabled={isLoading}
                >
                    {t("common.refresh")}
                </Button>
            </CardHeader>
            <CardContent className="p-5 space-y-4">
                <p className="text-[11px] text-ink/45 font-vazirmatn leading-relaxed">
                    {t("finance.bulkSettlement.hint")}
                </p>

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
                                const checked = selected.has(row.supplierId);
                                return (
                                    <li key={row.supplierId}>
                                        <label
                                            className={cn(
                                                "flex items-center gap-3 px-3.5 py-3 cursor-pointer transition-colors",
                                                checked ? "bg-primary/[0.04]" : "hover:bg-parchment/30"
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => toggle(row.supplierId)}
                                                className="rounded border-ink/20 text-primary focus:ring-primary/30"
                                            />
                                            <span className="flex-1 text-[12px] font-black font-vazirmatn text-ink truncate">
                                                {row.name}
                                            </span>
                                            <span className="text-[12px] font-black tabular-nums text-primary shrink-0">
                                                {formatNumber(row.balance)} {currencySymbol}
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
                            {formatNumber(totalSelected)} {currencySymbol}
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
                        disabled={selectedRows.length === 0 || !fromDate || !toDate || isSubmitting}
                        onClick={handleSubmit}
                    >
                        {isSubmitting
                            ? t("common.submitting")
                            : t("finance.bulkSettlement.confirm")}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
