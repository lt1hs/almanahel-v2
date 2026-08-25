"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { Suspense, useCallback, useEffect, useState } from "react";
import {
    ArrowRight, Phone, Receipt, CalendarDays, Pencil, X, ChevronLeft, StickyNote, Banknote,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest, ApiError } from "@/lib/api";
import { Link } from "@/i18n/routing";
import { useSearchParams } from "next/navigation";
import { cn } from "@/lib/utils";

type Customer = {
    id: number;
    name: string;
    phone?: string | null;
    notes?: string | null;
};

type InvoiceRow = {
    id: number;
    invoice_number?: string | null;
    payment_method?: string | null;
    payment_status?: string | null;
    currency?: string | null;
    total?: number | string | null;
    sold_at?: string | null;
    created_at?: string | null;
};

type CurrencyBalance = {
    accounts_receivable?: string;
    open_invoices?: number;
    overdue_invoices?: number;
};

type Balances = Record<string, CurrencyBalance>;

const AVATAR = [
    "bg-primary/10 text-primary border-primary/15",
    "bg-sky-50 text-sky-700 border-sky-100",
    "bg-amber-50 text-amber-700 border-amber-100",
    "bg-violet-50 text-violet-700 border-violet-100",
    "bg-teal-50 text-teal-700 border-teal-100",
];

function initials(name: string) {
    const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return parts.map((p) => p[0]).join("") || "؟";
}

function formatDay(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

function CustomerDetailContent() {
    const searchParams = useSearchParams();
    const customerId = Number(searchParams.get("id"));

    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");

    const [customer, setCustomer] = useState<Customer | null>(null);
    const [invoices, setInvoices] = useState<InvoiceRow[]>([]);
    const [balances, setBalances] = useState<Balances>({});
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    usePageReady(!isLoading);

    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({ name: "", phone: "", notes: "" });
    const [isSaving, setIsSaving] = useState(false);

    const fetchCustomer = useCallback(async () => {
        if (!Number.isFinite(customerId) || customerId <= 0) {
            setError(t("customers.notFound"));
            setIsLoading(false);
            return;
        }
        setIsLoading(true);
        setError(null);
        try {
            const data = await apiRequest(`/customers/${customerId}`);
            const next = data?.customer as Customer;
            setCustomer(next);
            setInvoices(Array.isArray(data?.invoices) ? data.invoices : []);
            setBalances(data?.balances && typeof data.balances === "object" ? data.balances : {});
            setForm({
                name: next?.name || "",
                phone: next?.phone || "",
                notes: next?.notes || "",
            });
        } catch (err) {
            const message = err instanceof ApiError ? err.message : t("customers.notFound");
            setError(message);
            setCustomer(null);
        } finally {
            setIsLoading(false);
        }
    }, [customerId, t]);

    useEffect(() => {
        fetchCustomer();
    }, [fetchCustomer]);

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

    const handleSave = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!form.name.trim() || !form.phone.trim()) {
            notify.error("toast.requiredFields");
            return;
        }
        setIsSaving(true);
        try {
            const updated = await apiRequest(`/customers/${customerId}`, {
                method: "PUT",
                body: JSON.stringify({
                    name: form.name.trim(),
                    phone: form.phone.trim(),
                    notes: form.notes.trim() || null,
                }),
            });
            setCustomer((prev) => ({ ...(prev || { id: customerId, name: form.name }), ...updated }));
            setEditing(false);
            notify.success("toast.customerUpdated");
        } catch (err) {
            const message = err instanceof ApiError ? err.message : "";
            if (message) notify.rawError(message);
            else notify.error("toast.customerSaveError");
        } finally {
            setIsSaving(false);
        }
    };

    const tomanAr = Number(balances?.toman?.accounts_receivable || 0);
    const dinarAr = Number(balances?.dinar?.accounts_receivable || 0);
    const openInvoices =
        Number(balances?.toman?.open_invoices || 0) + Number(balances?.dinar?.open_invoices || 0);
    const overdueInvoices =
        Number(balances?.toman?.overdue_invoices || 0) + Number(balances?.dinar?.overdue_invoices || 0);

    if (isLoading) {
        return (
            <div className="grid gap-5 pb-10 lg:grid-cols-[minmax(260px,340px)_1fr]">
                <div className="h-64 animate-pulse rounded-2xl bg-parchment/20" />
                <div className="space-y-3">
                    <div className="h-12 animate-pulse rounded-2xl bg-parchment/20" />
                    <div className="h-24 animate-pulse rounded-2xl bg-parchment/20" />
                    <div className="h-24 animate-pulse rounded-2xl bg-parchment/20" />
                </div>
            </div>
        );
    }

    if (error || !customer) {
        return (
            <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-ink/10 bg-white/40 px-6 py-16 text-center">
                <p className="text-[13px] font-black text-ink/40">{error || t("customers.notFound")}</p>
                <Link
                    href="/dashboard/customers"
                    className="mt-4 inline-flex h-9 items-center rounded-xl border-2 border-primary px-3 text-[11px] font-bold text-primary hover:bg-primary/5"
                >
                    <ArrowRight className="ms-1.5 h-3.5 w-3.5" />
                    {t("customers.backToList")}
                </Link>
            </div>
        );
    }

    return (
        <div className="space-y-5 pb-10">
            <Link
                href="/dashboard/customers"
                className="inline-flex items-center gap-1.5 text-[11px] font-black text-ink/40 transition-colors hover:text-primary"
            >
                <ArrowRight className="h-3.5 w-3.5" />
                {t("customers.backToList")}
            </Link>

            <div className="grid items-start gap-5 lg:grid-cols-[minmax(260px,360px)_1fr]">
                <div className="space-y-4 lg:sticky lg:top-4">
                    <Card className="overflow-hidden rounded-2xl border border-white/70 bg-white/75 shadow-sm backdrop-blur-xl">
                        <div className="h-16 bg-gradient-to-l from-primary/15 via-primary/5 to-transparent" />
                        <CardContent className="-mt-8 p-5 pt-0">
                            <div className="mb-4 flex items-end justify-between gap-3">
                                <div
                                    className={cn(
                                        "flex h-16 w-16 items-center justify-center rounded-2xl border bg-white text-[18px] font-black font-vazirmatn shadow-sm",
                                        AVATAR[customer.id % AVATAR.length]
                                    )}
                                >
                                    {initials(customer.name)}
                                </div>
                                {!editing && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-9 rounded-xl text-[11px]"
                                        onClick={() => setEditing(true)}
                                    >
                                        <Pencil className="ms-1.5 h-3.5 w-3.5" />
                                        {t("common.edit")}
                                    </Button>
                                )}
                            </div>
                            <p className="text-[9px] font-bold uppercase tracking-widest text-ink/30">
                                {t("customers.profile")}
                            </p>
                            <h1 className="mt-1 text-xl font-black font-vazirmatn text-ink">{customer.name}</h1>
                            <p className="mt-2 flex items-center gap-1.5 text-[12px] text-ink/50 tabular-nums">
                                <Phone className="h-3.5 w-3.5 shrink-0" />
                                {customer.phone || t("customers.noPhone")}
                            </p>
                        </CardContent>
                    </Card>

                    <div className="grid grid-cols-2 gap-3">
                        <Card className="rounded-2xl border border-white/70 bg-white/70">
                            <CardContent className="p-3.5">
                                <p className="text-[9px] font-bold uppercase tracking-widest text-ink/35">
                                    {t("customers.invoicesTitle")}
                                </p>
                                <p className="mt-1 text-lg font-black font-vazirmatn tabular-nums text-ink">
                                    {formatNumber(invoices.length)}
                                </p>
                            </CardContent>
                        </Card>
                        <Card className="rounded-2xl border border-white/70 bg-white/70">
                            <CardContent className="p-3.5">
                                <p className="text-[9px] font-bold uppercase tracking-widest text-ink/35">
                                    {t("customers.openInvoices")}
                                </p>
                                <p className={cn(
                                    "mt-1 text-lg font-black font-vazirmatn tabular-nums",
                                    openInvoices > 0 ? "text-amber-600" : "text-ink"
                                )}>
                                    {formatNumber(openInvoices)}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {(tomanAr > 0 || dinarAr > 0 || overdueInvoices > 0) && (
                        <Card className="rounded-2xl border border-rose-100 bg-rose-50/50">
                            <CardContent className="space-y-2 p-4">
                                <p className="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-widest text-rose-500">
                                    <Banknote className="h-3.5 w-3.5" />
                                    {t("customers.openReceivable")}
                                </p>
                                {tomanAr > 0 && (
                                    <p className="text-[15px] font-black font-vazirmatn tabular-nums text-rose-700">
                                        {formatNumber(tomanAr)} <span className="text-[10px] opacity-60">{tomanSymbol}</span>
                                    </p>
                                )}
                                {dinarAr > 0 && (
                                    <p className="text-[15px] font-black font-vazirmatn tabular-nums text-rose-700">
                                        {formatNumber(dinarAr)} <span className="text-[10px] opacity-60">{dinarSymbol}</span>
                                    </p>
                                )}
                                {overdueInvoices > 0 && (
                                    <p className="text-[10px] font-bold text-rose-500">
                                        {t("customers.overdueInvoices")}: {formatNumber(overdueInvoices)}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {editing ? (
                        <Card className="rounded-2xl border border-white/70 bg-white/85">
                            <CardContent className="p-4">
                                <form onSubmit={handleSave} className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <h2 className="text-[13px] font-black text-ink">{t("customers.form.editTitle")}</h2>
                                        <button
                                            type="button"
                                            disabled={isSaving}
                                            onClick={() => {
                                                setEditing(false);
                                                setForm({
                                                    name: customer.name || "",
                                                    phone: customer.phone || "",
                                                    notes: customer.notes || "",
                                                });
                                            }}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-ink/35 hover:bg-ink/5"
                                            aria-label={t("common.close")}
                                        >
                                            <X className="h-4 w-4" />
                                        </button>
                                    </div>
                                    <input
                                        required
                                        value={form.name}
                                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                                        placeholder={t("customers.form.name")}
                                        className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-[12px] outline-none focus:border-primary/30 focus:bg-white"
                                    />
                                    <input
                                        required
                                        value={form.phone}
                                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                        placeholder={t("customers.form.phone")}
                                        className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-left text-[12px] tabular-nums outline-none focus:border-primary/30 focus:bg-white"
                                    />
                                    <textarea
                                        rows={4}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="w-full resize-none rounded-xl border border-ink/8 bg-parchment/25 px-3 py-2.5 text-[12px] outline-none focus:border-primary/30 focus:bg-white"
                                    />
                                    <Button type="submit" isLoading={isSaving} className="h-10 w-full rounded-xl text-[12px]">
                                        {t("common.save")}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="rounded-2xl border border-white/70 bg-white/70">
                            <CardContent className="p-4">
                                <p className="mb-2 flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-widest text-ink/35">
                                    <StickyNote className="h-3.5 w-3.5" />
                                    {t("common.notes")}
                                </p>
                                <p className={cn(
                                    "text-[12px] leading-6",
                                    customer.notes ? "whitespace-pre-wrap text-ink/70" : "text-ink/30"
                                )}>
                                    {customer.notes || t("customers.notesEmpty")}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div>
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <Receipt className="h-4 w-4 text-ink/35" />
                            <h2 className="text-[13px] font-black text-ink">{t("customers.invoicesTitle")}</h2>
                        </div>
                        <span className="rounded-lg bg-white/70 px-2 py-1 text-[10px] font-black text-ink/40">
                            {t("customers.invoiceCount", { count: formatNumber(invoices.length) })}
                        </span>
                    </div>
                    {invoices.length === 0 ? (
                        <div className="flex flex-col items-center rounded-2xl border border-dashed border-ink/10 bg-white/40 px-6 py-14 text-center">
                            <Receipt className="mb-3 h-8 w-8 text-ink/20" />
                            <p className="text-[12px] font-black text-ink/35">{t("customers.invoicesEmpty")}</p>
                        </div>
                    ) : (
                        <div className="space-y-2.5">
                            {invoices.map((inv) => {
                                const status = String(inv.payment_status || "pending");
                                const currency = inv.currency === "dinar" ? dinarSymbol : tomanSymbol;
                                return (
                                    <Link key={inv.id} href={`/dashboard/invoices?id=${inv.id}`} className="block">
                                        <Card className="group overflow-hidden rounded-2xl border border-white/70 bg-white/70 transition-all hover:-translate-y-px hover:border-primary/20 hover:shadow-md">
                                            <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-[13px] font-black tabular-nums text-ink">
                                                        {inv.invoice_number || `#${inv.id}`}
                                                    </p>
                                                    <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink/40">
                                                        <span className="inline-flex items-center gap-1">
                                                            <CalendarDays className="h-3 w-3" />
                                                            {formatDay(inv.sold_at || inv.created_at)}
                                                        </span>
                                                        <span className="text-ink/15">·</span>
                                                        <span>{paymentLabel(String(inv.payment_method || "cash"))}</span>
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <Badge variant="outline" className={cn("text-[9px] border", statusClass(status))}>
                                                        {statusLabel(status)}
                                                    </Badge>
                                                    <p className="text-[13px] font-black tabular-nums text-ink">
                                                        {formatNumber(Number(inv.total || 0))}
                                                        <span className="ms-0.5 text-[9px] font-bold text-ink/30">{currency}</span>
                                                    </p>
                                                    <ChevronLeft className="hidden h-4 w-4 text-ink/20 group-hover:text-primary sm:block" />
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                );
                            })}
                            {invoices.length >= 50 && (
                                <p className="pt-1 text-center text-[10px] font-bold text-ink/30">
                                    {t("customers.invoicesCap")}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function CustomerDetailPage() {
    return (
        <Suspense
            fallback={
                <div className="grid gap-5 pb-10 lg:grid-cols-[minmax(260px,340px)_1fr]">
                    <div className="h-64 animate-pulse rounded-2xl bg-parchment/20" />
                    <div className="h-40 animate-pulse rounded-2xl bg-parchment/20" />
                </div>
            }
        >
            <CustomerDetailContent />
        </Suspense>
    );
}
