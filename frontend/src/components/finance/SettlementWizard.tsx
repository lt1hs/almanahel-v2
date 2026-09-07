"use client";

import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Calculator, ChevronDown, Building2, BookOpen, CalendarDays,
    FileText, ScrollText, CheckCircle2, Banknote, Landmark, Palette,
} from "lucide-react";
import { Link } from "@/i18n/routing";
import { cacheSettlementInvoiceRow } from "@/lib/settlementInvoiceLayout";
import { Card, CardContent } from "@/components/ui/Card";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { printSettlement, buildSettlementPrintLabels } from "@/lib/printSettlement";
import { ALL_BRANCHES_VALUE } from "@/lib/supplierAccountSelection";
import {
    defaultSettlementPeriod,
    invoicePeriodFromSettlement,
    periodPreset,
} from "@/lib/settlementPeriod";
import { groupSettlementDisplayRows } from "@/lib/settlementDisplayRows";

interface SettlementItem {
    title: string;
    qty: number;
    remainingQty?: number;
    price: number;
    total: number;
    commission: number;
    publisherShare?: number;
    kind?: "sale" | "gift";
    branchId?: number | null;
    branchName?: string | null;
    bookId?: number | null;
}

export type SettlementConfirmResult = {
    id?: number;
    settlement_number?: string;
    period_start?: string | null;
    period_end?: string | null;
    settlements?: Array<{
        id?: number;
        settlement_number?: string;
        period_start?: string | null;
        period_end?: string | null;
    }>;
} | false;

type IssuedInvoice = {
    supplierName: string;
    fromDate: string;
    toDate: string;
    amount: number;
    items: SettlementItem[];
    docNumber: string;
    settlementId?: number;
};

export type SettlementBranchShare = {
    branch_id: number;
    branch_name?: string | null;
    remaining_payable?: string | number;
    remaining_qty?: string | number;
};

export type SettlementBreakdown = {
    sales_payable?: string | number;
    gift_payable?: string | number;
    return_reversals?: string | number;
    previously_settled?: string | number;
    remaining_payable?: string | number;
    by_branch?: SettlementBranchShare[];
};

function groupSettlementByBranch(items: SettlementItem[]): { branchId: number | null; branchName: string; items: SettlementItem[] }[] {
    const groups = new Map<string, { branchId: number | null; branchName: string; items: SettlementItem[] }>();
    for (const item of items) {
        const branchId = item.branchId != null && Number.isFinite(item.branchId) ? Number(item.branchId) : null;
        const key = branchId != null ? `b:${branchId}` : "none";
        const existing = groups.get(key);
        if (existing) {
            existing.items.push(item);
            continue;
        }
        groups.set(key, {
            branchId,
            branchName: item.branchName || (branchId != null ? `#${branchId}` : ""),
            items: [item],
        });
    }
    return Array.from(groups.values());
}

function uniqueRemainingQty(items: SettlementItem[]): number {
    const seen = new Set<string>();
    let sum = 0;
    for (const item of items) {
        const key = `${item.branchId ?? ""}:${item.title}`;
        if (seen.has(key)) continue;
        seen.add(key);
        sum += Number(item.remainingQty ?? 0);
    }
    return sum;
}

interface SettlementWizardProps {
    suppliers: { id: number; name: string }[];
    onCalculate: (supplierAccountId: number, fromDate: string, toDate: string, allOpen?: boolean) => Promise<void>;
    onConfirm?: (
        supplierAccountId: number,
        fromDate: string,
        toDate: string,
        amount: number,
        allOpen?: boolean
    ) => Promise<SettlementConfirmResult | void>;
    settlementData: SettlementItem[];
    breakdown?: SettlementBreakdown | null;
    isLoading?: boolean;
    isConfirming?: boolean;
    initialSupplierAccountId?: number | null;
    currencySymbol?: string;
    branchId?: number | null;
    disabled?: boolean;
    sessionKey?: number;
    /** Optional branch picker embedded in the same filter card */
    branches?: { id: number; name: string }[];
    selectedBranchId?: string;
    onBranchChange?: (branchId: string) => void;
    showBranchPicker?: boolean;
    allowAllBranches?: boolean;
    autoCalculate?: boolean;
}

export function SettlementWizard({
    suppliers,
    onCalculate,
    onConfirm,
    settlementData,
    breakdown = null,
    isLoading,
    isConfirming,
    initialSupplierAccountId,
    currencySymbol,
    branchId,
    disabled = false,
    sessionKey = 0,
    branches = [],
    selectedBranchId = "",
    onBranchChange,
    showBranchPicker = false,
    allowAllBranches = false,
    autoCalculate = false,
}: SettlementWizardProps) {
    const { t, formatNumber, language } = useTranslation();
    const notify = useNotify();
    const symbol = currencySymbol || t("common.currency.tomanSymbol");
    const initialDates = defaultSettlementPeriod();
    const [selectedSupplier, setSelectedSupplier] = useState<any>(null);
    const [open, setOpen] = useState(false);
    const [fromDate, setFromDate] = useState(initialDates.from);
    const [toDate, setToDate] = useState(initialDates.to);
    const [settleAmount, setSettleAmount] = useState("");
    const [activePreset, setActivePreset] = useState<SettlementPreset>(initialDates.preset);
    const [issuedInvoice, setIssuedInvoice] = useState<IssuedInvoice | null>(null);
    const autoCalcKeyRef = useRef("");

    useEffect(() => {
        const next = defaultSettlementPeriod();
        setFromDate(next.from);
        setToDate(next.to);
        setActivePreset(next.preset);
        setSettleAmount("");
        autoCalcKeyRef.current = "";
    }, [sessionKey]);

    const allBranchesMode = Boolean(allowAllBranches && selectedBranchId === ALL_BRANCHES_VALUE);
    const hasScope = Boolean(branchId) || allBranchesMode;

    useEffect(() => {
        if (!suppliers.length || disabled || !hasScope) {
            setSelectedSupplier(null);
            return;
        }
        const fromUrl = initialSupplierAccountId
            ? suppliers.find((s) => s.id === initialSupplierAccountId)
            : null;
        setSelectedSupplier((prev: any) => {
            if (prev && suppliers.some((s) => s.id === prev.id)) return prev;
            return fromUrl || suppliers[0] || null;
        });
    }, [suppliers, initialSupplierAccountId, disabled, hasScope, sessionKey]);

    const totalPayable = settlementData.reduce(
        (acc, item) => acc + (item.publisherShare ?? item.total - item.commission), 0
    );

    useEffect(() => {
        setSettleAmount(totalPayable > 0 ? String(totalPayable) : "");
    }, [totalPayable, sessionKey]);

    const allOpen = activePreset === "all";

    const applyPreset = (kind: "all" | "month" | "90d" | "year") => {
        if (kind === "all") {
            const next = defaultSettlementPeriod();
            setFromDate(next.from);
            setToDate(next.to);
            setActivePreset("all");
            return;
        }
        const next = periodPreset(kind);
        setFromDate(next.from);
        setToDate(next.to);
        setActivePreset(kind);
    };

    const tableHeaders = [
        t("finance.settlement.table.book"),
        t("finance.settlement.table.soldQty"),
        t("finance.settlement.table.remainingQty"),
        t("finance.settlement.table.total"),
        t("finance.settlement.table.publisherShare"),
    ];

    const branchGroups = useMemo(
        () => groupSettlementByBranch(groupSettlementDisplayRows(settlementData)),
        [settlementData]
    );
    const splitByBranch = allBranchesMode || branchGroups.length > 1;
    const displayRows = useMemo(
        () => branchGroups.flatMap((group) => group.items),
        [branchGroups]
    );

    const printArgs = (items: SettlementItem[], extra?: Partial<Parameters<typeof printSettlement>[0]>) => ({
        supplierName: extra?.supplierName || selectedSupplier?.name || "",
        fromDate: extra?.fromDate || fromDate,
        toDate: extra?.toDate || toDate,
        items,
        formatNumber,
        currencySymbol: symbol,
        labels: buildSettlementPrintLabels(t),
        dir: "rtl" as const,
        lang: (language === "ar" ? "ar" : "fa") as "fa" | "ar",
        ...extra,
    });

    const handlePrintReport = async () => {
        if (!selectedSupplier || settlementData.length === 0) return;
        try {
            await printSettlement(printArgs(displayRows, { variant: "report" }));
        } catch {
            notify.error("toast.invoicePrintError");
        }
    };

    const handlePrintInvoice = async (issued?: IssuedInvoice | null) => {
        const doc = issued || issuedInvoice;
        if (!doc) return;
        try {
            await printSettlement(printArgs(doc.items, {
                variant: "invoice",
                supplierName: doc.supplierName,
                fromDate: doc.fromDate,
                toDate: doc.toDate,
                settledAmount: doc.amount,
                docNumber: doc.docNumber,
                settlementId: doc.settlementId,
            }));
        } catch {
            notify.error("toast.invoicePrintError");
        }
    };

    const amountNum = Number(settleAmount);
    const amountValid =
        Number.isFinite(amountNum) && amountNum > 0 && amountNum <= totalPayable + 1e-9;

    const confirmAmount = async () => {
        if (!selectedSupplier || !amountValid || !onConfirm) return;
        const result = await onConfirm(selectedSupplier.id, fromDate, toDate, amountNum, allOpen);
        if (result === false) return;
        const settlementId = result?.id || result?.settlements?.[0]?.id;
        const docNumber =
            result?.settlement_number ||
            result?.settlements?.[0]?.settlement_number ||
            `SET-${new Date().toISOString().slice(0, 10).replace(/-/g, "")}`;
        const invoicePeriod = invoicePeriodFromSettlement(allOpen, fromDate, toDate, result || null);
        const snapshot: IssuedInvoice = {
            supplierName: selectedSupplier.name,
            fromDate: invoicePeriod.from,
            toDate: invoicePeriod.to,
            amount: amountNum,
            items: displayRows,
            docNumber: "",
        };
        if (settlementId) {
            cacheSettlementInvoiceRow({
                id: settlementId,
                settlement_number: docNumber,
                amount: amountNum,
                period_start: invoicePeriod.from,
                period_end: invoicePeriod.to,
                paid_at: new Date().toISOString(),
                supplier: { name: selectedSupplier.name },
            });
        }
        setIssuedInvoice({
            ...snapshot,
            docNumber,
            settlementId,
        });
    };

    const amountEntered = Number.isFinite(amountNum) && amountNum > 0;
    const leftover = Math.max(0, totalPayable - (amountEntered ? amountNum : 0));
    const overMax = amountEntered && !amountValid;

    const breakdownRows = [
        { key: "sales", label: t("finance.settlement.salesPayable"), value: breakdown?.sales_payable },
        { key: "gifts", label: t("finance.settlement.giftPayable"), value: breakdown?.gift_payable },
        { key: "reversals", label: t("finance.settlement.returnReversals"), value: breakdown?.return_reversals },
        { key: "settled", label: t("finance.settlement.previouslySettled"), value: breakdown?.previously_settled },
        { key: "remaining", label: t("finance.settlement.remainingPayable"), value: breakdown?.remaining_payable ?? totalPayable, emphasize: true },
    ];

    const canCalculate = Boolean(
        hasScope && selectedSupplier && (allOpen || (fromDate && toDate)) && !isLoading && !disabled
    );

    useEffect(() => {
        if (!autoCalculate || !canCalculate || !selectedSupplier) return;
        const key = `${sessionKey}:${selectedSupplier.id}:${selectedBranchId}:${activePreset}:${fromDate}:${toDate}`;
        if (autoCalcKeyRef.current === key) return;
        autoCalcKeyRef.current = key;
        void onCalculate(selectedSupplier.id, fromDate, toDate, allOpen);
    }, [
        autoCalculate,
        canCalculate,
        selectedSupplier,
        sessionKey,
        selectedBranchId,
        activePreset,
        fromDate,
        toDate,
        allOpen,
        onCalculate,
    ]);

    return (
        <div className="space-y-4">
            <Card className="relative z-10 overflow-visible rounded-3xl border border-white/80 bg-white/75 shadow-[0_18px_70px_rgba(23,32,31,0.06)] backdrop-blur-xl">
                <CardContent className="space-y-4 p-5 pt-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <CalendarDays className="h-4 w-4" />
                            </div>
                            <div>
                                <p className="text-[11px] font-black text-ink">{t("finance.settlement.periodLabel")}</p>
                                <p className="text-[9px] font-bold text-ink/35">{t("finance.settlement.period")}</p>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {(
                                [
                                    { key: "all", label: t("finance.settlement.presetAllOpen") },
                                    { key: "month", label: t("finance.settlement.presetThisMonth") },
                                    { key: "90d", label: t("finance.settlement.preset90d") },
                                    { key: "year", label: t("finance.settlement.presetThisYear") },
                                ] as const
                            ).map((p) => (
                                <button
                                    key={p.key}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => applyPreset(p.key)}
                                    className={cn(
                                        "h-8 rounded-xl px-3 text-[10px] font-black transition-all",
                                        activePreset === p.key
                                            ? "bg-primary text-white shadow-sm shadow-primary/20"
                                            : "border border-ink/5 bg-parchment/60 text-ink/45 hover:border-primary/20 hover:text-ink"
                                    )}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div
                        className={cn(
                            "grid grid-cols-1 gap-3",
                            showBranchPicker ? "lg:grid-cols-12" : "lg:grid-cols-10"
                        )}
                    >
                        {showBranchPicker && (
                            <div className="lg:col-span-3 space-y-1.5">
                                <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 flex items-center gap-1.5">
                                    <Building2 className="w-3 h-3" />
                                    {t("distribution.branchFallback")}
                                </label>
                                <select
                                    value={selectedBranchId}
                                    onChange={(e) => onBranchChange?.(e.target.value)}
                                    disabled={disabled}
                                    className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 text-[12px] font-vazirmatn outline-none focus:border-primary/30 disabled:opacity-40"
                                >
                                    <option value="">{t("expenses.form.selectBranch")}</option>
                                    {allowAllBranches && (
                                        <option value={ALL_BRANCHES_VALUE}>{t("finance.ledger.allBranches")}</option>
                                    )}
                                    {branches.map((b) => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className={cn("relative space-y-1.5", showBranchPicker ? "lg:col-span-3" : "lg:col-span-3")}>
                            <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 block">
                                {t("finance.settlement.selectSupplier")}
                            </label>
                            <button
                                type="button"
                                disabled={disabled || !hasScope}
                                onClick={() => setOpen((v) => !v)}
                                className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 flex items-center justify-between text-[12px] font-black font-vazirmatn disabled:opacity-40"
                            >
                                <span className="truncate">{selectedSupplier?.name || t("finance.settlement.selectSupplier")}</span>
                                <ChevronDown className="w-4 h-4 opacity-40 shrink-0" />
                            </button>
                            {open && (
                                <div className="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-xl border border-ink/10 bg-white shadow-xl">
                                    {suppliers.length === 0 ? (
                                        <p className="px-3 py-3 text-[11px] text-ink/35">{t("finance.settlement.noSuppliers")}</p>
                                    ) : (
                                        suppliers.map((s) => (
                                            <button
                                                key={s.id}
                                                type="button"
                                                className="w-full text-start px-3 py-2.5 text-[12px] font-vazirmatn hover:bg-parchment/40"
                                                onClick={() => {
                                                    setSelectedSupplier(s);
                                                    setOpen(false);
                                                }}
                                            >
                                                {s.name}
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="lg:col-span-2 space-y-1.5">
                            <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 block">
                                {t("finance.settlement.fromDate")}
                            </label>
                            <input
                                type="date"
                                value={fromDate}
                                onChange={(e) => {
                                    setFromDate(e.target.value);
                                    setActivePreset("custom");
                                }}
                                disabled={disabled || !hasScope || allOpen}
                                className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 text-[12px] font-vazirmatn outline-none focus:border-primary/30 disabled:opacity-40"
                            />
                        </div>

                        <div className="lg:col-span-2 space-y-1.5">
                            <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 block">
                                {t("finance.settlement.toDate")}
                            </label>
                            <input
                                type="date"
                                value={toDate}
                                onChange={(e) => {
                                    setToDate(e.target.value);
                                    setActivePreset("custom");
                                }}
                                disabled={disabled || !hasScope || allOpen}
                                className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 text-[12px] font-vazirmatn outline-none focus:border-primary/30 disabled:opacity-40"
                            />
                        </div>

                        <div className="lg:col-span-2 flex items-end">
                            <button
                                type="button"
                                disabled={!canCalculate}
                                onClick={() => selectedSupplier && onCalculate(selectedSupplier.id, fromDate, toDate, allOpen)}
                                className="h-11 w-full rounded-xl bg-primary text-white text-[12px] font-black font-vazirmatn flex items-center justify-center gap-2 disabled:opacity-40 shadow-sm shadow-primary/20"
                            >
                                <Calculator className="w-4 h-4" />
                                {isLoading ? t("common.loading") : t("finance.settlement.calculate")}
                            </button>
                        </div>
                    </div>

                    {!hasScope && (
                        <p className="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2 flex items-center gap-1.5">
                            <Building2 className="w-3.5 h-3.5 shrink-0" />
                            {t("expenses.form.selectBranch")}
                        </p>
                    )}
                    {allOpen && (
                        <p className="text-[11px] font-bold text-ink/50 bg-parchment/70 border border-ink/5 rounded-xl px-3 py-2">
                            {t("finance.settlement.allOpenHint")}
                        </p>
                    )}
                    {allBranchesMode && (
                        <p className="text-[11px] font-bold text-ink/50 bg-parchment/70 border border-ink/5 rounded-xl px-3 py-2">
                            {t("finance.settlement.aggregateHint")}
                        </p>
                    )}
                </CardContent>
            </Card>

            {breakdown && settlementData.length > 0 && (
                <div className="space-y-2">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    {breakdownRows.map((row) => (
                        <Card
                            key={row.key}
                            className={cn(
                                "rounded-2xl border bg-white/75 shadow-sm",
                                row.emphasize ? "border-primary/20 bg-primary/[0.04]" : "border-white/70"
                            )}
                        >
                            <CardContent className="p-3.5 pt-3.5">
                                <p className="mb-1 text-[8px] font-black uppercase tracking-wider text-ink/35">{row.label}</p>
                                <p className={cn(
                                    "font-vazirmatn text-[13px] font-black tabular-nums",
                                    row.emphasize ? "text-primary" : "text-ink"
                                )}>
                                    {formatNumber(Number(row.value || 0))}
                                    <span className="ms-1 text-[9px] font-bold text-ink/25">{symbol}</span>
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                </div>
            )}

            {isLoading && (
                <Card className="overflow-hidden rounded-3xl border border-white/80 bg-white/70 shadow-[0_18px_70px_rgba(23,32,31,0.04)]">
                    <CardContent className="space-y-3 p-8 pt-8">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div key={i} className="h-10 animate-pulse rounded-xl bg-parchment/30" />
                        ))}
                    </CardContent>
                </Card>
            )}

            {issuedInvoice && (
                <Card className="overflow-hidden rounded-3xl border border-emerald-100 bg-emerald-50/80">
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4 pt-4">
                        <div>
                            <p className="text-[12px] font-black font-vazirmatn text-ink">{t("finance.settlement.invoiceReady")}</p>
                            <p className="mt-0.5 text-[10px] font-bold text-ink/40">
                                {issuedInvoice.docNumber} · {formatNumber(issuedInvoice.amount)} {symbol}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {issuedInvoice.settlementId ? (
                                <Link
                                    href={`/dashboard/consignment/settle/invoice?id=${issuedInvoice.settlementId}`}
                                    className="flex h-10 items-center justify-center gap-2 rounded-xl border border-ink/10 bg-white px-4 text-[11px] font-black text-ink/60"
                                >
                                    <Palette className="h-4 w-4 shrink-0" />
                                    {t("consignment.settle.invoice.design")}
                                </Link>
                            ) : null}
                            <button
                                type="button"
                                onClick={() => handlePrintInvoice(issuedInvoice)}
                                className="flex h-10 items-center justify-center gap-2 rounded-xl bg-primary px-4 text-[11px] font-black text-white"
                            >
                                <ScrollText className="h-4 w-4 shrink-0" />
                                {t("finance.settlement.printInvoice")}
                            </button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {!isLoading && settlementData.length === 0 && !issuedInvoice && (
                <Card className="overflow-hidden rounded-3xl border border-white/80 bg-white/70">
                    <CardContent className="p-10 pt-10 text-center text-[11px] font-black font-vazirmatn text-ink/25">
                        {t("finance.settlement.noData")}
                    </CardContent>
                </Card>
            )}

            {!isLoading && settlementData.length > 0 && (
                <div className="space-y-4">
                    {branchGroups.map((group) => {
                        const payable = group.items.reduce(
                            (acc, item) => acc + (item.publisherShare ?? item.total - item.commission),
                            0
                        );
                        return (
                            <Card
                                key={group.branchId ?? "none"}
                                className="overflow-hidden rounded-3xl border border-white/80 bg-white/70 shadow-[0_18px_70px_rgba(23,32,31,0.04)] backdrop-blur-md"
                            >
                                <CardContent className="p-0 pt-0">
                                    {splitByBranch && group.branchName ? (
                                        <div className="flex items-center justify-between gap-3 border-b border-ink/5 bg-parchment/35 px-5 py-3.5">
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                                    <Building2 className="h-4 w-4" />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate text-[13px] font-black font-vazirmatn text-ink">{group.branchName}</p>
                                                    <p className="text-[10px] font-bold text-ink/35">
                                                        {t("finance.settlement.branchStock")}: {formatNumber(uniqueRemainingQty(group.items))} {t("common.units.volume")}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="shrink-0 font-vazirmatn text-[13px] font-black tabular-nums text-primary">
                                                {formatNumber(payable)}
                                                <span className="ms-1 text-[9px] font-bold text-ink/30">{symbol}</span>
                                            </p>
                                        </div>
                                    ) : null}
                                    <div className="hidden md:block">
                                        <SettlementBooksTable
                                            items={group.items}
                                            headers={tableHeaders}
                                            formatNumber={formatNumber}
                                            giftLabel={t("finance.settlement.giftItem")}
                                            unitLabel={t("common.units.volume")}
                                        />
                                    </div>
                                    <div className="space-y-3 p-3 md:hidden">
                                        {group.items.map((item, i) => (
                                            <div key={`${group.branchId ?? "none"}-${i}`} className="rounded-2xl border border-ink/5 bg-white/80 p-3.5">
                                                <p className="text-start text-[13px] font-black font-vazirmatn text-ink">
                                                    {item.title}
                                                    {item.kind === "gift" ? ` (${t("finance.settlement.giftItem")})` : ""}
                                                </p>
                                                <div className="mt-3 grid grid-cols-3 gap-2 border-t border-ink/5 pt-2">
                                                    <MobileStat label={t("finance.settlement.table.soldQty")} value={formatNumber(item.qty)} />
                                                    <MobileStat label={t("finance.settlement.table.remainingQty")} value={formatNumber(item.remainingQty ?? 0)} />
                                                    <MobileStat label={t("finance.settlement.publisherShare")} value={formatNumber(item.publisherShare ?? item.total - item.commission)} primary />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            <div className="sticky bottom-20 z-20 md:bottom-4">
                <div className="overflow-hidden rounded-3xl border border-white/80 bg-white/90 shadow-[0_18px_70px_rgba(23,32,31,0.12)] backdrop-blur-xl">
                    <div className="h-1 w-full bg-gradient-to-l from-accent via-primary to-primary/40" />
                    <div className="grid gap-4 p-4 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_auto] md:items-end md:p-5">
                        <div className="flex items-start gap-3 rounded-2xl border border-primary/10 bg-primary/[0.045] px-4 py-3.5">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/20">
                                <Landmark className="h-5 w-5" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-[10px] font-bold text-ink/40">{t("finance.settlement.finalPayable")}</p>
                                <p className="mt-1 font-vazirmatn text-[26px] font-black leading-none tabular-nums text-primary">
                                    {formatNumber(totalPayable)}
                                    <span className="ms-1 text-[11px] font-bold text-ink/30">{symbol}</span>
                                </p>
                                {selectedSupplier?.name && (
                                    <p className="mt-1.5 truncate text-[10px] font-bold text-ink/35">{selectedSupplier.name}</p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-2">
                                <label className="flex items-center gap-1.5 text-[10px] font-black text-ink/45">
                                    <Banknote className="h-3.5 w-3.5" />
                                    {t("finance.settlement.partialAmount")}
                                </label>
                                <span className="text-[9px] font-bold text-ink/30">{t("finance.settlement.partialHint")}</span>
                            </div>
                            <input
                                type="number"
                                min={0}
                                step="0.01"
                                max={totalPayable || undefined}
                                value={settleAmount}
                                onChange={(e) => setSettleAmount(e.target.value)}
                                disabled={disabled || settlementData.length === 0}
                                placeholder={totalPayable > 0 ? String(totalPayable) : "0"}
                                className={cn(
                                    "h-12 w-full rounded-xl border bg-white px-3 font-vazirmatn text-[15px] font-black tabular-nums text-ink outline-none transition-all placeholder:text-ink/20 disabled:opacity-40",
                                    overMax ? "border-rose-300 ring-2 ring-rose-100" : "border-ink/10 focus:border-primary/30 focus:ring-2 focus:ring-primary/10"
                                )}
                            />
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex gap-1.5">
                                    <button
                                        type="button"
                                        disabled={disabled || settlementData.length === 0 || totalPayable <= 0}
                                        onClick={() => setSettleAmount(String(Math.round(totalPayable / 2)))}
                                        className="h-7 rounded-lg border border-ink/10 bg-parchment/70 px-2.5 text-[9px] font-black text-ink/50 hover:border-primary/20 hover:text-primary disabled:opacity-40"
                                    >
                                        {t("finance.settlement.half")}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={disabled || settlementData.length === 0 || totalPayable <= 0}
                                        onClick={() => setSettleAmount(String(totalPayable))}
                                        className="h-7 rounded-lg border border-ink/10 bg-parchment/70 px-2.5 text-[9px] font-black text-ink/50 hover:border-primary/20 hover:text-primary disabled:opacity-40"
                                    >
                                        {t("finance.settlement.fullRemaining")}
                                    </button>
                                </div>
                                {overMax ? (
                                    <p className="text-[9px] font-bold text-rose-500">{t("finance.settlement.overMax")}</p>
                                ) : amountEntered ? (
                                    <p className="text-[9px] font-bold text-ink/35">
                                        {t("finance.settlement.remainingAfter")}: {formatNumber(leftover)} {symbol}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex w-full gap-2 md:w-auto md:flex-col lg:flex-row">
                            <button
                                type="button"
                                disabled={disabled || !hasScope || settlementData.length === 0 || !selectedSupplier}
                                onClick={handlePrintReport}
                                className="flex h-12 flex-1 items-center justify-center gap-2 rounded-xl border border-ink/10 bg-white px-3 text-[11px] font-black text-ink/55 transition-all hover:border-primary/20 hover:text-primary disabled:opacity-40 md:min-w-[8.5rem]"
                            >
                                <FileText className="h-4 w-4 shrink-0" />
                                {t("finance.settlement.printReport")}
                            </button>
                            <button
                                type="button"
                                disabled={
                                    disabled ||
                                    !hasScope ||
                                    settlementData.length === 0 ||
                                    !selectedSupplier ||
                                    (!allOpen && (!fromDate || !toDate)) ||
                                    isConfirming ||
                                    !amountValid
                                }
                                onClick={confirmAmount}
                                className="flex h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-accent px-4 text-[11px] font-black text-ink shadow-lg shadow-accent/25 transition-all hover:bg-accent/90 active:scale-[0.98] disabled:opacity-40 md:min-w-[11.5rem]"
                            >
                                <CheckCircle2 className="h-4 w-4 shrink-0" />
                                {isConfirming ? t("common.submitting") : t("finance.settlement.confirmDeposit")}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function SettlementBooksTable({
    items,
    headers,
    formatNumber,
    giftLabel,
    unitLabel,
}: {
    items: SettlementItem[];
    headers: string[];
    formatNumber: (n: number) => string;
    giftLabel: string;
    unitLabel: string;
}) {
    return (
        <div className="overflow-x-auto" dir="rtl">
            <table className="w-full min-w-[40rem] border-collapse text-start">
                <thead>
                    <tr className="border-b border-ink/5 bg-parchment/25">
                        {headers.map((header, index) => (
                            <th
                                key={header}
                                className={cn(
                                    "py-3 text-[9px] font-black uppercase tracking-widest text-ink/35",
                                    index === 0 ? "ps-5 pe-3" : "px-3",
                                    index === headers.length - 1 && "pe-5"
                                )}
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {items.map((item, i) => (
                        <tr key={i} className="border-b border-ink/5 last:border-0 even:bg-parchment/15">
                            <td className="py-3.5 ps-5 pe-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-ink/5 bg-parchment/60">
                                        <BookOpen className="h-3.5 w-3.5 text-ink/20" />
                                    </div>
                                    <span className="text-[12px] font-black font-vazirmatn text-ink">
                                        {item.title}
                                        {item.kind === "gift" ? ` (${giftLabel})` : ""}
                                    </span>
                                </div>
                            </td>
                            <td className="px-3 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-ink/65">
                                {formatNumber(item.qty)}
                                <span className="ms-1 text-[9px] text-ink/30">{unitLabel}</span>
                            </td>
                            <td className="px-3 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-ink/65">
                                {formatNumber(item.remainingQty ?? 0)}
                                <span className="ms-1 text-[9px] text-ink/30">{unitLabel}</span>
                            </td>
                            <td className="px-3 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-ink/65">
                                {formatNumber(item.total)}
                            </td>
                            <td className="py-3.5 pe-5 ps-3 text-[14px] font-black font-vazirmatn tabular-nums text-primary">
                                {formatNumber(item.publisherShare ?? item.total - item.commission)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function MobileStat({ label, value, danger, primary }: {
    label: string; value: string; danger?: boolean; primary?: boolean;
}) {
    return (
        <div className="text-center">
            <p className="text-[8px] font-black text-ink/30 uppercase tracking-wider mb-1">{label}</p>
            <p className={cn(
                "text-[11px] font-black font-vazirmatn tabular-nums",
                danger ? "text-rose-400" : primary ? "text-primary" : "text-ink/60"
            )}>
                {value}
            </p>
        </div>
    );
}
