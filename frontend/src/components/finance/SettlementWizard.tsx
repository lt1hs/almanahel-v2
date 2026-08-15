"use client";

import React, { useEffect, useState } from "react";
import { Calculator, Download, Send, ChevronDown, Building2, BookOpen } from "lucide-react";
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
}

interface SettlementWizardProps {
    suppliers: { id: number; name: string }[];
    onCalculate: (supplierId: number, fromDate: string, toDate: string) => Promise<void>;
    onConfirm?: (supplierId: number, fromDate: string, toDate: string, amount: number) => Promise<void>;
    settlementData: SettlementItem[];
    isLoading?: boolean;
    isConfirming?: boolean;
    initialSupplierId?: number | null;
    currencySymbol?: string;
}

export function SettlementWizard({
    suppliers,
    onCalculate,
    onConfirm,
    settlementData,
    isLoading,
    isConfirming,
    initialSupplierId,
    currencySymbol,
}: SettlementWizardProps) {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const symbol = currencySymbol || t("common.currency.tomanSymbol");
    const [selectedSupplier, setSelectedSupplier] = useState<any>(null);
    const [open, setOpen] = useState(false);
    const [fromDate, setFromDate] = useState("");
    const [toDate, setToDate] = useState("");

    useEffect(() => {
        if (!suppliers.length) return;
        const fromUrl = initialSupplierId
            ? suppliers.find((s) => s.id === initialSupplierId)
            : null;
        setSelectedSupplier((prev: any) => {
            if (prev && suppliers.some((s) => s.id === prev.id)) return prev;
            return fromUrl || suppliers[0] || null;
        });
    }, [suppliers, initialSupplierId]);

    const totalPayable = settlementData.reduce(
        (acc, item) => acc + (item.total - item.commission), 0
    );

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

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 p-4 bg-white/50 backdrop-blur-md rounded-2xl border border-white/70 shadow-sm">
                <div className="space-y-1.5">
                    <label className="text-[10px] font-black font-vazirmatn text-ink/40 uppercase tracking-wider block">
                        {t("finance.settlement.supplier")}
                    </label>
                    <div className="relative">
                        <button type="button" onClick={() => setOpen((v) => !v)}
                            className="w-full h-10 bg-white border border-ink/10 rounded-xl ps-3 pe-9 text-[12px] font-vazirmatn text-ink text-end flex items-center justify-between hover:border-primary/30 transition-colors outline-none focus:ring-2 focus:ring-primary/15 shadow-sm">
                            <ChevronDown className={cn("w-3.5 h-3.5 text-ink/30 transition-transform duration-200 shrink-0", open && "rotate-180")} />
                            <span className="truncate">{selectedSupplier?.name || t("finance.settlement.selectSupplier")}</span>
                        </button>
                        {open && (
                            <div className="absolute top-full mt-1.5 inset-x-0 bg-white border border-ink/8 rounded-xl shadow-xl overflow-hidden z-20 max-h-48 overflow-y-auto">
                                {suppliers.length === 0 ? (
                                    <p className="px-4 py-3 text-[11px] text-ink/30 text-center">{t("finance.settlement.loadingSuppliers")}</p>
                                ) : suppliers.map((s) => (
                                    <button key={s.id} type="button"
                                        onClick={() => { setSelectedSupplier(s); setOpen(false); }}
                                        className={cn(
                                            "w-full px-4 py-2.5 text-end text-[12px] font-vazirmatn transition-colors hover:bg-primary/5 hover:text-primary",
                                            s.id === selectedSupplier?.id ? "text-primary font-black bg-primary/5" : "text-ink/70"
                                        )}>
                                        {s.name}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <label className="text-[10px] font-black font-vazirmatn text-ink/40 uppercase tracking-wider block">
                        {t("finance.settlement.fromDate")}
                    </label>
                    <input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)}
                        aria-label={t("finance.settlement.fromDate")}
                        className="w-full h-10 bg-white border border-ink/10 rounded-xl px-3 text-[12px] font-vazirmatn text-ink outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/30 transition-all shadow-sm" />
                </div>
                <div className="space-y-1.5">
                    <label className="text-[10px] font-black font-vazirmatn text-ink/40 uppercase tracking-wider block">
                        {t("finance.settlement.toDate")}
                    </label>
                    <input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)}
                        aria-label={t("finance.settlement.toDate")}
                        className="w-full h-10 bg-white border border-ink/10 rounded-xl px-3 text-[12px] font-vazirmatn text-ink outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/30 transition-all shadow-sm" />
                </div>

                <div className="md:col-span-3 pt-1">
                    <button
                        type="button"
                        disabled={!selectedSupplier || !fromDate || !toDate || isLoading}
                        onClick={() => onCalculate(selectedSupplier.id, fromDate, toDate)}
                        className="w-full md:w-auto flex items-center justify-center gap-2 h-10 px-6 rounded-xl bg-primary hover:bg-primary/90 disabled:opacity-50 text-white text-[12px] font-black font-vazirmatn shadow-md shadow-primary/20 transition-all active:scale-[0.98]">
                        <Calculator className={cn("w-4 h-4", isLoading && "animate-spin")} />
                        {isLoading ? t("finance.settlement.calculating") : t("finance.settlement.calculate")}
                    </button>
                </div>
            </div>

            {selectedSupplier && (
                <div className="flex items-center gap-3 px-4 py-3 bg-primary/5 border border-primary/15 rounded-xl">
                    <div className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center shrink-0">
                        <Building2 className="w-4 h-4 text-primary" />
                    </div>
                    <div>
                        <p className="text-[12px] font-black font-vazirmatn text-ink">{selectedSupplier.name}</p>
                        <p className="text-[9px] text-ink/30 font-bold mt-px">
                            {t("finance.settlement.supplier")} · SUP-{selectedSupplier.id}
                        </p>
                    </div>
                </div>
            )}

            <Card className="border border-white/70 bg-white/50 backdrop-blur-md shadow-sm rounded-2xl overflow-hidden hidden md:block min-h-[100px]">
                <CardContent className="p-0">
                    {isLoading ? (
                        <div className="p-8 space-y-2">
                            {Array.from({ length: 3 }).map((_, i) => (
                                <div key={i} className="h-10 bg-parchment/20 rounded-lg animate-pulse" />
                            ))}
                        </div>
                    ) : (
                        <table className="w-full text-end border-collapse">
                            <thead>
                                <tr className="bg-white/40 border-b border-ink/[0.06]">
                                    {tableHeaders.map((h) => (
                                        <th key={h} className="px-5 py-3.5 font-black font-vazirmatn text-[10px] text-ink/40 uppercase tracking-wider">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink/[0.04]">
                                {settlementData.map((item, i) => (
                                    <tr key={i} className="hover:bg-white/60 transition-colors duration-150 group">
                                        <td className="px-5 py-3.5">
                                            <div className="flex items-center justify-end gap-2.5">
                                                <span className="text-[12.5px] font-black font-vazirmatn text-ink group-hover:text-primary transition-colors">
                                                    {item.title}
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
                                            {formatNumber(item.total - item.commission)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {settlementData.length === 0 && !isLoading && (
                        <div className="p-10 text-center text-ink/20 font-black uppercase tracking-widest text-[10px]">
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
                                <MobileStat label={t("finance.settlement.publisherShare")} value={formatNumber(item.total - item.commission)} primary />
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-col sm:flex-row items-center justify-between gap-5 p-5 text-white rounded-2xl shadow-xl border border-primary/20 relative overflow-hidden bg-gradient-to-br from-[#0b1e1e] via-[#0d2626] to-ink/95">
                <div className="absolute inset-0 bg-gradient-to-l from-accent/5 via-transparent to-primary/15 pointer-events-none" />

                <div className="relative z-10 text-center sm:text-end">
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

                <div className="flex gap-2.5 w-full sm:w-auto relative z-10">
                    <button
                        type="button"
                        disabled={settlementData.length === 0 || !selectedSupplier}
                        onClick={handlePrint}
                        className="flex-1 sm:flex-none flex items-center justify-center gap-2 h-11 px-5 rounded-xl border border-white/15 text-white/70 hover:bg-white/10 hover:text-white disabled:opacity-40 text-[11.5px] font-black font-vazirmatn transition-all"
                    >
                        <Download className="w-4 h-4 shrink-0" />
                        {t("finance.settlement.downloadPdf")}
                    </button>
                    <button
                        type="button"
                        disabled={settlementData.length === 0 || !selectedSupplier || !fromDate || !toDate || isConfirming}
                        onClick={() => onConfirm?.(selectedSupplier.id, fromDate, toDate, totalPayable)}
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
