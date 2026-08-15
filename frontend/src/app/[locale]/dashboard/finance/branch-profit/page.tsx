"use client";

import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import {
    Building2, TrendingUp, TrendingDown, Gift, Wallet,
    RefreshCw, Search, CreditCard,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";

type Currency = "toman" | "dinar";
type PeriodKey = "thisMonth" | "lastMonth" | "last30" | "thisYear" | "custom";

interface BranchRow {
    id: number;
    name: string;
    city?: string | null;
    type?: string;
}

interface BranchProfitRow {
    branch: BranchRow;
    revenue_toman: number;
    revenue_dinar: number;
    expenses_toman: number;
    expenses_dinar: number;
    gift_costs_toman: number;
    gift_costs_dinar: number;
    pending_credit?: number;
    pending_credit_toman?: number;
    pending_credit_dinar?: number;
    net_profit_toman: number;
    net_profit_dinar: number;
}

function toNum(v: unknown): number {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
}

function isoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

function periodBounds(key: PeriodKey, customFrom: string, customTo: string): { from: string; to: string } {
    const today = new Date();
    const to = isoDate(today);

    if (key === "custom") {
        return {
            from: customFrom || isoDate(new Date(today.getFullYear(), today.getMonth(), 1)),
            to: customTo || to,
        };
    }
    if (key === "last30") {
        const from = new Date(today);
        from.setDate(from.getDate() - 29);
        return { from: isoDate(from), to };
    }
    if (key === "thisYear") {
        return { from: `${today.getFullYear()}-01-01`, to };
    }
    if (key === "lastMonth") {
        const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        const end = new Date(today.getFullYear(), today.getMonth(), 0);
        return { from: isoDate(start), to: isoDate(end) };
    }
    // thisMonth
    return {
        from: isoDate(new Date(today.getFullYear(), today.getMonth(), 1)),
        to,
    };
}

function pick(row: BranchProfitRow, currency: Currency) {
    if (currency === "dinar") {
        return {
            rev: toNum(row.revenue_dinar),
            exp: toNum(row.expenses_dinar),
            gift: toNum(row.gift_costs_dinar),
            net: toNum(row.net_profit_dinar),
            credit: toNum(row.pending_credit_dinar ?? 0),
        };
    }
    return {
        rev: toNum(row.revenue_toman),
        exp: toNum(row.expenses_toman),
        gift: toNum(row.gift_costs_toman),
        net: toNum(row.net_profit_toman),
        credit: toNum(row.pending_credit_toman ?? 0),
    };
}

export default function BranchProfitPage() {
    const { t, formatNumber, preferredCurrency } = useTranslation();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;

    const [currency, setCurrency] = useState<Currency>(preferredCurrency);
    const [branches, setBranches] = useState<BranchProfitRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [search, setSearch] = useState("");
    const [period, setPeriod] = useState<PeriodKey>("thisMonth");
    const [customFrom, setCustomFrom] = useState(() =>
        isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
    );
    const [customTo, setCustomTo] = useState(() => isoDate(new Date()));

    const currencySymbol = currency === "toman"
        ? t("common.currency.tomanSymbol")
        : t("common.currency.dinarSymbol");

    useEffect(() => {
        setCurrency(preferredCurrency);
    }, [preferredCurrency]);

    const bounds = useMemo(
        () => periodBounds(period, customFrom, customTo),
        [period, customFrom, customTo]
    );

    const fetchData = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const params = new URLSearchParams({
                date_from: bounds.from,
                date_to: bounds.to,
            });
            const data = await apiRequest(`/reports/all-branches?${params.toString()}`);
            setBranches(Array.isArray(data) ? data : []);
        } catch (error) {
            console.error("Failed to fetch branch profit data:", error);
            notifyRef.current.error("finance.branchProfit.loadError");
            setBranches([]);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [bounds.from, bounds.to]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const byKey = new Map<string, BranchProfitRow>();
        for (const row of branches) {
            if (!row?.branch?.id) continue;
            const key = `${row.branch.name}|${row.branch.type || "store"}`;
            const existing = byKey.get(key);
            if (!existing) {
                byKey.set(key, row);
                continue;
            }
            const cur = pick(row, currency);
            const prev = pick(existing, currency);
            const curScore = Math.abs(cur.rev) + Math.abs(cur.exp) + Math.abs(cur.gift) + Math.abs(cur.net);
            const prevScore = Math.abs(prev.rev) + Math.abs(prev.exp) + Math.abs(prev.gift) + Math.abs(prev.net);
            if (curScore > prevScore || (curScore === prevScore && row.branch.id < existing.branch.id)) {
                byKey.set(key, row);
            }
        }
        let list = Array.from(byKey.values());
        if (q) {
            list = list.filter((row) => {
                const name = (row.branch?.name || "").toLowerCase();
                const city = (row.branch?.city || "").toLowerCase();
                return name.includes(q) || city.includes(q);
            });
        }
        return [...list].sort((a, b) => pick(b, currency).net - pick(a, currency).net);
    }, [branches, search, currency]);

    const totals = useMemo(() => {
        return filtered.reduce(
            (acc, row) => {
                const m = pick(row, currency);
                acc.rev += m.rev;
                acc.exp += m.exp;
                acc.gift += m.gift;
                acc.net += m.net;
                acc.credit += m.credit;
                return acc;
            },
            { rev: 0, exp: 0, gift: 0, net: 0, credit: 0 }
        );
    }, [filtered, currency]);

    const periods: { key: PeriodKey; label: string }[] = [
        { key: "thisMonth", label: t("finance.branchProfit.period.thisMonth") },
        { key: "lastMonth", label: t("finance.branchProfit.period.lastMonth") },
        { key: "last30", label: t("finance.branchProfit.period.last30") },
        { key: "thisYear", label: t("finance.branchProfit.period.thisYear") },
        { key: "custom", label: t("finance.branchProfit.period.custom") },
    ];

    const kpis = [
        {
            label: t("finance.branchProfit.kpi.totalRevenue"),
            value: totals.rev,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/40",
            icon: TrendingUp,
        },
        {
            label: t("finance.branchProfit.kpi.totalExpenses"),
            value: totals.exp,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/40",
            icon: TrendingDown,
        },
        {
            label: t("finance.branchProfit.kpi.giftCost"),
            value: totals.gift,
            color: "text-amber-600",
            border: "border-amber-100",
            bg: "bg-amber-50/40",
            icon: Gift,
        },
        {
            label: t("finance.branchProfit.kpi.netProfit"),
            value: totals.net,
            color: totals.net >= 0 ? "text-primary" : "text-rose-500",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
            icon: Wallet,
        },
    ];

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">
                        {t("finance.branchProfitTitle")}
                    </h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-0.5">
                        {t("finance.branchProfit.subtitle")}
                        <span className="text-ink/25 ms-1.5 font-mono">
                            {bounds.from} — {bounds.to}
                        </span>
                    </p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                    <div className="flex bg-white/70 border border-white p-1 rounded-xl shadow-sm">
                        <button
                            type="button"
                            onClick={() => setCurrency("toman")}
                            className={cn(
                                "px-3 py-1.5 rounded-lg text-[10px] font-black font-vazirmatn transition-all",
                                currency === "toman" ? "bg-primary text-white shadow-sm" : "text-ink/40 hover:text-ink/70"
                            )}
                        >
                            {t("finance.branchProfit.currencyToman")}
                        </button>
                        <button
                            type="button"
                            onClick={() => setCurrency("dinar")}
                            className={cn(
                                "px-3 py-1.5 rounded-lg text-[10px] font-black font-vazirmatn transition-all",
                                currency === "dinar" ? "bg-primary text-white shadow-sm" : "text-ink/40 hover:text-ink/70"
                            )}
                        >
                            {t("finance.branchProfit.currencyDinar")}
                        </button>
                    </div>
                    <button
                        type="button"
                        title={t("common.refresh")}
                        aria-label={t("common.refresh")}
                        disabled={isLoading || isRefreshing}
                        onClick={() => fetchData(true)}
                        className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing) && "animate-spin")} />
                    </button>
                </div>
            </div>

            <div className="flex flex-col sm:flex-row gap-2 flex-wrap">
                <div className="flex gap-1 bg-white/50 border border-white/70 rounded-xl p-1 shadow-sm overflow-x-auto">
                    {periods.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            onClick={() => setPeriod(p.key)}
                            className={cn(
                                "px-2.5 py-1.5 rounded-lg text-[10px] font-black font-vazirmatn whitespace-nowrap transition-all",
                                period === p.key
                                    ? "bg-white shadow-sm text-primary"
                                    : "text-ink/35 hover:text-ink/60"
                            )}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
                {period === "custom" && (
                    <div className="flex items-center gap-2">
                        <input
                            type="date"
                            value={customFrom}
                            onChange={(e) => setCustomFrom(e.target.value)}
                            className="h-9 rounded-xl border border-white bg-white/70 px-2.5 text-[11px] font-vazirmatn outline-none"
                        />
                        <span className="text-ink/25 text-[10px]">—</span>
                        <input
                            type="date"
                            value={customTo}
                            onChange={(e) => setCustomTo(e.target.value)}
                            className="h-9 rounded-xl border border-white bg-white/70 px-2.5 text-[11px] font-vazirmatn outline-none"
                        />
                    </div>
                )}
                <div className="relative flex-1 min-w-[160px]">
                    <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/25" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("finance.branchProfit.searchPlaceholder")}
                        className="w-full h-9 ps-9 pe-3 rounded-xl border border-white bg-white/70 text-[11px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                    />
                </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
                {kpis.map((kpi) => (
                    <Card key={kpi.label} className={cn("border bg-white/70 rounded-xl", kpi.border)}>
                        <CardContent className={cn("p-3.5", kpi.bg)}>
                            <div className="flex items-center justify-between mb-1.5">
                                <p className="text-[9px] font-bold text-ink/40 truncate">{kpi.label}</p>
                                <kpi.icon className={cn("w-3.5 h-3.5 shrink-0", kpi.color)} />
                            </div>
                            <p className={cn("text-lg font-black font-vazirmatn tabular-nums leading-none", kpi.color)}>
                                {isLoading && !branches.length ? "…" : formatNumber(kpi.value)}
                                <span className="text-[9px] text-ink/30 ms-1 font-bold">{currencySymbol}</span>
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {totals.credit > 0 && (
                <div className="rounded-xl border border-amber-100 bg-amber-50/50 px-3.5 py-2.5 flex items-center gap-2 text-[11px]">
                    <CreditCard className="w-3.5 h-3.5 text-amber-600 shrink-0" />
                    <span className="text-ink/50 font-bold">{t("finance.branchProfit.pendingCredit")}</span>
                    <span className="ms-auto font-black font-vazirmatn tabular-nums text-amber-700">
                        {formatNumber(totals.credit)}
                        <span className="text-[9px] text-amber-600/60 ms-1">{currencySymbol}</span>
                    </span>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5">
                {isLoading && !branches.length ? (
                    Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="h-44 bg-parchment/20 rounded-xl animate-pulse" />
                    ))
                ) : filtered.length === 0 ? (
                    <Card className="border border-white/70 bg-white/70 rounded-2xl md:col-span-2 lg:col-span-3">
                        <CardContent className="p-10 text-center text-ink/30 text-[12px] font-black">
                            {t("finance.branchProfit.empty")}
                        </CardContent>
                    </Card>
                ) : (
                    filtered.map((item) => {
                        const branch = item.branch;
                        const { rev, exp, gift, net, credit } = pick(item, currency);
                        const margin = rev > 0 ? (net / rev) * 100 : 0;

                        return (
                            <div
                                key={branch.id}
                                className="rounded-xl border border-white/80 bg-white/75 overflow-hidden flex flex-col"
                            >
                                <div className="px-3.5 py-3 border-b border-ink/5 flex items-center justify-between gap-2">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <div className="w-8 h-8 rounded-lg bg-primary/10 border border-primary/10 flex items-center justify-center shrink-0">
                                            <Building2 className="w-3.5 h-3.5 text-primary" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-[13px] font-black font-vazirmatn text-ink truncate">
                                                {branch.name}
                                            </p>
                                            {branch.city ? (
                                                <p className="text-[9px] text-ink/30 truncate">{branch.city}</p>
                                            ) : null}
                                        </div>
                                    </div>
                                    <Badge
                                        className={cn(
                                            "text-[9px] font-black shrink-0",
                                            margin >= 0 ? "bg-emerald-50 text-emerald-600" : "bg-rose-50 text-rose-600"
                                        )}
                                    >
                                        {margin >= 0 ? "+" : ""}
                                        {formatNumber(Math.round(margin * 10) / 10)}%
                                    </Badge>
                                </div>

                                <div className="p-3.5 space-y-2 flex-1">
                                    <Row
                                        label={t("finance.branchProfit.salesRevenue")}
                                        value={formatNumber(rev)}
                                        className="text-emerald-600"
                                    />
                                    <Row
                                        label={t("finance.branchProfit.operatingExpenses")}
                                        value={formatNumber(exp)}
                                        className="text-rose-500"
                                    />
                                    <Row
                                        label={t("finance.branchProfit.giftBooksCost")}
                                        value={formatNumber(gift)}
                                        className="text-amber-600"
                                    />
                                    {credit > 0 && (
                                        <Row
                                            label={t("finance.branchProfit.pendingCredit")}
                                            value={formatNumber(credit)}
                                            className="text-ink/50"
                                        />
                                    )}
                                </div>

                                <div className="px-3.5 py-3 border-t border-ink/5 flex items-end justify-between gap-2">
                                    <span className="text-[9px] text-ink/30 font-bold">
                                        {t("finance.branchProfit.estimatedNet")}
                                    </span>
                                    <p
                                        className={cn(
                                            "text-lg font-black font-vazirmatn tabular-nums leading-none",
                                            net >= 0 ? "text-primary" : "text-rose-500"
                                        )}
                                    >
                                        {formatNumber(net)}
                                        <span className="text-[9px] text-ink/30 ms-1 font-bold">{currencySymbol}</span>
                                    </p>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </div>
    );
}

function Row({ label, value, className }: { label: string; value: string; className?: string }) {
    return (
        <div className="flex items-center justify-between gap-2">
            <span className="text-[10px] text-ink/40 font-bold truncate">{label}</span>
            <span className={cn("text-[12px] font-black font-vazirmatn tabular-nums shrink-0", className)}>
                {value}
            </span>
        </div>
    );
}
