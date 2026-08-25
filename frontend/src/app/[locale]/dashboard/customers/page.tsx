"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { ContactRound, Plus, Search, Phone, Receipt, X, ChevronLeft, Users } from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest, ApiError } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { Link, useRouter } from "@/i18n/routing";
import { cn } from "@/lib/utils";

const AVATAR = [
    "bg-primary/10 text-primary border-primary/15",
    "bg-sky-50 text-sky-700 border-sky-100",
    "bg-amber-50 text-amber-700 border-amber-100",
    "bg-violet-50 text-violet-700 border-violet-100",
    "bg-teal-50 text-teal-700 border-teal-100",
];

type CustomerRow = {
    id: number;
    name: string;
    phone?: string | null;
    notes?: string | null;
    invoices_count?: number;
};

const EMPTY_FORM = { name: "", phone: "", notes: "" };

function initials(name: string) {
    const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return parts.map((p) => p[0]).join("") || "؟";
}

export default function CustomersPage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const { user } = useAuth();
    const router = useRouter();

    const [search, setSearch] = useState("");
    const [debounced, setDebounced] = useState("");
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [customers, setCustomers] = useState<CustomerRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [reloadKey, setReloadKey] = useState(0);
    usePageReady(true);

    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(EMPTY_FORM);
    const [isSaving, setIsSaving] = useState(false);
    const notifyRef = useRef(notify);
    notifyRef.current = notify;

    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(search.trim()), 300);
        return () => window.clearTimeout(id);
    }, [search]);

    useEffect(() => {
        setPage(1);
    }, [debounced]);

    useEffect(() => {
        let cancelled = false;
        setIsLoading(true);
        const params = new URLSearchParams({ page: String(page) });
        if (debounced) params.set("search", debounced);
        apiRequest(`/customers?${params.toString()}`)
            .then((data) => {
                if (cancelled) return;
                const rows = Array.isArray(data?.data)
                    ? data.data
                    : Array.isArray(data)
                        ? data
                        : [];
                setCustomers(rows);
                setLastPage(Number(data?.last_page || 1));
                setTotal(Number(data?.total ?? rows.length));
            })
            .catch((error) => {
                if (cancelled) return;
                const message = error instanceof ApiError ? error.message : "";
                if (message) notifyRef.current.rawError(message);
                else notifyRef.current.error("customers.loadError");
                setCustomers([]);
                setLastPage(1);
                setTotal(0);
            })
            .finally(() => {
                if (!cancelled) setIsLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [debounced, page, reloadKey]);

    const pageInvoiceCount = useMemo(
        () => customers.reduce((sum, row) => sum + Number(row.invoices_count || 0), 0),
        [customers]
    );

    const openCreate = () => {
        setForm(EMPTY_FORM);
        setShowForm(true);
    };

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!form.name.trim() || !form.phone.trim()) {
            notify.error("toast.requiredFields");
            return;
        }
        setIsSaving(true);
        try {
            const created = await apiRequest("/customers", {
                method: "POST",
                body: JSON.stringify({
                    name: form.name.trim(),
                    phone: form.phone.trim(),
                    notes: form.notes.trim() || undefined,
                    branch_id: user?.branch_id ?? user?.branch?.id ?? undefined,
                }),
            });
            notify.success("toast.customerCreated");
            setShowForm(false);
            setForm(EMPTY_FORM);
            if (created?.id) {
                router.push(`/dashboard/customers/detail?id=${created.id}`);
            } else {
                setReloadKey((key) => key + 1);
            }
        } catch (error) {
            const message = error instanceof ApiError ? error.message : "";
            if (message) notify.rawError(message);
            else notify.error("toast.customerSaveError");
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div className="space-y-6 pb-10">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("customers.title")}</h1>
                    <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
                        {t("customers.subtitle")}
                    </p>
                </div>
                <Button
                    variant="primary"
                    size="sm"
                    className="h-9 rounded-xl px-4 text-[11px] shadow-lg shadow-primary/10"
                    onClick={openCreate}
                >
                    <Plus className="ms-1.5 h-3.5 w-3.5" />
                    {t("customers.add")}
                </Button>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <Card className="rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
                    <CardContent className="flex items-center gap-3 p-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/8 text-primary">
                            <Users className="h-4 w-4" />
                        </div>
                        <div>
                            <p className="text-[10px] font-bold text-ink/40">{t("customers.kpiTotal")}</p>
                            <p className="text-lg font-black font-vazirmatn tabular-nums text-ink">
                                {isLoading ? "…" : formatNumber(total)}
                            </p>
                        </div>
                    </CardContent>
                </Card>
                <Card className="rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
                    <CardContent className="flex items-center gap-3 p-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
                            <Receipt className="h-4 w-4" />
                        </div>
                        <div>
                            <p className="text-[10px] font-bold text-ink/40">{t("customers.kpiPage")}</p>
                            <p className="text-lg font-black font-vazirmatn tabular-nums text-ink">
                                {isLoading ? "…" : t("customers.invoiceCount", { count: formatNumber(pageInvoiceCount) })}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div className="relative">
                <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-3.5 w-3.5 text-ink/20" />
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t("customers.searchPlaceholder")}
                    className="h-11 w-full rounded-xl border border-ink/5 bg-white/50 pe-3 ps-9 text-[12px] font-vazirmatn outline-none transition-all placeholder:text-ink/20 focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                />
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {isLoading && customers.length === 0 ? (
                    Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="h-40 animate-pulse rounded-2xl border border-ink/5 bg-white/50" />
                    ))
                ) : customers.length === 0 ? (
                    <div className="col-span-full flex flex-col items-center justify-center rounded-2xl border border-dashed border-ink/10 bg-white/40 px-6 py-16 text-center">
                        <ContactRound className="mb-3 h-10 w-10 text-ink/20" />
                        <p className="text-sm font-black text-ink/60">{t("customers.empty")}</p>
                        <p className="mt-1 max-w-sm text-[11px] leading-5 text-ink/35">{t("customers.emptyHint")}</p>
                        <Button className="mt-4 h-9 rounded-xl text-[11px]" onClick={openCreate}>
                            <Plus className="ms-1.5 h-3.5 w-3.5" />
                            {t("customers.add")}
                        </Button>
                    </div>
                ) : (
                    customers.map((customer) => {
                        const invoices = Number(customer.invoices_count || 0);
                        return (
                            <div key={customer.id}>
                                <Link href={`/dashboard/customers/detail?id=${customer.id}`} className="block h-full">
                                    <Card className="group h-full overflow-hidden rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl transition-all hover:-translate-y-0.5 hover:border-primary/20 hover:shadow-lg">
                                        <CardContent className="flex h-full flex-col p-5">
                                            <div className="mb-4 flex items-start justify-between gap-3">
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <div
                                                        className={cn(
                                                            "flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border text-[13px] font-black font-vazirmatn",
                                                            AVATAR[customer.id % AVATAR.length]
                                                        )}
                                                    >
                                                        {initials(customer.name)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <h3 className="truncate text-[14px] font-black font-vazirmatn text-ink">
                                                            {customer.name}
                                                        </h3>
                                                        <p className="mt-1 flex items-center gap-1.5 text-[11px] text-ink/45 tabular-nums">
                                                            <Phone className="h-3 w-3 shrink-0" />
                                                            <span className="truncate">{customer.phone || t("customers.noPhone")}</span>
                                                        </p>
                                                    </div>
                                                </div>
                                                <ChevronLeft className="h-4 w-4 shrink-0 text-ink/20 transition-colors group-hover:text-primary" />
                                            </div>
                                            {customer.notes ? (
                                                <p className="mb-4 line-clamp-2 text-[11px] leading-5 text-ink/40">
                                                    {customer.notes}
                                                </p>
                                            ) : (
                                                <p className="mb-4 text-[11px] text-ink/25">{t("customers.notesEmpty")}</p>
                                            )}
                                            <div className="mt-auto flex items-center justify-between border-t border-ink/5 pt-3">
                                                <span className="inline-flex items-center gap-1.5 rounded-lg bg-parchment/70 px-2 py-1 text-[10px] font-black text-ink/50">
                                                    <Receipt className="h-3 w-3" />
                                                    {t("customers.invoiceCount", { count: formatNumber(invoices) })}
                                                </span>
                                                <span className="text-[10px] font-bold text-primary/80 group-hover:text-primary">
                                                    {t("customers.openProfile")}
                                                </span>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </Link>
                            </div>
                        );
                    })
                )}
            </div>

            {lastPage > 1 && (
                <div className="flex items-center justify-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={page <= 1 || isLoading}
                        className="h-9 rounded-xl text-[11px]"
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                        {t("common.previous")}
                    </Button>
                    <span className="min-w-[4.5rem] text-center text-[11px] font-bold text-ink/40 tabular-nums">
                        {formatNumber(page)} / {formatNumber(lastPage)}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={page >= lastPage || isLoading}
                        className="h-9 rounded-xl text-[11px]"
                        onClick={() => setPage((p) => p + 1)}
                    >
                        {t("common.next")}
                    </Button>
                </div>
            )}

            {showForm && (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6"
                    onClick={(e) => e.target === e.currentTarget && !isSaving && setShowForm(false)}
                >
                    <div className="absolute inset-0 bg-ink/45 backdrop-blur-[6px]" />
                    <div className="relative w-full max-w-lg overflow-hidden rounded-3xl border border-white/70 bg-white/95 font-ibm-plex-arabic shadow-[0_24px_80px_rgba(13,13,13,0.18)] backdrop-blur-2xl">
                        <div className="pointer-events-none absolute -top-24 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" />
                        <form onSubmit={handleCreate} className="relative">
                            <div className="flex items-start justify-between gap-4 border-b border-ink/5 bg-parchment/30 px-5 py-4 sm:px-6">
                                <div className="min-w-0 pt-0.5">
                                    <h2 className="truncate text-[17px] font-bold font-ibm-plex-arabic tracking-tight text-ink">
                                        {t("customers.form.addTitle")}
                                    </h2>
                                    <p className="mt-1 text-[11px] font-medium leading-5 text-ink/40">
                                        {t("customers.form.hint")}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    disabled={isSaving}
                                    onClick={() => setShowForm(false)}
                                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-ink/5 bg-white/80 text-ink/35 transition-all hover:border-primary/20 hover:bg-white hover:text-primary"
                                    aria-label={t("common.close")}
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                            <div className="space-y-4 px-5 py-5 sm:px-6">
                                <div className="space-y-1.5">
                                    <label className="text-[11px] font-bold text-ink/45">
                                        {t("customers.form.name")}
                                        <span className="ms-1 text-rose-500">*</span>
                                    </label>
                                    <input
                                        required
                                        value={form.name}
                                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                                        className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-[12px] outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <label className="text-[11px] font-bold text-ink/45">
                                        {t("customers.form.phone")}
                                        <span className="ms-1 text-rose-500">*</span>
                                    </label>
                                    <input
                                        required
                                        value={form.phone}
                                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                        className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-left text-[12px] tabular-nums outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <label className="text-[11px] font-bold text-ink/45">{t("common.notes")}</label>
                                    <textarea
                                        rows={3}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="w-full resize-none rounded-xl border border-ink/8 bg-parchment/25 px-3 py-2.5 text-[12px] outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                                    />
                                </div>
                            </div>
                            <div className="flex gap-2 border-t border-ink/5 bg-parchment/20 px-5 py-4 sm:px-6">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11 flex-1 rounded-xl text-[12px] font-bold"
                                    disabled={isSaving}
                                    onClick={() => setShowForm(false)}
                                >
                                    {t("common.cancel")}
                                </Button>
                                <Button
                                    type="submit"
                                    isLoading={isSaving}
                                    className="h-11 flex-[1.4] rounded-xl text-[12px] font-bold shadow-lg shadow-primary/15"
                                >
                                    {t("customers.submit")}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
