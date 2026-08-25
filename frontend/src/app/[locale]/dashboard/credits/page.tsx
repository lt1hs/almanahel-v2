"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { useState, useEffect, useCallback, useMemo } from "react";
import {
    HandCoins, AlertTriangle, CheckCircle2, Clock, Search,
    CalendarDays, Phone, User, Store, Receipt, X,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { Link } from "@/i18n/routing";

type CreditStatus = "pending" | "paid" | "overdue" | "partially_paid";
type CollectMethod = "cash" | "card" | "bank_transfer";

function formatDueDate(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

function remainingAmount(inv: { outstanding?: number | string | null; total?: number | string | null }): number {
    return Number(inv.outstanding ?? inv.total ?? 0);
}

export default function CreditsPage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [statusFilter, setStatusFilter] = useState<CreditStatus | "all">("all");
    const [search, setSearch] = useState("");
    const [credits, setCredits] = useState<any[]>([]);
    const [dueSoon, setDueSoon] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(true);
    const [updatingId, setUpdatingId] = useState<number | null>(null);
    const [collecting, setCollecting] = useState<any | null>(null);
    const [collectMethod, setCollectMethod] = useState<CollectMethod>("cash");

    const statusCfg = useMemo(() => ({
        pending: { label: t("credits.status.pending"), icon: Clock, color: "text-amber-500", bg: "bg-amber-50", border: "border-amber-100" },
        paid: { label: t("credits.status.paid"), icon: CheckCircle2, color: "text-emerald-500", bg: "bg-emerald-50", border: "border-emerald-100" },
        overdue: { label: t("credits.status.overdue"), icon: AlertTriangle, color: "text-rose-500", bg: "bg-rose-50", border: "border-rose-100" },
        partially_paid: { label: t("credits.status.pending"), icon: Clock, color: "text-amber-500", bg: "bg-amber-50", border: "border-amber-100" },
    }), [t]);

    const fetchCredits = useCallback(async () => {
        setIsLoading(true);
        try {
            const params = new URLSearchParams();
            if (statusFilter !== "all") params.set("payment_status", statusFilter);
            const qs = params.toString();
            const data = await apiRequest(`/credits${qs ? `?${qs}` : ""}`);
            setCredits(Array.isArray(data?.credits) ? data.credits : []);
            setDueSoon(Array.isArray(data?.due_soon) ? data.due_soon : []);
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.creditsLoadError");
            setCredits([]);
            setDueSoon([]);
        } finally {
            setIsLoading(false);
        }
    }, [notify, statusFilter]);

    useEffect(() => { fetchCredits(); }, [fetchCredits]);

    const updateStatus = async (id: number, payment_status: Exclude<CreditStatus, "paid" | "partially_paid">) => {
        setUpdatingId(id);
        try {
            await apiRequest(`/credits/${id}`, {
                method: "PUT",
                body: JSON.stringify({ payment_status }),
            });
            notify.success("toast.creditsUpdated");
            fetchCredits();
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.creditsUpdateError");
        } finally {
            setUpdatingId(null);
        }
    };

    const openCollect = (inv: any) => {
        if (!inv.customer_id) {
            notify.error("toast.creditsNeedCustomer");
            return;
        }
        if (remainingAmount(inv) <= 0) {
            notify.error("toast.creditsUpdateError");
            return;
        }
        setCollectMethod("cash");
        setCollecting(inv);
    };

    const confirmCollect = async () => {
        if (!collecting?.customer_id) return;
        const amount = remainingAmount(collecting);
        setUpdatingId(collecting.id);
        try {
            await apiRequest(`/customers/${collecting.customer_id}/payments`, {
                method: "POST",
                body: JSON.stringify({
                    invoice_id: collecting.id,
                    amount,
                    currency: collecting.currency || "toman",
                    method: collectMethod,
                    idempotency_key: `credits-pay-${collecting.id}`,
                }),
            });
            notify.success("toast.creditsCollected");
            setCollecting(null);
            fetchCredits();
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.creditsUpdateError");
        } finally {
            setUpdatingId(null);
        }
    };

    const filtered = credits.filter((c) => {
        if (!search.trim()) return true;
        const q = search.trim().toLowerCase();
        return (
            (c.customer_name || "").toLowerCase().includes(q) ||
            (c.customer_phone || "").includes(q) ||
            (c.invoice_number || "").toLowerCase().includes(q)
        );
    });

    const unpaid = credits.filter((c) => ["pending", "overdue", "partially_paid"].includes(c.payment_status));
    const totalUnpaid = unpaid.reduce((a, c) => a + remainingAmount(c), 0);
    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");

    return (
        <div className="space-y-5 pb-10">
            <div>
                <h1 className="text-xl font-black font-vazirmatn text-ink">{t("credits.title")}</h1>
                <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
                    {t("credits.subtitle")}
                </p>
            </div>

            {dueSoon.length > 0 && (
                <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3.5">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                    <div>
                        <p className="text-[11px] font-black font-vazirmatn text-amber-800">
                            {t("credits.dueSoon", { count: dueSoon.length })}
                        </p>
                        <p className="mt-0.5 text-[9px] text-amber-600">
                            {t("credits.totalUnpaid")}: {formatNumber(totalUnpaid)} {tomanSymbol}
                        </p>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-3 gap-3">
                {[
                    { label: t("credits.status.pending"), value: credits.filter((c) => c.payment_status === "pending").length, color: "text-amber-500", icon: Clock, bg: "bg-amber-50 text-amber-500" },
                    { label: t("credits.status.paid"), value: credits.filter((c) => c.payment_status === "paid").length, color: "text-emerald-500", icon: CheckCircle2, bg: "bg-emerald-50 text-emerald-500" },
                    { label: t("credits.status.overdue"), value: credits.filter((c) => c.payment_status === "overdue").length, color: "text-rose-500", icon: AlertTriangle, bg: "bg-rose-50 text-rose-500" },
                ].map((kpi) => (
                    <Card key={kpi.label} className="rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
                        <CardContent className="flex items-center gap-3 p-4 pt-4">
                            <div className={cn("flex h-9 w-9 shrink-0 items-center justify-center rounded-xl", kpi.bg)}>
                                <kpi.icon className="h-4 w-4" />
                            </div>
                            <div className="min-w-0">
                                <p className="truncate text-[10px] font-bold text-ink/40">{kpi.label}</p>
                                <p className={cn("text-lg font-black font-vazirmatn tabular-nums", kpi.color)}>
                                    {isLoading ? "…" : kpi.value}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative min-w-0 flex-1">
                    <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-3.5 w-3.5 text-ink/20" />
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("credits.searchPlaceholder")}
                        className="h-11 w-full rounded-xl border border-ink/5 bg-white/50 pe-3 ps-9 text-[12px] font-vazirmatn outline-none transition-all placeholder:text-ink/20 focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                    />
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {(["all", "pending", "paid", "overdue"] as const).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => setStatusFilter(s)}
                            className={cn(
                                "h-11 rounded-xl border px-3 text-[10px] font-black transition-all",
                                statusFilter === s
                                    ? "border-primary bg-primary text-white shadow-lg shadow-primary/20"
                                    : "border-white bg-white/70 text-ink/40 hover:border-primary/20 hover:text-ink/70"
                            )}
                        >
                            {s === "all" ? t("common.all") : statusCfg[s as CreditStatus].label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="space-y-3">
                {isLoading && credits.length === 0 ? (
                    Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="h-24 animate-pulse rounded-2xl bg-parchment/20" />
                    ))
                ) : filtered.length === 0 ? (
                    <Card className="rounded-2xl border border-dashed border-ink/10 bg-white/40">
                        <CardContent className="p-10 pt-10 text-center text-[12px] font-black text-ink/30">
                            {t("credits.empty")}
                        </CardContent>
                    </Card>
                ) : filtered.map((inv) => {
                    const status = (inv.payment_status || "pending") as CreditStatus;
                    const cfg = statusCfg[status] ?? statusCfg.pending;
                    const isUnpaid = status === "pending" || status === "overdue" || status === "partially_paid";
                    const amount = remainingAmount(inv);
                    return (
                        <Card
                            key={inv.id}
                            className={cn(
                                "overflow-hidden rounded-2xl border bg-white/70 shadow-sm backdrop-blur-xl",
                                status === "overdue" ? "border-rose-100" : "border-white/70"
                            )}
                        >
                            <CardContent className="p-4 pt-4">
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <div className={cn("flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border", cfg.bg, cfg.border)}>
                                            <cfg.icon className={cn("h-5 w-5", cfg.color)} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="flex min-w-0 items-center gap-1 text-[13px] font-black font-vazirmatn text-ink">
                                                    <User className="h-3.5 w-3.5 shrink-0 text-ink/25" />
                                                    <span className="truncate">{inv.customer_name || t("credits.unknownCustomer")}</span>
                                                </span>
                                                <Badge className={cn("text-[8px] font-black border", cfg.bg, cfg.color, cfg.border)}>
                                                    {cfg.label}
                                                </Badge>
                                                <Link
                                                    href={`/dashboard/invoices?id=${inv.id}`}
                                                    className="flex items-center gap-1 font-mono text-[9px] text-ink/25 hover:text-primary"
                                                >
                                                    <Receipt className="h-3 w-3" />
                                                    {inv.invoice_number}
                                                </Link>
                                            </div>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] text-ink/40">
                                                <span className="inline-flex items-center gap-1">
                                                    <CalendarDays className="h-3 w-3" />
                                                    {t("credits.dueDate")}: {formatDueDate(inv.due_date)}
                                                </span>
                                                {inv.customer_phone && (
                                                    <span className="inline-flex items-center gap-1 tabular-nums">
                                                        <Phone className="h-3 w-3" />
                                                        {inv.customer_phone}
                                                    </span>
                                                )}
                                                {inv.branch?.name && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Store className="h-3 w-3" />
                                                        {t("credits.branch")} {inv.branch.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-3 lg:justify-end lg:ps-4">
                                        <div className="text-end">
                                            <p className="text-[8px] font-bold uppercase tracking-widest text-ink/25">{t("credits.amount")}</p>
                                            <p className="text-[16px] font-black font-vazirmatn tabular-nums text-ink">
                                                {formatNumber(amount)}
                                                <span className="ms-0.5 text-[10px] text-ink/25">
                                                    {inv.currency === "dinar" ? dinarSymbol : tomanSymbol}
                                                </span>
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {isUnpaid && (
                                                <Button
                                                    size="sm"
                                                    disabled={updatingId === inv.id}
                                                    className="h-8 rounded-lg bg-emerald-500 px-3 text-[10px] font-bold text-white hover:bg-emerald-600"
                                                    onClick={() => openCollect(inv)}
                                                >
                                                    {updatingId === inv.id ? t("common.submitting") : t("credits.markPaid")}
                                                </Button>
                                            )}
                                            {status === "pending" && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={updatingId === inv.id}
                                                    className="h-8 rounded-lg border border-rose-100 px-3 text-[10px] font-bold text-rose-500 hover:bg-rose-50"
                                                    onClick={() => updateStatus(inv.id, "overdue")}
                                                >
                                                    {t("credits.markOverdue")}
                                                </Button>
                                            )}
                                            {status === "paid" && (
                                                <span className="inline-flex items-center gap-1 rounded-lg border border-emerald-100 bg-emerald-50 px-2 py-1 text-[9px] font-black text-emerald-600">
                                                    <HandCoins className="h-3 w-3" />
                                                    {t("credits.status.paid")}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {collecting && (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6"
                    onClick={(e) => e.target === e.currentTarget && updatingId === null && setCollecting(null)}
                >
                    <div className="absolute inset-0 bg-ink/45 backdrop-blur-[6px]" />
                    <div className="relative w-full max-w-md overflow-hidden rounded-3xl border border-white/70 bg-white/95 font-ibm-plex-arabic shadow-[0_24px_80px_rgba(13,13,13,0.18)]">
                        <div className="flex items-start justify-between gap-4 border-b border-ink/5 bg-parchment/30 px-5 py-4">
                            <div className="min-w-0">
                                <h2 className="text-[17px] font-bold text-ink">{t("credits.collectTitle")}</h2>
                                <p className="mt-1 text-[11px] font-medium leading-5 text-ink/40">
                                    {t("credits.collectHint")}
                                </p>
                            </div>
                            <button
                                type="button"
                                disabled={updatingId === collecting.id}
                                onClick={() => setCollecting(null)}
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-ink/5 bg-white/80 text-ink/35 hover:text-primary"
                                aria-label={t("common.close")}
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <div className="space-y-4 px-5 py-5">
                            <div className="rounded-2xl border border-ink/5 bg-parchment/40 px-4 py-3">
                                <p className="truncate text-[12px] font-black text-ink">
                                    {collecting.customer_name || t("credits.unknownCustomer")}
                                </p>
                                <p className="mt-0.5 font-mono text-[10px] text-ink/35">{collecting.invoice_number}</p>
                                <p className="mt-2 text-[18px] font-black font-vazirmatn tabular-nums text-ink">
                                    {formatNumber(remainingAmount(collecting))}
                                    <span className="ms-1 text-[11px] font-bold text-ink/35">
                                        {collecting.currency === "dinar" ? dinarSymbol : tomanSymbol}
                                    </span>
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <p className="text-[11px] font-bold text-ink/45">{t("credits.collectMethod")}</p>
                                <div className="grid grid-cols-3 gap-1.5">
                                    {([
                                        ["cash", t("sales.cash")],
                                        ["card", t("sales.card")],
                                        ["bank_transfer", t("credits.methodBank")],
                                    ] as const).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setCollectMethod(value)}
                                            className={cn(
                                                "h-10 rounded-xl border text-[11px] font-black transition-all",
                                                collectMethod === value
                                                    ? "border-primary bg-primary text-white"
                                                    : "border-ink/10 bg-white text-ink/50 hover:border-primary/20"
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex gap-2 pt-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11 flex-1 rounded-xl text-[12px] font-bold"
                                    disabled={updatingId === collecting.id}
                                    onClick={() => setCollecting(null)}
                                >
                                    {t("common.close")}
                                </Button>
                                <Button
                                    type="button"
                                    className="h-11 flex-1 rounded-xl bg-emerald-500 text-[12px] font-bold text-white hover:bg-emerald-600"
                                    disabled={updatingId === collecting.id}
                                    onClick={confirmCollect}
                                >
                                    {updatingId === collecting.id ? t("common.submitting") : t("credits.collectConfirm")}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
