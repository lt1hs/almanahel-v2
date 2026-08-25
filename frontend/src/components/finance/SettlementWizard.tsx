"use client";

import React, { useEffect, useState } from "react";
import { Calculator, Download, Send, ChevronDown, Building2, BookOpen, CalendarDays } from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { printSettlement, buildSettlementPrintLabels } from "@/lib/printSettlement";

interface SettlementItem {
    title: string;
    qty: number;
    price: number;
    total: number;
    commission: number;
    publisherShare?: number;
    kind?: "sale" | "gift";
}

export type SettlementBreakdown = {
    sales_payable?: string | number;
    gift_payable?: string | number;
    return_reversals?: string | number;
    previously_settled?: string | number;
    remaining_payable?: string | number;
};

function toDateInput(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

function defaultPeriod(): { from: string; to: string } {
    const to = new Date();
    const from = new Date(to.getFullYear(), to.getMonth(), 1);
    return { from: toDateInput(from), to: toDateInput(to) };
}

function periodPreset(kind: "month" | "90d" | "year"): { from: string; to: string } {
    const to = new Date();
    if (kind === "month") {
        return { from: toDateInput(new Date(to.getFullYear(), to.getMonth(), 1)), to: toDateInput(to) };
    }
    if (kind === "90d") {
        const from = new Date(to);
        from.setDate(from.getDate() - 89);
        return { from: toDateInput(from), to: toDateInput(to) };
    }
    return { from: toDateInput(new Date(to.getFullYear(), 0, 1)), to: toDateInput(to) };
}

interface SettlementWizardProps {
    suppliers: { id: number; name: string }[];
    onCalculate: (supplierAccountId: number, fromDate: string, toDate: string) => Promise<void>;
    onConfirm?: (supplierAccountId: number, fromDate: string, toDate: string, amount: number) => Promise<void>;
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
}: SettlementWizardProps) {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const symbol = currencySymbol || t("common.currency.tomanSymbol");
    const initialDates = defaultPeriod();
    const [selectedSupplier, setSelectedSupplier] = useState<any>(null);
    const [open, setOpen] = useState(false);
    const [fromDate, setFromDate] = useState(initialDates.from);
    const [toDate, setToDate] = useState(initialDates.to);
    const [settleAmount, setSettleAmount] = useState("");
    const [activePreset, setActivePreset] = useState<"month" | "90d" | "year" | "custom">("month");

    useEffect(() => {
        const next = defaultPeriod();
        setFromDate(next.from);
        setToDate(next.to);
        setActivePreset("month");
        setSettleAmount("");
    }, [sessionKey]);

    useEffect(() => {
        if (!suppliers.length || disabled || !branchId) {
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
    }, [suppliers, initialSupplierAccountId, disabled, branchId, sessionKey]);

    const totalPayable = settlementData.reduce(
        (acc, item) => acc + (item.publisherShare ?? item.total - item.commission), 0
    );

    useEffect(() => {
        setSettleAmount("");
    }, [totalPayable, sessionKey]);

    const applyPreset = (kind: "month" | "90d" | "year") => {
        const next = periodPreset(kind);
        setFromDate(next.from);
        setToDate(next.to);
        setActivePreset(kind);
    };

    const tableHeaders = [
        t("finance.settlement.table.book"),
        t("finance.settlement.table.soldQty"),
        t("finance.settlement.table.total"),
        t("finance.settlement.table.commission"),
        t("finance.settlement.table.publisherShare"),
    ];

    const handlePrint = () => {
        if (!selectedSupplier || settlementData.length === 0) return;
        try {
            printSettlement({
                supplierName: selectedSupplier.name,
                fromDate,
                toDate,
                items: settlementData,
                formatNumber,
                currencySymbol: symbol,
                labels: buildSettlementPrintLabels(t),
                dir: "rtl",
            });
        } catch {
            notify.error("toast.invoicePrintError");
        }
    };

    const amountNum = Number(settleAmount);
    const amountValid =
        Number.isFinite(amountNum) && amountNum > 0 && amountNum <= totalPayable + 1e-9;

    const confirmAmount = () => {
        if (!selectedSupplier || !amountValid) return;
        onConfirm?.(selectedSupplier.id, fromDate, toDate, amountNum);
    };

    const breakdownRows = [
        { key: "sales", label: "فروش", value: breakdown?.sales_payable },
        { key: "gifts", label: "هدایا", value: breakdown?.gift_payable },
        { key: "reversals", label: "برگشت/تعدیل", value: breakdown?.return_reversals },
        { key: "settled", label: "تسویه‌شده قبلی", value: breakdown?.previously_settled },
        { key: "remaining", label: "مانده قابل تسویه", value: breakdown?.remaining_payable ?? totalPayable },
    ];

    const canCalculate = Boolean(branchId && selectedSupplier && fromDate && toDate && !isLoading && !disabled);

    return (
        <div className="space-y-4">
            <Card className="relative z-10 border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl overflow-visible shadow-sm">
                <CardContent className="p-5 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2 text-ink/45">
                            <CalendarDays className="w-3.5 h-3.5" />
                            <p className="text-[10px] font-black uppercase tracking-widest">بازه تسویه</p>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {(
                                [
                                    { key: "month", label: "این ماه" },
                                    { key: "90d", label: "۹۰ روز" },
                                    { key: "year", label: "امسال" },
                                ] as const
                            ).map((p) => (
                                <button
                                    key={p.key}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => applyPreset(p.key)}
                                    className={cn(
                                        "h-7 rounded-lg px-2.5 text-[10px] font-black transition-all",
                                        activePreset === p.key
                                            ? "bg-primary text-white shadow-sm"
                                            : "bg-parchment/50 text-ink/45 hover:bg-parchment hover:text-ink"
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
                                disabled={disabled || !branchId}
                                onClick={() => setOpen((v) => !v)}
                                className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 flex items-center justify-between text-[12px] font-black font-vazirmatn disabled:opacity-40"
                            >
                                <span className="truncate">{selectedSupplier?.name || t("finance.settlement.selectSupplier")}</span>
                                <ChevronDown className="w-4 h-4 opacity-40 shrink-0" />
                            </button>
                            {open && (
                                <div className="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-xl border border-ink/10 bg-white shadow-xl">
                                    {suppliers.length === 0 ? (
                                        <p className="px-3 py-3 text-[11px] text-ink/35">تأمین‌کننده‌ای برای این شعبه نیست</p>
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
                                disabled={disabled || !branchId}
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
                                disabled={disabled || !branchId}
                                className="h-11 w-full rounded-xl border border-ink/10 bg-white/90 px-3 text-[12px] font-vazirmatn outline-none focus:border-primary/30 disabled:opacity-40"
                            />
                        </div>

                        <div className="lg:col-span-2 flex items-end">
                            <button
                                type="button"
                                disabled={!canCalculate}
                                onClick={() => selectedSupplier && onCalculate(selectedSupplier.id, fromDate, toDate)}
                                className="h-11 w-full rounded-xl bg-primary text-white text-[12px] font-black font-vazirmatn flex items-center justify-center gap-2 disabled:opacity-40 shadow-sm shadow-primary/20"
                            >
                                <Calculator className="w-4 h-4" />
                                {isLoading ? t("common.loading") : t("finance.settlement.calculate")}
                            </button>
                        </div>
                    </div>

                    {!branchId && (
                        <p className="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2 flex items-center gap-1.5">
                            <Building2 className="w-3.5 h-3.5 shrink-0" />
                            {t("expenses.form.selectBranch")}
                        </p>
                    )}
                </CardContent>
            </Card>

            {breakdown && settlementData.length > 0 && (
                <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">
                    {breakdownRows.map((row) => (
                        <Card key={row.key} className="rounded-xl border border-white/70 bg-white/70">
                            <CardContent className="p-3">
                                <p className="text-[8px] font-black uppercase tracking-wider text-ink/35 mb-1">{row.label}</p>
                                <p className="text-[13px] font-black font-vazirmatn tabular-nums text-ink">
                                    {formatNumber(Number(row.value || 0))}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            <Card className="border border-white/70 bg-white/50 backdrop-blur-md rounded-2xl overflow-hidden hidden md:block">
                <CardContent className="p-0">
                    {isLoading && (
                        <div className="p-8 space-y-3">
                            {Array.from({ length: 3 }).map((_, i) => (
                                <div key={i} className="h-10 bg-parchment/30 rounded-xl animate-pulse" />
                            ))}
                        </div>
                    )}
                    {!isLoading && settlementData.length > 0 && (
                        <table className="w-full text-end">
                            <thead>
                                <tr className="border-b border-ink/5 bg-parchment/20">
                                    {tableHeaders.map((h) => (
                                        <th key={h} className="px-5 py-3 text-[9px] font-black uppercase tracking-widest text-ink/30">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {settlementData.map((item, i) => (
                                    <tr key={i} className="border-b border-ink/5 last:border-0">
                                        <td className="px-5 py-3.5">
                                            <div className="flex items-center justify-end gap-2">
                                                <span className="text-[12px] font-black font-vazirmatn text-ink">
                                                    {item.title}
                                                    {item.kind === "gift" ? " (هدیه)" : ""}
                                                </span>
                                                <div className="w-7 h-7 rounded-lg bg-parchment/60 border border-ink/5 flex items-center justify-center shrink-0">
                                                    <BookOpen className="w-3.5 h-3.5 text-ink/20" />
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-5 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-ink/60">
                                            {formatNumber(item.qty)}
                                            <span className="text-[9px] text-ink/30 ms-1">{t("common.units.volume")}</span>
                                        </td>
                                        <td className="px-5 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-ink/60">
                                            {formatNumber(item.total)}
                                        </td>
                                        <td className="px-5 py-3.5 text-[12px] font-black font-vazirmatn tabular-nums text-rose-400">
                                            {formatNumber(item.commission)}
                                        </td>
                                        <td className="px-5 py-3.5 text-[14px] font-black font-vazirmatn tabular-nums text-primary">
                                            {formatNumber(item.publisherShare ?? item.total - item.commission)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {settlementData.length === 0 && !isLoading && (
                        <div className="p-10 text-center text-ink/25 font-black text-[11px] font-vazirmatn">
                            {t("finance.settlement.noData")}
                        </div>
                    )}
                </CardContent>
            </Card>

            <div className="space-y-3 md:hidden">
                {isLoading && (
                    Array.from({ length: 2 }).map((_, i) => (
                        <div key={i} className="h-24 bg-parchment/20 rounded-2xl animate-pulse" />
                    ))
                )}
                {!isLoading && settlementData.map((item, i) => (
                    <Card key={i} className="border border-white/70 bg-white/50 backdrop-blur-md rounded-2xl overflow-hidden">
                        <CardContent className="p-4 space-y-3">
                            <div className="flex items-center justify-between">
                                <span className="text-[9px] font-black text-ink/30 font-vazirmatn">
                                    {formatNumber(item.qty)} {t("common.units.volume")}
                                </span>
                                <span className="text-[13px] font-black font-vazirmatn text-ink">{item.title}</span>
                            </div>
                            <div className="grid grid-cols-3 gap-2 pt-2 border-t border-ink/5">
                                <MobileStat label={t("finance.settlement.total")} value={formatNumber(item.total)} />
                                <MobileStat label={t("finance.settlement.commission")} value={formatNumber(item.commission)} danger />
                                <MobileStat label={t("finance.settlement.publisherShare")} value={formatNumber(item.publisherShare ?? item.total - item.commission)} primary />
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-col sm:flex-row items-center justify-between gap-5 p-5 text-white rounded-2xl shadow-xl border border-primary/20 relative overflow-hidden bg-gradient-to-br from-[#0b1e1e] via-[#0d2626] to-ink/95">
                <div className="absolute inset-0 bg-gradient-to-l from-accent/5 via-transparent to-primary/15 pointer-events-none" />

                <div className="relative z-10 text-center sm:text-end space-y-3 w-full sm:w-auto">
                    <div>
                        <p className="text-[10px] font-black font-vazirmatn text-white/40 uppercase tracking-widest mb-2">
                            {t("finance.settlement.finalPayable")}
                        </p>
                        <div className="flex items-end gap-2 justify-center sm:justify-end">
                            <span className="text-[28px] font-black font-vazirmatn tabular-nums text-primary leading-none">
                                {formatNumber(totalPayable)}
                            </span>
                            <span className="text-[12px] font-black font-vazirmatn text-white/25 mb-1">{symbol}</span>
                        </div>
                    </div>
                    <div className="text-start sm:text-end space-y-2">
                        <label className="text-[9px] font-black uppercase tracking-widest text-white/40 block">
                            مبلغ تسویه (جزئی مجاز)
                        </label>
                        <input
                            type="number"
                            min={0}
                            step="0.01"
                            max={totalPayable || undefined}
                            value={settleAmount}
                            onChange={(e) => setSettleAmount(e.target.value)}
                            disabled={disabled || settlementData.length === 0}
                            placeholder={totalPayable > 0 ? String(totalPayable) : "0"}
                            className="h-10 w-full sm:w-48 rounded-xl border border-white/20 bg-white/10 px-3 text-[13px] font-black font-vazirmatn tabular-nums text-white outline-none disabled:opacity-40 placeholder:text-white/25"
                        />
                        {totalPayable > 0 && (
                            <div className="flex gap-2 justify-center sm:justify-end">
                                <button
                                    type="button"
                                    disabled={disabled || settlementData.length === 0}
                                    onClick={() => setSettleAmount(String(Math.round(totalPayable / 2)))}
                                    className="text-[9px] font-black text-white/50 hover:text-white underline disabled:opacity-40"
                                >
                                    ۵۰٪
                                </button>
                                <button
                                    type="button"
                                    disabled={disabled || settlementData.length === 0}
                                    onClick={() => setSettleAmount(String(totalPayable))}
                                    className="text-[9px] font-black text-white/50 hover:text-white underline disabled:opacity-40"
                                >
                                    کل مانده
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex gap-2.5 w-full sm:w-auto relative z-10">
                    <button
                        type="button"
                        disabled={disabled || !branchId || settlementData.length === 0 || !selectedSupplier}
                        onClick={handlePrint}
                        className="flex-1 sm:flex-none flex items-center justify-center gap-2 h-11 px-5 rounded-xl border border-white/15 text-white/70 hover:bg-white/10 hover:text-white disabled:opacity-40 text-[11.5px] font-black font-vazirmatn transition-all"
                    >
                        <Download className="w-4 h-4 shrink-0" />
                        {t("finance.settlement.downloadPdf")}
                    </button>
                    <button
                        type="button"
                        disabled={
                            disabled ||
                            !branchId ||
                            settlementData.length === 0 ||
                            !selectedSupplier ||
                            !fromDate ||
                            !toDate ||
                            isConfirming ||
                            !amountValid
                        }
                        onClick={confirmAmount}
                        className="flex-1 sm:flex-none flex items-center justify-center gap-2 h-11 px-5 rounded-xl bg-accent hover:bg-accent/90 disabled:opacity-40 text-white shadow-lg shadow-accent/25 text-[11.5px] font-black font-vazirmatn transition-all active:scale-[0.98]"
                    >
                        <Send className="w-4 h-4 shrink-0" />
                        {isConfirming ? t("common.submitting") : t("finance.settlement.confirmDeposit")}
                    </button>
                </div>
            </div>
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
