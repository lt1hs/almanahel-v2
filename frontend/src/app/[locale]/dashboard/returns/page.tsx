"use client";

import React, { useState, useEffect, useCallback } from "react";
import { apiRequest } from "@/lib/api";
import { motion, Variants, AnimatePresence } from "framer-motion";
import {
    RotateCcw, Search, X, Plus, CheckCircle2, Package, Minus,
    Building2, Calendar, User, BookOpen, Hash, FileText,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { cn } from "@/lib/utils";
import { parsePriceDigits } from "@/lib/bookFormUtils";

const stagger: Variants = {
    hidden: { opacity: 0 },
    show: { opacity: 1, transition: { staggerChildren: 0.06 } },
};
const fadeUp: Variants = {
    hidden: { opacity: 0, y: 12 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 380, damping: 28 } },
};

type ReturnType = "customer" | "consignment";

function returnTotals(ret: any, isCustomer: boolean) {
    const items = Array.isArray(ret.items) ? ret.items : [];
    const totalQty = items.reduce((sum: number, item: any) => sum + Number(item.quantity || 0), 0);
    const titleCount = items.length;
    const totalValue = isCustomer
        ? Number(ret.refund_amount || 0)
        : items.reduce(
            (sum: number, item: any) => sum + Number(item.cost_price || 0) * Number(item.quantity || 0),
            0
        );
    return { totalQty, titleCount, totalValue };
}

interface ConsignmentItem {
    book_id: number;
    title: string;
    quantity: number;
    maxQty: number;
    cost_price: number;
}

export default function ReturnsPage() {
    const { t, formatNumber, formatDate, isArabic } = useTranslation();
    const currencySymbol = isArabic ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");
    const [activeTab, setActiveTab] = useState<ReturnType>("customer");
    const [returns, setReturns] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Customer return state
    const [invoiceSearch, setInvoiceSearch] = useState("");
    const [foundInvoice, setFoundInvoice] = useState<any>(null);
    const [refundMethod, setRefundMethod] = useState<"cash" | "credit">("cash");
    const [reason, setReason] = useState("");
    const [selectedItems, setSelectedItems] = useState<Record<number, boolean>>({});
    const [returnQtys, setReturnQtys] = useState<Record<number, number>>({});

    // Consignment return state
    const [suppliers, setSuppliers] = useState<any[]>([]);
    const [branches, setBranches] = useState<any[]>([]);
    const [supplierId, setSupplierId] = useState("");
    const [branchId, setBranchId] = useState("");
    const [consignmentItems, setConsignmentItems] = useState<ConsignmentItem[]>([]);
    const [consignmentReason, setConsignmentReason] = useState("");

    const resetForm = () => {
        setInvoiceSearch("");
        setFoundInvoice(null);
        setRefundMethod("cash");
        setReason("");
        setSelectedItems({});
        setReturnQtys({});
        setSupplierId("");
        setBranchId("");
        setConsignmentItems([]);
        setConsignmentReason("");
        setError(null);
    };

    const fetchReturns = useCallback(async () => {
        setIsLoading(true);
        try {
            const endpoint = activeTab === "customer" ? "/returns/customer" : "/returns/consignment";
            const data = await apiRequest(endpoint);
            setReturns(data.data || []);
        } catch (error) {
            console.error("Failed to fetch returns:", error);
        } finally {
            setIsLoading(false);
        }
    }, [activeTab]);

    useEffect(() => {
        fetchReturns();
    }, [fetchReturns]);

    useEffect(() => {
        if (showForm && activeTab === "consignment") {
            Promise.all([apiRequest("/suppliers"), apiRequest("/branches")])
                .then(([s, b]) => {
                    setSuppliers(Array.isArray(s) ? s : []);
                    setBranches((Array.isArray(b) ? b : []).filter((x: any) => x.type === "store" || x.type === "warehouse"));
                })
                .catch(console.error);
        }
    }, [showForm, activeTab]);

    const loadConsignmentInventory = async (supId: string, brId: string) => {
        if (!supId || !brId) return;
        try {
            const data = await apiRequest(`/warehouse/${brId}/inventory`);
            const items = (Array.isArray(data) ? data : [])
                .filter((inv: any) => inv.type === "consignment" && String(inv.supplier_id) === supId && inv.quantity > 0)
                .map((inv: any) => ({
                    book_id: inv.book_id,
                    title: inv.book?.title || t("distribution.bookFallback"),
                    quantity: 1,
                    maxQty: inv.quantity,
                    cost_price: inv.cost_price_toman || inv.cost_price_dinar || inv.price_toman || 0,
                }));
            setConsignmentItems(items);
        } catch {
            setConsignmentItems([]);
        }
    };

    useEffect(() => {
        if (supplierId && branchId) {
            loadConsignmentInventory(supplierId, branchId);
        }
    }, [supplierId, branchId]);

    const handleSearchInvoice = async () => {
        if (!invoiceSearch) return;
        try {
            const data = await apiRequest(`/invoices?search=${invoiceSearch}`);
            if (data.data?.length > 0) {
                const inv = data.data[0];
                setFoundInvoice(inv);
                const selected: Record<number, boolean> = {};
                const qtys: Record<number, number> = {};
                inv.items?.forEach((i: any) => {
                    selected[i.id] = true;
                    qtys[i.id] = Number(i.quantity) || 1;
                });
                setSelectedItems(selected);
                setReturnQtys(qtys);
            } else {
                setError(t("toast.invoiceNotFound"));
            }
        } catch {
            setError(t("toast.invoiceSearchError"));
        }
    };

    const handleSubmitReturn = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);
        setError(null);

        try {
            if (activeTab === "customer") {
                if (!foundInvoice) throw new Error(t("toast.invoiceNotSelected"));
                const items = foundInvoice.items
                    .filter((i: any) => selectedItems[i.id] && (Number(returnQtys[i.id]) || 0) > 0)
                    .map((i: any) => {
                        const maxQty = Number(i.quantity) || 0;
                        const qty = Math.max(1, Math.min(maxQty, Number(returnQtys[i.id]) || maxQty));
                        return {
                            book_id: i.book_id,
                            invoice_item_id: i.id,
                            quantity: qty,
                            unit_price: i.actual_price || i.unit_price,
                        };
                    });
                if (items.length === 0) throw new Error(t("toast.minOneItem"));

                await apiRequest("/returns/customer", {
                    method: "POST",
                    body: JSON.stringify({
                        invoice_id: foundInvoice.id,
                        items,
                        refund_method: refundMethod,
                        reason: reason || null,
                    }),
                });
            } else {
                const items = consignmentItems.filter((i) => i.quantity > 0);
                if (!supplierId || !branchId) throw new Error(t("toast.supplierBranchRequired"));
                if (items.length === 0) throw new Error(t("toast.minOneBook"));

                await apiRequest("/returns/consignment", {
                    method: "POST",
                    body: JSON.stringify({
                        supplier_id: Number(supplierId),
                        branch_id: Number(branchId),
                        reason: consignmentReason || null,
                        items: items.map((i) => ({
                            book_id: i.book_id,
                            quantity: i.quantity,
                            cost_price: i.cost_price,
                        })),
                    }),
                });
            }

            setShowForm(false);
            resetForm();
            fetchReturns();
        } catch (err) {
            setError((err as Error).message || t("toast.returnError"));
        } finally {
            setIsSubmitting(false);
        }
    };

    const updateConsignmentQty = (bookId: number, delta: number) => {
        setConsignmentItems((prev) =>
            prev.map((item) =>
                item.book_id === bookId
                    ? { ...item, quantity: Math.max(0, Math.min(item.maxQty, item.quantity + delta)) }
                    : item
            )
        );
    };

    const setConsignmentQtyInput = (bookId: number, raw: string) => {
        const digits = parsePriceDigits(raw);
        setConsignmentItems((prev) =>
            prev.map((item) => {
                if (item.book_id !== bookId) return item;
                if (!digits) return { ...item, quantity: 0 };
                const next = Math.max(0, Math.min(item.maxQty, parseInt(digits, 10) || 0));
                return { ...item, quantity: next };
            })
        );
    };

    const setCustomerReturnQty = (itemId: number, maxQty: number, raw: string) => {
        const digits = parsePriceDigits(raw);
        if (!digits) {
            setReturnQtys((prev) => ({ ...prev, [itemId]: 0 }));
            return;
        }
        const next = Math.max(0, Math.min(maxQty, parseInt(digits, 10) || 0));
        setReturnQtys((prev) => ({ ...prev, [itemId]: next }));
        if (next > 0) {
            setSelectedItems((prev) => ({ ...prev, [itemId]: true }));
        }
    };

    const QtyStepper = ({
        value,
        max,
        onDelta,
        onInput,
    }: {
        value: number;
        max: number;
        onDelta: (delta: number) => void;
        onInput: (raw: string) => void;
    }) => (
        <div className="flex items-center gap-1.5 shrink-0" onClick={(e) => e.stopPropagation()}>
            <button
                type="button"
                onClick={() => onDelta(-1)}
                disabled={value <= 0}
                className="w-7 h-7 rounded-lg border border-ink/10 flex items-center justify-center disabled:opacity-30 hover:bg-white"
            >
                <Minus className="w-3 h-3" />
            </button>
            <input
                type="text"
                inputMode="numeric"
                pattern="[0-9۰-۹٠-٩]*"
                value={value > 0 ? formatNumber(value) : ""}
                onChange={(e) => onInput(e.target.value)}
                onBlur={() => {
                    if (!value) onInput("0");
                }}
                onFocus={(e) => e.target.select()}
                className="w-12 h-7 rounded-lg border border-ink/10 bg-white px-1 text-center text-[12px] font-black font-vazirmatn tabular-nums outline-none focus:ring-1 focus:ring-primary/30"
                aria-label={t("returns.form.quantity")}
            />
            <button
                type="button"
                onClick={() => onDelta(1)}
                disabled={value >= max}
                className="w-7 h-7 rounded-lg border border-ink/10 flex items-center justify-center disabled:opacity-30 hover:bg-white"
            >
                <Plus className="w-3 h-3" />
            </button>
        </div>
    );

    const tabs = [
        { key: "customer" as const, label: t("returns.tabs.customer") },
        { key: "consignment" as const, label: t("returns.tabs.consignment") },
    ];

    return (
        <motion.div variants={stagger} initial="hidden" animate="show" className="space-y-5 pb-10">
            <motion.div variants={fadeUp} className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("returns.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">
                        {t("returns.subtitle")}
                    </p>
                </div>
                <Button variant="primary" size="sm" className="h-9 px-4 rounded-xl text-[11px]"
                    onClick={() => { resetForm(); setShowForm(true); }}>
                    <Plus className="w-3.5 h-3.5 ms-1.5" />
                    {t("returns.submit")}
                </Button>
            </motion.div>

            <motion.div variants={fadeUp} className="flex gap-1 bg-white/50 border border-white/70 rounded-xl p-1 w-fit">
                {tabs.map((tab) => (
                    <button key={tab.key} onClick={() => setActiveTab(tab.key)}
                        className={cn(
                            "px-5 py-2 rounded-[9px] font-black text-[11px] font-vazirmatn transition-all",
                            activeTab === tab.key ? "bg-white shadow-md text-primary" : "text-ink/35 hover:bg-white/50"
                        )}>
                        {tab.label}
                    </button>
                ))}
            </motion.div>

            <AnimatePresence mode="wait">
                {isLoading ? (
                    <div className="space-y-3">
                        {[1, 2, 3].map((i) => <div key={i} className="h-24 bg-parchment/20 rounded-2xl animate-pulse" />)}
                    </div>
                ) : returns.length > 0 ? (
                    <div key={activeTab} className="space-y-3">
                        {returns.map((ret) => {
                            const isCustomer = activeTab === "customer";
                            const { totalQty, titleCount, totalValue } = returnTotals(ret, isCustomer);
                            const partyName = isCustomer
                                ? (ret.invoice?.customer_name || t("returns.unknownCustomer"))
                                : (ret.supplier?.name || t("returns.unknownSupplier"));

                            return (
                                <Card key={ret.id} className="border border-white/70 bg-white/70 backdrop-blur-xl shadow-sm rounded-2xl overflow-hidden">
                                    <CardContent className="p-0">
                                        <div className="p-4 flex items-start gap-3.5">
                                            <div className={cn(
                                                "w-11 h-11 rounded-xl border flex items-center justify-center shrink-0",
                                                isCustomer
                                                    ? "bg-sky-50 border-sky-100 text-sky-600"
                                                    : "bg-indigo-50 border-indigo-100 text-indigo-500"
                                            )}>
                                                <RotateCcw className="w-5 h-5" />
                                            </div>

                                            <div className="flex-1 min-w-0 space-y-2.5">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <h3 className="text-[14px] font-black text-ink font-vazirmatn truncate">
                                                                {partyName}
                                                            </h3>
                                                            <Badge className="text-[8px] font-black border bg-parchment/80 text-ink/55 border-ink/8 font-vazirmatn tabular-nums">
                                                                {ret.return_number}
                                                            </Badge>
                                                            {isCustomer && ret.refund_method && (
                                                                <Badge className={cn(
                                                                    "text-[8px] font-black border",
                                                                    ret.refund_method === "cash"
                                                                        ? "bg-emerald-50 text-emerald-600 border-emerald-100"
                                                                        : "bg-amber-50 text-amber-700 border-amber-100"
                                                                )}>
                                                                    {ret.refund_method === "cash"
                                                                        ? t("returns.refund.cash")
                                                                        : t("returns.refund.credit")}
                                                                </Badge>
                                                            )}
                                                        </div>

                                                        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-ink/40 font-vazirmatn">
                                                            {ret.branch?.name && (
                                                                <span className="inline-flex items-center gap-1">
                                                                    <Building2 className="w-3 h-3" />
                                                                    {ret.branch.name}
                                                                </span>
                                                            )}
                                                            {isCustomer && ret.invoice?.invoice_number && (
                                                                <span className="inline-flex items-center gap-1">
                                                                    <Hash className="w-3 h-3" />
                                                                    {t("checks.invoice")} {ret.invoice.invoice_number}
                                                                </span>
                                                            )}
                                                            {ret.user?.name && (
                                                                <span className="inline-flex items-center gap-1">
                                                                    <User className="w-3 h-3" />
                                                                    {ret.user.name}
                                                                </span>
                                                            )}
                                                            {ret.created_at && (
                                                                <span className="inline-flex items-center gap-1 tabular-nums">
                                                                    <Calendar className="w-3 h-3" />
                                                                    {formatDate(ret.created_at)}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="text-end shrink-0">
                                                        {totalValue > 0 ? (
                                                            <>
                                                                <p className="text-[9px] font-bold text-ink/30 uppercase tracking-widest">
                                                                    {isCustomer ? t("returns.refundAmount") : t("returns.returnValue")}
                                                                </p>
                                                                <p className="text-[15px] font-black text-rose-500 font-vazirmatn tabular-nums leading-tight mt-0.5">
                                                                    {formatNumber(totalValue)}
                                                                    <span className="text-[10px] text-rose-400/70 ms-1 font-bold">{currencySymbol}</span>
                                                                </p>
                                                            </>
                                                        ) : (
                                                            <p className="text-[11px] font-bold text-ink/25">—</p>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <span className="inline-flex items-center gap-1.5 rounded-lg bg-parchment/70 border border-ink/5 px-2.5 py-1 text-[10px] font-black font-vazirmatn text-ink/60">
                                                        <Package className="w-3 h-3 text-ink/30" />
                                                        {formatNumber(totalQty)} {t("distribution.volumeUnit")}
                                                    </span>
                                                    <span className="inline-flex items-center gap-1.5 rounded-lg bg-parchment/70 border border-ink/5 px-2.5 py-1 text-[10px] font-black font-vazirmatn text-ink/60">
                                                        <BookOpen className="w-3 h-3 text-ink/30" />
                                                        {formatNumber(titleCount)} {t("distribution.titleUnit")}
                                                    </span>
                                                </div>

                                                {ret.items?.length > 0 && (
                                                    <div className="rounded-xl border border-ink/5 bg-white/60 overflow-hidden">
                                                        {ret.items.map((item: any, i: number) => {
                                                            const unit = isCustomer
                                                                ? Number(item.unit_price || 0)
                                                                : Number(item.cost_price || 0);
                                                            const line = unit * Number(item.quantity || 0);
                                                            return (
                                                                <div
                                                                    key={item.id || i}
                                                                    className={cn(
                                                                        "flex items-center justify-between gap-3 px-3 py-2",
                                                                        i > 0 && "border-t border-ink/5"
                                                                    )}
                                                                >
                                                                    <div className="min-w-0">
                                                                        <p className="text-[12px] font-bold font-vazirmatn text-ink truncate">
                                                                            {item.book?.title || t("distribution.bookFallback")}
                                                                        </p>
                                                                        {item.book?.author && (
                                                                            <p className="text-[9px] text-ink/35 font-vazirmatn truncate">
                                                                                {item.book.author}
                                                                            </p>
                                                                        )}
                                                                    </div>
                                                                    <div className="text-end shrink-0">
                                                                        <p className="text-[11px] font-black font-vazirmatn tabular-nums text-ink/70">
                                                                            × {formatNumber(item.quantity)}
                                                                        </p>
                                                                        {line > 0 && (
                                                                            <p className="text-[9px] font-bold font-vazirmatn tabular-nums text-ink/35">
                                                                                {formatNumber(line)} {currencySymbol}
                                                                            </p>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                )}

                                                {ret.reason && (
                                                    <div className="flex items-start gap-1.5 text-[10px] text-ink/45 font-vazirmatn leading-relaxed">
                                                        <FileText className="w-3 h-3 mt-0.5 shrink-0 text-ink/25" />
                                                        <span>{ret.reason}</span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                ) : (
                    <Card className="border border-white/70 bg-white/70 rounded-2xl">
                        <CardContent className="flex flex-col items-center py-16 gap-3 text-ink/20">
                            <Package className="w-10 h-10" />
                            <p className="text-[12px] font-black font-vazirmatn">{t("returns.empty")}</p>
                        </CardContent>
                    </Card>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {showForm && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink/30 backdrop-blur-sm"
                        onClick={(e) => e.target === e.currentTarget && setShowForm(false)}>
                        <motion.div initial={{ scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }}
                            className="w-full max-w-xl bg-white rounded-3xl shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">
                            <form onSubmit={handleSubmitReturn}>
                                <div className="flex items-center justify-between px-6 py-5 border-b border-ink/5 bg-indigo-50/30">
                                    <div>
                                        <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("returns.form.title")}</h2>
                                        <p className="text-[10px] text-ink/35 mt-0.5">
                                            {activeTab === "customer" ? t("returns.form.customerSubtitle") : t("returns.form.consignmentSubtitle")}
                                        </p>
                                    </div>
                                    <button type="button" onClick={() => setShowForm(false)} className="p-2 rounded-xl hover:bg-ink/5">
                                        <X className="w-4 h-4 text-ink/40" />
                                    </button>
                                </div>

                                <div className="p-6 space-y-4">
                                    {error && (
                                        <div className="px-3 py-2 rounded-xl bg-rose-50 border border-rose-100 text-rose-600 text-[11px] font-vazirmatn">
                                            {error}
                                        </div>
                                    )}

                                    {activeTab === "customer" ? (
                                        <>
                                            <div className="space-y-1.5">
                                                <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("returns.form.searchInvoice")}</label>
                                                <div className="flex gap-2">
                                                    <input type="text" value={invoiceSearch} onChange={(e) => setInvoiceSearch(e.target.value)}
                                                        placeholder={t("returns.form.invoicePlaceholder")}
                                                        className="flex-1 h-10 rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary" />
                                                    <Button type="button" size="sm" className="h-10 rounded-xl" onClick={handleSearchInvoice}>{t("returns.form.search")}</Button>
                                                </div>
                                            </div>
                                            {foundInvoice && (
                                                <div className="p-3 bg-primary/5 border border-primary/15 rounded-xl">
                                                    <div className="flex items-center justify-between mb-2">
                                                        <p className="text-[11px] font-black">{t("checks.invoice")} {foundInvoice.invoice_number}</p>
                                                        <CheckCircle2 className="w-4 h-4 text-primary" />
                                                    </div>
                                                    {foundInvoice.items?.map((item: any) => {
                                                        const maxQty = Number(item.quantity) || 0;
                                                        const qty = returnQtys[item.id] ?? maxQty;
                                                        const unit = Number(item.actual_price || item.unit_price || 0);
                                                        return (
                                                        <label key={item.id} className="flex items-center justify-between gap-3 py-2 px-2.5 rounded-lg bg-white/70 border border-ink/5 mb-1 cursor-pointer">
                                                            <div className="flex items-center gap-2 min-w-0 flex-1">
                                                                <input type="checkbox" checked={!!selectedItems[item.id]}
                                                                    onChange={(e) => setSelectedItems((p) => ({ ...p, [item.id]: e.target.checked }))}
                                                                    className="w-3.5 h-3.5 rounded accent-primary shrink-0" />
                                                                <div className="min-w-0">
                                                                    <span className="text-[11px] font-vazirmatn font-bold block truncate">{item.book?.title}</span>
                                                                    <span className="text-[9px] text-ink/35">
                                                                        {t("returns.form.maxQtyPrice")} · {formatNumber(maxQty)}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div className="flex items-center gap-2 shrink-0">
                                                                <QtyStepper
                                                                    value={selectedItems[item.id] ? qty : 0}
                                                                    max={maxQty}
                                                                    onDelta={(delta) => {
                                                                        const next = Math.max(0, Math.min(maxQty, qty + delta));
                                                                        setReturnQtys((p) => ({ ...p, [item.id]: next }));
                                                                        setSelectedItems((p) => ({ ...p, [item.id]: next > 0 }));
                                                                    }}
                                                                    onInput={(raw) => setCustomerReturnQty(item.id, maxQty, raw)}
                                                                />
                                                                <span className="text-[10px] text-primary font-vazirmatn tabular-nums min-w-[4.5rem] text-end">
                                                                    {formatNumber(unit * (selectedItems[item.id] ? qty : 0))} {currencySymbol}
                                                                </span>
                                                            </div>
                                                        </label>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                            <div className="space-y-1.5">
                                                <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("returns.form.refundMethod")}</label>
                                                <select value={refundMethod} onChange={(e) => setRefundMethod(e.target.value as "cash" | "credit")}
                                                    className="w-full h-10 rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary">
                                                    <option value="cash">{t("returns.form.refundCash")}</option>
                                                    <option value="credit">{t("returns.form.refundCredit")}</option>
                                                </select>
                                            </div>
                                            <div className="space-y-1.5">
                                                <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("returns.form.reason")}</label>
                                                <textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)}
                                                    placeholder={t("returns.form.notesPlaceholder")} className="w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 py-2.5 text-[12px] font-vazirmatn outline-none resize-none" />
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="space-y-1.5">
                                                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("finance.settlement.supplier")}</label>
                                                    <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)}
                                                        className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none">
                                                        <option value="">{t("finance.settlement.selectSupplier")}</option>
                                                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                                    </select>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("distribution.branchFallback")}</label>
                                                    <select value={branchId} onChange={(e) => setBranchId(e.target.value)}
                                                        className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none">
                                                        <option value="">{t("expenses.form.selectBranch")}</option>
                                                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                                    </select>
                                                </div>
                                            </div>
                                            {consignmentItems.length > 0 ? (
                                                <div className="space-y-2">
                                                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("returns.form.consignmentBooks")}</label>
                                                    {consignmentItems.map((item) => (
                                                        <div key={item.book_id} className="flex items-center justify-between gap-3 p-3 rounded-xl border border-ink/8 bg-parchment/10">
                                                            <div className="min-w-0">
                                                                <p className="text-[11px] font-black font-vazirmatn truncate">{item.title}</p>
                                                                <p className="text-[9px] text-ink/35">
                                                                    {t("returns.form.maxQtyPrice")} · {formatNumber(item.maxQty)} · {formatNumber(item.cost_price)} {currencySymbol}
                                                                </p>
                                                            </div>
                                                            <QtyStepper
                                                                value={item.quantity}
                                                                max={item.maxQty}
                                                                onDelta={(delta) => updateConsignmentQty(item.book_id, delta)}
                                                                onInput={(raw) => setConsignmentQtyInput(item.book_id, raw)}
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : supplierId && branchId ? (
                                                <p className="text-[11px] text-ink/35 text-center py-4">{t("returns.form.noConsignmentBooks")}</p>
                                            ) : null}
                                            <div className="space-y-1.5">
                                                <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest">{t("returns.form.reason")}</label>
                                                <textarea rows={2} value={consignmentReason} onChange={(e) => setConsignmentReason(e.target.value)}
                                                    placeholder={t("returns.form.notesPlaceholder")} className="w-full rounded-xl border border-ink/10 px-3 py-2.5 text-[12px] font-vazirmatn outline-none resize-none" />
                                            </div>
                                        </>
                                    )}
                                </div>

                                <div className="flex gap-2 px-6 py-4 bg-parchment/20 border-t border-ink/5">
                                    <Button type="button" variant="ghost" className="flex-1 h-10 rounded-xl" onClick={() => setShowForm(false)}>{t("common.cancel")}</Button>
                                    <Button type="submit" disabled={isSubmitting} className="flex-1 h-10 rounded-xl font-black">
                                        {isSubmitting ? t("common.submitting") : t("returns.submit")}
                                    </Button>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>
        </motion.div>
    );
}
