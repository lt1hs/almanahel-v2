"use client";

import React, { useState, useEffect, useCallback, Suspense } from "react";
import {
    ArrowRight, Receipt, Store, User, Phone, CalendarDays,
    CreditCard, Banknote, ShoppingBag, BookOpen, AlertTriangle, Printer,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useSearchParams } from "next/navigation";
import { useRouter, Link } from "@/i18n/routing";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";
import { useNotify } from "@/hooks/useNotify";
import { printInvoice, buildInvoicePrintLabels } from "@/lib/printInvoice";

function formatDueDate(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

function formatDateTime(value: string | null | undefined): string {
    if (!value) return "—";
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value).slice(0, 19).replace("T", " ");
    return d.toLocaleString("fa-IR", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function InvoiceDetailContent() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const searchParams = useSearchParams();
    const invoiceId = searchParams.get("id");
    const autoPrint = searchParams.get("print") === "1";

    const [invoice, setInvoice] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [isPrinting, setIsPrinting] = useState(false);
    const didAutoPrint = React.useRef(false);

    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");

    const fetchInvoice = useCallback(async () => {
        if (!invoiceId) {
            setIsLoading(false);
            setError(t("sales.invoiceDetail.notFound"));
            setInvoice(null);
            return;
        }
        setIsLoading(true);
        setError(null);
        try {
            const data = await apiRequest(`/invoices/${invoiceId}`);
            setInvoice(data);
        } catch (err) {
            console.error(err);
            setError(err instanceof Error ? err.message : t("sales.invoiceDetail.notFound"));
            setInvoice(null);
        } finally {
            setIsLoading(false);
        }
    }, [invoiceId, t]);

    useEffect(() => {
        fetchInvoice();
    }, [fetchInvoice]);

    const handlePrint = useCallback((inv = invoice) => {
        if (!inv) return;
        setIsPrinting(true);
        try {
            printInvoice(inv, {
                formatNumber,
                currencySymbol: inv.currency === "dinar" ? dinarSymbol : tomanSymbol,
                labels: buildInvoicePrintLabels(t),
                dir: "rtl",
            });
        } catch {
            notify.error("toast.invoicePrintError");
        } finally {
            setIsPrinting(false);
        }
    }, [invoice, formatNumber, dinarSymbol, tomanSymbol, t, notify]);

    useEffect(() => {
        if (!autoPrint || !invoice || isLoading || didAutoPrint.current) return;
        didAutoPrint.current = true;
        handlePrint(invoice);
        if (typeof window !== "undefined") {
            const url = new URL(window.location.href);
            url.searchParams.delete("print");
            window.history.replaceState({}, "", url.pathname + url.search);
        }
    }, [autoPrint, invoice, isLoading, handlePrint]);

    const paymentLabel = (method: string) => {
        if (method === "check") return t("sales.check");
        if (method === "credit") return t("sales.credit");
        if (method === "card") return t("sales.card");
        return t("sales.cash");
    };

    const statusLabel = (status: string) => {
        if (status === "paid") return t("sales.invoiceDetail.statusPaid");
        if (status === "overdue") return t("sales.invoiceDetail.statusOverdue");
        return t("sales.invoiceDetail.statusPending");
    };

    const statusClass = (status: string) => {
        if (status === "paid") return "bg-emerald-50 text-emerald-600 border-emerald-100";
        if (status === "overdue") return "bg-rose-50 text-rose-600 border-rose-100";
        return "bg-amber-50 text-amber-600 border-amber-100";
    };

    const PaymentIcon = invoice?.payment_method === "check"
        ? CreditCard
        : invoice?.payment_method === "credit"
            ? ShoppingBag
            : Banknote;

    if (isLoading) {
        return (
            <div className="space-y-4 max-w-3xl mx-auto">
                <div className="h-10 w-48 bg-parchment/30 rounded-xl animate-pulse" />
                <div className="h-40 bg-parchment/20 rounded-2xl animate-pulse" />
                <div className="h-56 bg-parchment/20 rounded-2xl animate-pulse" />
            </div>
        );
    }

    if (!invoice) {
        return (
            <div className="max-w-lg mx-auto text-center py-16 space-y-4">
                <AlertTriangle className="w-8 h-8 text-ink/25 mx-auto" />
                <p className="text-[13px] font-black text-ink/40 font-vazirmatn">{error || t("sales.invoiceDetail.notFound")}</p>
                <Button onClick={() => router.push("/dashboard/sales")}>{t("common.back")}</Button>
            </div>
        );
    }

    const currencySymbol = invoice.currency === "dinar" ? dinarSymbol : tomanSymbol;
    const items = invoice.items || [];

    return (
        <div className="max-w-3xl mx-auto space-y-4 pb-10">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5 min-w-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 w-9 p-0 rounded-xl border border-ink/5 shrink-0"
                        onClick={() => router.push("/dashboard/sales")}
                    >
                        <ArrowRight className="w-4 h-4" />
                    </Button>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h1 className="text-lg font-black font-vazirmatn text-ink truncate">
                                {invoice.invoice_number}
                            </h1>
                            <Badge className={cn("text-[8px] font-black border", statusClass(invoice.payment_status))}>
                                {statusLabel(invoice.payment_status)}
                            </Badge>
                        </div>
                        <p className="text-[10px] text-ink/35 mt-0.5">
                            {t("sales.invoiceDetail.title")} · {formatDateTime(invoice.created_at)}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 px-3 rounded-xl text-[11px] font-black gap-1.5"
                        disabled={isPrinting}
                        onClick={() => handlePrint()}
                    >
                        <Printer className="w-3.5 h-3.5" />
                        {t("sales.printReceipt")}
                    </Button>
                    <div className="text-end hidden sm:block">
                        <p className="text-[9px] text-ink/30 uppercase tracking-widest">{t("sales.payable")}</p>
                        <p className="text-xl font-black text-primary font-vazirmatn tabular-nums leading-none">
                            {formatNumber(Number(invoice.total || 0))}
                            <span className="text-[10px] text-primary/40 ms-1">{currencySymbol}</span>
                        </p>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="rounded-2xl border border-white/80 bg-white/70 p-4 space-y-2.5">
                    <p className="text-[9px] font-black text-ink/35 uppercase tracking-widest">
                        {t("sales.invoiceDetail.info")}
                    </p>
                    <MetaRow icon={<Store className="w-3.5 h-3.5" />} label={t("sales.invoiceDetail.branch")} value={invoice.branch?.name || "—"} />
                    <MetaRow icon={<User className="w-3.5 h-3.5" />} label={t("sales.invoiceDetail.cashier")} value={invoice.user?.name || "—"} />
                    <MetaRow
                        icon={<PaymentIcon className="w-3.5 h-3.5" />}
                        label={t("sales.invoiceDetail.paymentMethod")}
                        value={paymentLabel(invoice.payment_method)}
                    />
                    {invoice.due_date && (
                        <MetaRow
                            icon={<CalendarDays className="w-3.5 h-3.5" />}
                            label={t("sales.dueDate")}
                            value={formatDueDate(invoice.due_date)}
                        />
                    )}
                </div>

                <div className="rounded-2xl border border-white/80 bg-white/70 p-4 space-y-2.5">
                    <p className="text-[9px] font-black text-ink/35 uppercase tracking-widest">
                        {t("sales.invoiceDetail.customer")}
                    </p>
                    <MetaRow
                        icon={<User className="w-3.5 h-3.5" />}
                        label={t("sales.customerName")}
                        value={invoice.customer_name || t("sales.cash")}
                    />
                    {invoice.customer_phone && (
                        <MetaRow icon={<Phone className="w-3.5 h-3.5" />} label={t("sales.customerPhone")} value={invoice.customer_phone} />
                    )}
                    {invoice.notes && (
                        <p className="text-[11px] text-ink/50 font-vazirmatn pt-1 border-t border-ink/5">
                            {invoice.notes}
                        </p>
                    )}
                    {!invoice.customer_phone && !invoice.notes && invoice.payment_method === "cash" && (
                        <p className="text-[11px] text-ink/30 font-vazirmatn">{t("sales.invoiceDetail.walkIn")}</p>
                    )}
                </div>
            </div>

            {invoice.payment_method === "check" && invoice.check && (
                <div className="rounded-2xl border border-accent/15 bg-accent/[0.03] p-4 space-y-2">
                    <p className="text-[9px] font-black text-accent/70 uppercase tracking-widest flex items-center gap-1.5">
                        <CreditCard className="w-3.5 h-3.5" />
                        {t("sales.checkInfo")}
                    </p>
                    <div className="grid grid-cols-2 gap-2 text-[11px] font-vazirmatn">
                        <InfoCell label={t("sales.checkNumber")} value={invoice.check.check_number} />
                        <InfoCell label={t("sales.bankName")} value={invoice.check.bank_name || "—"} />
                        <InfoCell label={t("sales.payerName")} value={invoice.check.payer_name || "—"} />
                        <InfoCell label={t("sales.dueDate")} value={formatDueDate(invoice.check.due_date)} />
                        <InfoCell
                            label={t("sales.invoiceDetail.checkStatus")}
                            value={
                                invoice.check.status === "cleared"
                                    ? t("checks.status.cleared")
                                    : invoice.check.status === "bounced"
                                        ? t("checks.status.bounced")
                                        : t("checks.status.pending")
                            }
                        />
                        {invoice.check.payer_phone && (
                            <InfoCell label={t("sales.customerPhone")} value={invoice.check.payer_phone} />
                        )}
                    </div>
                    <Link
                        href="/dashboard/checks"
                        className="inline-block text-[10px] font-black text-accent hover:underline mt-1"
                    >
                        {t("nav.checks")} →
                    </Link>
                </div>
            )}

            {invoice.payment_method === "credit" && (
                <div className="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 flex items-center justify-between gap-3">
                    <div>
                        <p className="text-[9px] font-black text-indigo-500 uppercase tracking-widest">{t("sales.creditInfo")}</p>
                        <p className="text-[12px] font-black font-vazirmatn text-ink mt-1">
                            {t("sales.dueDate")}: {formatDueDate(invoice.due_date)}
                        </p>
                    </div>
                    <Link
                        href="/dashboard/credits"
                        className="text-[10px] font-black text-indigo-600 hover:underline shrink-0"
                    >
                        {t("nav.credits")} →
                    </Link>
                </div>
            )}

            <div className="rounded-2xl border border-white/80 bg-white/70 overflow-hidden">
                <div className="px-4 py-3 border-b border-ink/5 flex items-center gap-2">
                    <Receipt className="w-4 h-4 text-primary" />
                    <h2 className="text-[12px] font-black font-vazirmatn text-ink">
                        {t("sales.invoiceDetail.items")}
                    </h2>
                    <span className="text-[9px] text-ink/30 font-bold ms-auto">
                        {formatNumber(items.length)} {t("sales.invoiceDetail.lines")}
                    </span>
                </div>
                <div className="divide-y divide-ink/5">
                    {items.length === 0 ? (
                        <p className="p-6 text-center text-[11px] text-ink/30">{t("sales.invoiceDetail.noItems")}</p>
                    ) : items.map((item: any) => {
                        const line = Number(item.actual_price || 0) * Number(item.quantity || 0)
                            - Number(item.discount || 0) * Number(item.quantity || 0);
                        return (
                            <div key={item.id} className="px-4 py-3 flex items-center gap-3">
                                <div className="w-8 h-10 rounded-lg bg-parchment/50 border border-ink/5 flex items-center justify-center shrink-0">
                                    <BookOpen className="w-3.5 h-3.5 text-ink/20" />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-[12px] font-black font-vazirmatn text-ink truncate">
                                        {item.book?.title || `Book #${item.book_id}`}
                                    </p>
                                    <p className="text-[9px] text-ink/35 mt-0.5">
                                        {item.book?.author && <span>{item.book.author} · </span>}
                                        {formatNumber(Number(item.quantity))} × {formatNumber(Number(item.actual_price))}
                                        {Number(item.discount) > 0 && (
                                            <span className="text-rose-400"> (−{formatNumber(Number(item.discount))})</span>
                                        )}
                                    </p>
                                </div>
                                <p className="text-[12px] font-black font-vazirmatn text-ink tabular-nums shrink-0">
                                    {formatNumber(Math.max(0, line))}
                                </p>
                            </div>
                        );
                    })}
                </div>
                <div className="px-4 py-3 bg-parchment/20 border-t border-ink/5 space-y-1.5">
                    <TotalRow label={t("sales.subtotal")} value={formatNumber(Number(invoice.subtotal || 0))} symbol={currencySymbol} />
                    {Number(invoice.discount_amount) > 0 && (
                        <TotalRow
                            label={t("sales.discount")}
                            value={`−${formatNumber(Number(invoice.discount_amount))}`}
                            symbol={currencySymbol}
                            className="text-rose-500"
                        />
                    )}
                    <TotalRow
                        label={t("sales.payable")}
                        value={formatNumber(Number(invoice.total || 0))}
                        symbol={currencySymbol}
                        bold
                    />
                </div>
            </div>
        </div>
    );
}

export default function InvoiceDetailPage() {
    return (
        <Suspense
            fallback={
                <div className="space-y-4 max-w-3xl mx-auto">
                    <div className="h-10 w-48 bg-parchment/30 rounded-xl animate-pulse" />
                    <div className="h-40 bg-parchment/20 rounded-2xl animate-pulse" />
                </div>
            }
        >
            <InvoiceDetailContent />
        </Suspense>
    );
}

function MetaRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="flex items-center gap-2 text-[11px]">
            <span className="text-ink/25 shrink-0">{icon}</span>
            <span className="text-ink/35 font-bold shrink-0">{label}</span>
            <span className="font-black font-vazirmatn text-ink truncate ms-auto text-end">{value}</span>
        </div>
    );
}

function InfoCell({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[8px] text-ink/30 font-bold uppercase">{label}</p>
            <p className="text-[11px] font-black text-ink font-vazirmatn mt-0.5">{value}</p>
        </div>
    );
}

function TotalRow({
    label, value, symbol, bold, className,
}: {
    label: string; value: string; symbol: string; bold?: boolean; className?: string;
}) {
    return (
        <div className={cn("flex justify-between items-center", className)}>
            <span className={cn("text-[10px] font-black", bold ? "text-ink" : "text-ink/40")}>{label}</span>
            <span className={cn("font-vazirmatn tabular-nums", bold ? "text-[15px] font-black text-primary" : "text-[11px] font-black text-ink/60")}>
                {value} <span className="text-[8px] opacity-50">{symbol}</span>
            </span>
        </div>
    );
}
