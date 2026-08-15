"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import {
    AlertTriangle, CheckCircle2, Clock, Search, X,
    Building2, CalendarDays, Phone, Store, Receipt, RefreshCw,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";

type CheckStatus = "pending" | "cleared" | "bounced";
type StatusFilter = CheckStatus | "all" | "overdue";

interface CheckRow {
    id: number;
    check_number: string;
    bank_name?: string | null;
    payer_name?: string | null;
    payer_phone?: string | null;
    amount: number;
    currency: "toman" | "dinar";
    due_date?: string | null;
    status: CheckStatus;
    branch?: { id: number; name: string } | null;
    invoice?: { id: number; invoice_number: string } | null;
}

function formatDueDate(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

function dueKey(value: string | null | undefined): string | null {
    if (!value) return null;
    return String(value).slice(0, 10);
}

function isPastDue(dueDate: string | null | undefined, status: string): boolean {
    if (status !== "pending") return false;
    const due = dueKey(dueDate);
    if (!due) return false;
    return due < new Date().toISOString().slice(0, 10);
}

export default function ChecksPage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
    const [search, setSearch] = useState("");
    const [checks, setChecks] = useState<CheckRow[]>([]);
    const [stats, setStats] = useState({
        pending: 0,
        cleared: 0,
        bounced: 0,
        overdue: 0,
        due_soon: 0,
        pending_amount_toman: 0,
        pending_amount_dinar: 0,
    });
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [updatingId, setUpdatingId] = useState<number | null>(null);

    const statusCfg = useMemo(() => ({
        pending: { label: t("checks.status.pending"), icon: Clock, color: "text-amber-500", bg: "bg-amber-50", border: "border-amber-100" },
        cleared: { label: t("checks.status.cleared"), icon: CheckCircle2, color: "text-emerald-500", bg: "bg-emerald-50", border: "border-emerald-100" },
        bounced: { label: t("checks.status.bounced"), icon: AlertTriangle, color: "text-rose-500", bg: "bg-rose-50", border: "border-rose-100" },
    }), [t]);

    const fetchChecks = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const data = await apiRequest("/checks");
            setChecks(data.checks || []);
            if (data.stats) {
                setStats({
                    pending: Number(data.stats.pending || 0),
                    cleared: Number(data.stats.cleared || 0),
                    bounced: Number(data.stats.bounced || 0),
                    overdue: Number(data.stats.overdue || 0),
                    due_soon: Number(data.stats.due_soon || 0),
                    pending_amount_toman: Number(data.stats.pending_amount_toman || 0),
                    pending_amount_dinar: Number(data.stats.pending_amount_dinar || 0),
                });
            }
        } catch (error) {
            console.error("Failed to fetch checks:", error);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => { fetchChecks(); }, [fetchChecks]);

    const updateStatus = async (id: number, status: CheckStatus) => {
        setUpdatingId(id);
        try {
            await apiRequest(`/checks/${id}`, {
                method: "PUT",
                body: JSON.stringify({ status }),
            });
            notify.success("toast.checksUpdated");
            // Optimistic local update, then soft refresh stats
            setChecks((prev) => prev.map((c) => (c.id === id ? { ...c, status } : c)));
            fetchChecks(true);
        } catch (error) {
            console.error("Failed to update check:", error);
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.checkUpdateError");
        } finally {
            setUpdatingId(null);
        }
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return checks.filter((c) => {
            if (statusFilter === "overdue") {
                if (!isPastDue(c.due_date, c.status)) return false;
            } else if (statusFilter !== "all" && c.status !== statusFilter) {
                return false;
            }
            if (!q) return true;
            return (
                (c.payer_name || "").toLowerCase().includes(q) ||
                (c.check_number || "").toLowerCase().includes(q) ||
                (c.bank_name || "").toLowerCase().includes(q) ||
                (c.payer_phone || "").includes(q) ||
                (c.invoice?.invoice_number || "").toLowerCase().includes(q) ||
                (c.branch?.name || "").toLowerCase().includes(q)
            );
        });
    }, [checks, search, statusFilter]);

    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");
    const pendingAmountLabel = stats.pending_amount_dinar > 0 && stats.pending_amount_toman > 0
        ? `${formatNumber(stats.pending_amount_toman)} ${tomanSymbol} · ${formatNumber(stats.pending_amount_dinar)} ${dinarSymbol}`
        : stats.pending_amount_dinar > 0
            ? `${formatNumber(stats.pending_amount_dinar)} ${dinarSymbol}`
            : `${formatNumber(stats.pending_amount_toman)} ${tomanSymbol}`;

    const kpis: { label: string; value: number; color: string; filter: StatusFilter }[] = [
        { label: t("checks.status.pending"), value: stats.pending, color: "text-amber-500", filter: "pending" },
        { label: t("checks.status.overdue"), value: stats.overdue, color: "text-rose-500", filter: "overdue" },
        { label: t("checks.status.cleared"), value: stats.cleared, color: "text-emerald-500", filter: "cleared" },
        { label: t("checks.status.bouncedShort"), value: stats.bounced, color: "text-rose-400", filter: "bounced" },
    ];

    const filters: { key: StatusFilter; label: string }[] = [
        { key: "all", label: t("common.all") },
        { key: "pending", label: statusCfg.pending.label },
        { key: "overdue", label: t("checks.status.overdue") },
        { key: "cleared", label: statusCfg.cleared.label },
        { key: "bounced", label: statusCfg.bounced.label },
    ];

    return (
        <div className="space-y-4 pb-10">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("checks.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-0.5">{t("checks.subtitle")}</p>
                </div>
                <button
                    type="button"
                    onClick={() => fetchChecks(true)}
                    disabled={isLoading || isRefreshing}
                    title={t("common.refresh")}
                    aria-label={t("common.refresh")}
                    className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                >
                    <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing) && "animate-spin")} />
                </button>
            </div>

            {(stats.due_soon > 0 || stats.overdue > 0) && (
                <div className="flex flex-wrap gap-2">
                    {stats.overdue > 0 && (
                        <button
                            type="button"
                            onClick={() => setStatusFilter("overdue")}
                            className="flex items-center gap-2 px-3 py-2 rounded-xl bg-rose-50 border border-rose-100 text-rose-700 text-[11px] font-black font-vazirmatn"
                        >
                            <AlertTriangle className="w-3.5 h-3.5" />
                            {t("checks.overdueAlert", { count: stats.overdue })}
                        </button>
                    )}
                    {stats.due_soon > 0 && (
                        <div className="flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50 border border-amber-100 text-amber-800 text-[11px] font-black font-vazirmatn">
                            <Clock className="w-3.5 h-3.5" />
                            {t("checks.dueSoon", { count: stats.due_soon })}
                            {stats.pending > 0 && (
                                <span className="text-[10px] font-bold text-amber-600/80 font-vazirmatn tabular-nums ms-1">
                                    · {pendingAmountLabel}
                                </span>
                            )}
                        </div>
                    )}
                </div>
            )}

            <div className="grid grid-cols-4 gap-2">
                {kpis.map((kpi) => (
                    <button
                        key={kpi.filter}
                        type="button"
                        onClick={() => setStatusFilter(kpi.filter)}
                        className={cn(
                            "rounded-xl border bg-white/70 px-3 py-2.5 text-start transition-all",
                            statusFilter === kpi.filter
                                ? "border-primary/30 ring-1 ring-primary/15 shadow-sm"
                                : "border-white/80 hover:border-primary/15"
                        )}
                    >
                        <p className="text-[9px] font-bold text-ink/35 mb-0.5 truncate">{kpi.label}</p>
                        <p className={cn("text-xl font-black font-vazirmatn tabular-nums leading-none", kpi.color)}>
                            {formatNumber(kpi.value)}
                        </p>
                    </button>
                ))}
            </div>

            <div className="flex flex-col sm:flex-row gap-2">
                <div className="flex-1 relative">
                    <Search className="absolute inset-y-0 end-3 my-auto w-3.5 h-3.5 text-ink/20 pointer-events-none" />
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("checks.searchPlaceholder")}
                        className="w-full h-9 bg-white/70 border border-white focus:border-primary/30 rounded-xl pe-9 ps-8 text-[12px] font-vazirmatn placeholder:text-ink/20 outline-none"
                    />
                    {search && (
                        <button
                            type="button"
                            aria-label={t("common.clear")}
                            onClick={() => setSearch("")}
                            className="absolute inset-y-0 start-2 my-auto w-5 h-5 flex items-center justify-center rounded-full bg-ink/8 text-ink/40"
                        >
                            <X className="w-3 h-3" />
                        </button>
                    )}
                </div>
                <div className="flex gap-1.5 flex-wrap">
                    {filters.map(({ key, label }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setStatusFilter(key)}
                            className={cn(
                                "h-9 px-3 rounded-xl text-[10px] font-black transition-all border",
                                statusFilter === key
                                    ? "bg-primary text-white border-primary"
                                    : "bg-white/70 text-ink/40 border-white hover:text-ink/70"
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="space-y-2">
                {isLoading ? (
                    Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="h-16 bg-parchment/20 rounded-xl animate-pulse" />
                    ))
                ) : filtered.length === 0 ? (
                    <div className="rounded-xl border border-white/70 bg-white/70 py-12 text-center text-ink/30 text-[12px] font-black">
                        {t("checks.empty")}
                    </div>
                ) : filtered.map((chk) => {
                    const status = chk.status || "pending";
                    const cfg = statusCfg[status] ?? statusCfg.pending;
                    const overdue = isPastDue(chk.due_date, status);
                    const busy = updatingId === chk.id;
                    return (
                        <div
                            key={chk.id}
                            className={cn(
                                "rounded-xl border bg-white/75 px-3.5 py-3 transition-colors",
                                status === "bounced" || overdue ? "border-rose-100" : "border-white/80 hover:border-primary/10"
                            )}
                        >
                            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="flex items-start gap-2.5 flex-1 min-w-0">
                                    <div className={cn(
                                        "w-8 h-8 rounded-lg flex items-center justify-center border shrink-0 mt-0.5",
                                        overdue ? "bg-rose-50 border-rose-100" : cn(cfg.bg, cfg.border)
                                    )}>
                                        <cfg.icon className={cn("w-3.5 h-3.5", overdue ? "text-rose-500" : cfg.color)} />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-1.5 flex-wrap">
                                            <span className="text-[12px] font-black text-ink font-vazirmatn truncate">
                                                {chk.payer_name || t("checks.unknownPayer")}
                                            </span>
                                            <Badge className={cn("text-[7px] font-black border px-1.5 py-px", cfg.bg, cfg.color, cfg.border)}>
                                                {overdue ? t("checks.status.overdue") : cfg.label}
                                            </Badge>
                                            <span className="text-[9px] text-ink/30 font-mono">#{chk.check_number}</span>
                                        </div>
                                        <div className="flex items-center gap-2.5 mt-1 flex-wrap text-[9px] text-ink/35">
                                            {chk.bank_name && (
                                                <span className="flex items-center gap-0.5"><Building2 className="w-2.5 h-2.5" />{chk.bank_name}</span>
                                            )}
                                            <span className={cn("flex items-center gap-0.5", overdue && "text-rose-500 font-bold")}>
                                                <CalendarDays className="w-2.5 h-2.5" />
                                                {formatDueDate(chk.due_date)}
                                            </span>
                                            {chk.payer_phone && (
                                                <span className="flex items-center gap-0.5"><Phone className="w-2.5 h-2.5" />{chk.payer_phone}</span>
                                            )}
                                            {chk.branch?.name && (
                                                <span className="flex items-center gap-0.5"><Store className="w-2.5 h-2.5" />{chk.branch.name}</span>
                                            )}
                                            {chk.invoice?.invoice_number && (
                                                <span className="flex items-center gap-0.5"><Receipt className="w-2.5 h-2.5" />{chk.invoice.invoice_number}</span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center justify-between sm:justify-end gap-3 shrink-0">
                                    <div className="text-end">
                                        <p className="text-[15px] font-black text-ink font-vazirmatn tabular-nums leading-none">
                                            {formatNumber(Number(chk.amount || 0))}
                                        </p>
                                        <p className="text-[8px] text-ink/30 mt-0.5">
                                            {chk.currency === "dinar" ? dinarSymbol : tomanSymbol}
                                        </p>
                                    </div>
                                    {status === "pending" && (
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                disabled={busy}
                                                className="h-7 px-2.5 rounded-lg text-[9px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white"
                                                onClick={() => updateStatus(chk.id, "cleared")}
                                            >
                                                {busy ? "…" : t("checks.markCleared")}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={busy}
                                                className="h-7 px-2.5 rounded-lg text-[9px] font-bold text-rose-500 hover:bg-rose-50 border border-rose-100"
                                                onClick={() => updateStatus(chk.id, "bounced")}
                                            >
                                                {t("checks.markBounced")}
                                            </Button>
                                        </div>
                                    )}
                                    {status === "bounced" && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={busy}
                                            className="h-7 px-2.5 rounded-lg text-[9px] font-bold text-amber-600 hover:bg-amber-50 border border-amber-100"
                                            onClick={() => updateStatus(chk.id, "pending")}
                                        >
                                            {t("checks.reopen")}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {!isLoading && filtered.length > 0 && (
                <p className="text-[9px] text-ink/25 text-center font-bold">
                    {formatNumber(filtered.length)} / {formatNumber(checks.length)}
                </p>
            )}
        </div>
    );
}
