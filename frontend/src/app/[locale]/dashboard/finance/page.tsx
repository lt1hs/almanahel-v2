"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useEffect, useState, useCallback } from "react";
import dynamic from "next/dynamic";
import { SettlementWizard } from "@/components/finance/SettlementWizard";
import { printSettlement, buildSettlementPrintLabels } from "@/lib/printSettlement";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import {
    Wallet, TrendingUp, Receipt, BarChart2, FileText, History,
    RefreshCw, Building2, CalendarDays, AlertTriangle,
} from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { supplierAccountsUrl } from "@/lib/supplierAccountSelection";
import {
    buildFinanceOverviewUrls,
    buildSettlementHistoryScopeKey,
    buildSettlementHistoryUrl,
    buildUnsettledDebtUrl,
    sumDebtRowsForCurrency,
    type OperationalFinanceRole,
} from "@/lib/financeRequests";
import { RequireRole } from "@/components/auth/RequireRole";
import { BulkSettlementPanel } from "@/components/finance/BulkSettlementPanel";
import { LedgerReportsPanel } from "@/components/finance/LedgerReportsPanel";

const ProfitCharts = dynamic(
    () => import("@/components/finance/ProfitCharts").then((m) => m.ProfitCharts),
    {
        ssr: false,
        loading: () => (
            <div className="h-64 rounded-2xl bg-ink/5 animate-pulse" aria-hidden />
        ),
    }
);

const RANK_COLORS = [
    "from-accent to-accent/60",
    "from-slate-400 to-slate-300",
    "from-amber-700/80 to-amber-600/50",
];

type FinanceTab = "overview" | "settlement" | "history";

interface FinanceStats {
    total_balance: number;
    balance_is_dinar?: boolean;
    gross_profit: number;
    supplier_debt: number | null;
    supplier_debt_unavailable: boolean;
    top_books: any[];
}

function formatPeriodDate(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

export default function FinancePage() {
    return (
        <RequireRole roles={["super_admin", "admin", "branch_manager", "accountant"]}>
            <FinancePageContent />
        </RequireRole>
    );
}

function FinancePageContent() {
    const { t, formatNumber, isArabic, isDinar, preferredCurrency, language } = useTranslation();
    const notify = useNotify();
    const { user } = useAuth();
    const isAdmin = user?.role === "admin" || user?.role === "super_admin";
    const isAccountant = user?.role === "accountant";
    const userBranchId = user?.branch_id ?? user?.branch?.id ?? null;
    const requiresBranchPicker = isAdmin;
    const currencySymbol = isDinar ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");
    const currency = preferredCurrency;

    const [activeTab, setActiveTab] = useState<FinanceTab>("overview");
    const [stats, setStats] = useState<FinanceStats | null>(null);
    const [suppliers, setSuppliers] = useState<any[]>([]);
    const [settlementBranchId, setSettlementBranchId] = useState("");
    const [settlementBranches, setSettlementBranches] = useState<{ id: number; name: string }[]>([]);
    const [settlementSessionKey, setSettlementSessionKey] = useState(0);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isSettlementLoading, setIsSettlementLoading] = useState(false);
    const [isConfirming, setIsConfirming] = useState(false);
    const [settlementData, setSettlementData] = useState<any[]>([]);
    const [settlementBreakdown, setSettlementBreakdown] = useState<any>(null);
    const [settlements, setSettlements] = useState<any[]>([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyAggregate, setHistoryAggregate] = useState(false);

    const effectiveSettlementBranchId = React.useMemo(
        () => (requiresBranchPicker ? settlementBranchId : userBranchId ? String(userBranchId) : ""),
        [requiresBranchPicker, settlementBranchId, userBranchId]
    );
    const historyScopeKey = React.useMemo(
        () => buildSettlementHistoryScopeKey({
            aggregate: historyAggregate,
            branchId: effectiveSettlementBranchId,
        }),
        [historyAggregate, effectiveSettlementBranchId]
    );
    const historyUrl = React.useMemo(
        () => (historyScopeKey
            ? buildSettlementHistoryUrl({
                aggregate: historyAggregate,
                branchId: effectiveSettlementBranchId,
            })
            : null),
        [historyScopeKey, historyAggregate, effectiveSettlementBranchId]
    );
    const canUseSettlement = Boolean(effectiveSettlementBranchId) && !(isAccountant && !userBranchId);

    const notifyRef = React.useRef(notify);
    notifyRef.current = notify;

    const fetchOverview = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const urls = buildFinanceOverviewUrls({
                role: user?.role,
                currency,
                branchId: userBranchId,
            });
            const [topBooks, pnl, treasury] = await Promise.all([
                apiRequest(urls.topBooks),
                apiRequest(urls.pnl),
                apiRequest(urls.treasury),
            ]);

            const cashRows = Array.isArray(treasury?.accounts) ? treasury.accounts : [];
            const cashBalance = cashRows
                .filter((row: { type?: string; currency?: string }) => row.type === "cash_drawer" && row.currency === currency)
                .reduce((acc: number, row: { closing_balance?: string }) => acc + Number(row.closing_balance ?? 0), 0);
            const totalBalance = cashBalance;
            const useDinarBalance = currency === "dinar";
            const grossProfit = Number(pnl?.net_profit ?? 0);

            let supplierDebt: number | null = null;
            let supplierDebtUnavailable = false;

            const debtRole = (user?.role ?? "accountant") as OperationalFinanceRole;
            const debtUrl = buildUnsettledDebtUrl({
                role: debtRole,
                branchId: userBranchId,
            });

            if (!debtUrl) {
                supplierDebtUnavailable = true;
            } else {
                try {
                    const debtData = await apiRequest(debtUrl);
                    const rows = Array.isArray(debtData?.rows) ? debtData.rows : [];
                    supplierDebt = sumDebtRowsForCurrency(rows, isDinar ? "dinar" : "toman");
                } catch (debtError) {
                    console.error("Failed to fetch supplier debt:", debtError);
                    supplierDebtUnavailable = true;
                }
            }

            setStats({
                total_balance: totalBalance,
                balance_is_dinar: useDinarBalance,
                gross_profit: grossProfit,
                supplier_debt: supplierDebtUnavailable ? null : supplierDebt,
                supplier_debt_unavailable: supplierDebtUnavailable,
                top_books: Array.isArray(topBooks) ? topBooks : [],
            });
        } catch (error) {
            console.error("Failed to fetch finance data:", error);
            notifyRef.current.error("finance.loadError");
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [currency, isDinar, user?.role, userBranchId]);

    const clearSettlementSession = useCallback(() => {
        setSuppliers([]);
        setSettlementData([]);
        setSettlements([]);
        setSettlementSessionKey((key) => key + 1);
    }, []);

    const refreshHistory = useCallback(async () => {
        if (!historyUrl) {
            return;
        }
        setHistoryLoading(true);
        try {
            const data = await apiRequest(historyUrl);
            const list = data?.data ?? data ?? [];
            setSettlements(Array.isArray(list) ? list : []);
        } catch (error) {
            console.error("Failed to fetch settlements:", error);
            notifyRef.current.error("finance.historyLoadError");
            setSettlements([]);
        } finally {
            setHistoryLoading(false);
        }
    }, [historyUrl]);

    useEffect(() => {
        fetchOverview();
    }, [fetchOverview]);

    useEffect(() => {
        if (!requiresBranchPicker || (activeTab !== "settlement" && activeTab !== "history")) return;
        apiRequest("/branches?lite=1")
            .then((data) => {
                const rows = (Array.isArray(data) ? data : []).filter(
                    (b: { type?: string }) => b.type === "store" || b.type === "warehouse"
                );
                setSettlementBranches(rows);
            })
            .catch(console.error);
    }, [activeTab, requiresBranchPicker]);

    useEffect(() => {
        if (activeTab !== "settlement") return;
        if (!effectiveSettlementBranchId) {
            setSuppliers([]);
            return;
        }
        let cancelled = false;
        apiRequest(supplierAccountsUrl(Number(effectiveSettlementBranchId), true))
            .then((suppliersData) => {
                if (cancelled) return;
                const rows = Array.isArray(suppliersData) ? suppliersData : [];
                setSuppliers(rows.map((row: any) => ({
                    id: row.id,
                    name: row.display_name || row.name || `#${row.id}`,
                })));
            })
            .catch((error) => {
                if (cancelled) return;
                console.error("Failed to fetch supplier accounts:", error);
                setSuppliers([]);
                notifyRef.current.error("finance.loadError");
            });
        return () => {
            cancelled = true;
        };
    }, [activeTab, effectiveSettlementBranchId]);

    useEffect(() => {
        if (activeTab !== "history" || !historyScopeKey || !historyUrl) {
            return;
        }

        setSettlements([]);
        setHistoryLoading(true);
        let cancelled = false;

        apiRequest(historyUrl)
            .then((data) => {
                if (cancelled) return;
                const list = data?.data ?? data ?? [];
                setSettlements(Array.isArray(list) ? list : []);
            })
            .catch((error) => {
                if (cancelled) return;
                console.error("Failed to fetch settlements:", error);
                notifyRef.current.error("finance.historyLoadError");
                setSettlements([]);
            })
            .finally(() => {
                if (!cancelled) setHistoryLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [activeTab, historyScopeKey, historyUrl]);

    const handleSettlementBranchChange = (branchId: string) => {
        setHistoryAggregate(false);
        setSettlementBranchId(branchId);
        clearSettlementSession();
    };

    const handleHistoryAggregateChange = (enabled: boolean) => {
        setHistoryAggregate(enabled);
        if (enabled) {
            setSettlementBranchId("");
        }
        clearSettlementSession();
    };

    const historyScopePicker = requiresBranchPicker && activeTab === "history" ? (
        <div className="max-w-md space-y-3">
            <div className="space-y-1.5">
                <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 flex items-center gap-1.5">
                    <Building2 className="w-3 h-3" />
                    {t("distribution.branchFallback")}
                </label>
                <select
                    value={historyAggregate ? "" : settlementBranchId}
                    disabled={historyAggregate}
                    onChange={(e) => handleSettlementBranchChange(e.target.value)}
                    className="h-10 w-full rounded-xl border border-ink/10 bg-white/70 px-3 text-[12px] font-vazirmatn outline-none disabled:opacity-50"
                >
                    <option value="">{t("expenses.form.selectBranch")}</option>
                    {settlementBranches.map((b) => (
                        <option key={b.id} value={b.id}>{b.name}</option>
                    ))}
                </select>
            </div>
            <label className="flex items-center gap-2 text-[11px] font-bold text-ink/55">
                <input
                    type="checkbox"
                    checked={historyAggregate}
                    onChange={(e) => handleHistoryAggregateChange(e.target.checked)}
                    className="rounded border-ink/20 text-primary focus:ring-primary/30"
                />
                {t("finance.settlementHistoryAggregate")}
            </label>
        </div>
    ) : null;

    const branchPicker = requiresBranchPicker && activeTab === "settlement" ? (
        <div className="max-w-xs space-y-1.5">
            <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 flex items-center gap-1.5">
                <Building2 className="w-3 h-3" />
                {t("distribution.branchFallback")}
            </label>
            <select
                value={settlementBranchId}
                onChange={(e) => handleSettlementBranchChange(e.target.value)}
                className="h-10 w-full rounded-xl border border-ink/10 bg-white/70 px-3 text-[12px] font-vazirmatn outline-none"
            >
                <option value="">{t("expenses.form.selectBranch")}</option>
                {settlementBranches.map((b) => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                ))}
            </select>
        </div>
    ) : null;

    const handleCalculateSettlement = async (supplierAccountId: number, fromDate: string, toDate: string) => {
        setIsSettlementLoading(true);
        try {
            const branchQs = effectiveSettlementBranchId ? `&branch_id=${effectiveSettlementBranchId}` : "";
            const data = await apiRequest(
                `/consignments/settlement-preview?supplier_account_id=${supplierAccountId}&period_start=${fromDate}&period_end=${toDate}&currency=${currency}${branchQs}`
            );
            const items = (data.items || []).map((item: any) => {
                const total = Number(item.open_amount ?? item.total ?? 0);
                const qty = Number(item.open_qty ?? item.qty_sold ?? 0);
                const remainingQty = Number(item.remaining_qty ?? 0);
                const price = Number(item.unit_cost ?? item.cost_price ?? 0);
                const commission = Number(
                    item.commission ?? 0
                );
                const bookId = item.book_id != null ? Number(item.book_id) : null;
                return {
                    title: item.title || (bookId ? `#${bookId}` : "—"),
                    qty,
                    remainingQty,
                    branchId: item.branch_id != null ? Number(item.branch_id) : null,
                    branchName: item.branch_name ? String(item.branch_name) : null,
                    price,
                    total,
                    commission,
                    publisherShare: Number(item.publisher_share ?? total - commission),
                };
            });
            setSettlementData(items);
            setSettlementBreakdown(data.breakdown || null);
            if (items.length === 0) notify.info("finance.settlement.noData");
        } catch (error) {
            console.error("Calculation failed:", error);
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.settlementError");
            setSettlementData([]);
            setSettlementBreakdown(null);
        } finally {
            setIsSettlementLoading(false);
        }
    };

    const handleConfirmSettlement = async (supplierAccountId: number, fromDate: string, toDate: string, amount: number) => {
        setIsConfirming(true);
        try {
            const result = await apiRequest("/consignments/settle", {
                method: "POST",
                body: JSON.stringify({
                    supplier_account_id: supplierAccountId,
                    period_type: "custom",
                    period_start: fromDate,
                    period_end: toDate,
                    amount,
                    currency,
                    payment_method: "bank_transfer",
                    ...(effectiveSettlementBranchId ? { branch_id: Number(effectiveSettlementBranchId) } : {}),
                }),
            });
            notify.success("toast.settlementSuccess");
            setSettlementData([]);
            fetchOverview(true);
            if (activeTab === "history") refreshHistory();
            return result;
        } catch (error) {
            console.error("Settlement failed:", error);
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.settlementError");
            return false;
        } finally {
            setIsConfirming(false);
        }
    };

    const maxProfit = stats?.top_books?.length
        ? Math.max(...stats.top_books.map((i: any) => Number(i.total_revenue || 0)), 1)
        : 1;

    const kpis = [
        {
            label: t("finance.totalBalance"),
            hint: t("finance.totalCashBalance"),
            value: stats?.total_balance || 0,
            symbol: stats?.balance_is_dinar
                ? t("common.currency.dinarSymbol")
                : t("common.currency.tomanSymbol"),
            icon: Wallet,
            color: "text-primary",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
        },
        {
            label: t("finance.grossProfit"),
            hint: t("finance.grossProfitThisMonth"),
            value: stats?.gross_profit || 0,
            symbol: currencySymbol,
            icon: TrendingUp,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/50",
        },
        {
            label: t("finance.supplierDebt"),
            hint: stats?.supplier_debt_unavailable
                ? t("finance.supplierDebtUnavailable")
                : t("finance.overdueDebt"),
            value: stats?.supplier_debt_unavailable ? null : (stats?.supplier_debt ?? 0),
            unavailable: stats?.supplier_debt_unavailable ?? false,
            symbol: currencySymbol,
            icon: Receipt,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/50",
        },
    ];

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("finance.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-0.5">{t("finance.overview")}</p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                    <div className="flex gap-1 bg-white/50 border border-white/70 rounded-xl p-1 shadow-sm">
                        <TabBtn active={activeTab === "overview"} onClick={() => setActiveTab("overview")}
                            icon={<BarChart2 className="w-3.5 h-3.5" />} label={t("finance.overview")} />
                        <TabBtn active={activeTab === "settlement"} onClick={() => setActiveTab("settlement")}
                            icon={<FileText className="w-3.5 h-3.5" />} label={t("finance.settlementTab")} />
                        <TabBtn active={activeTab === "history"} onClick={() => setActiveTab("history")}
                            icon={<History className="w-3.5 h-3.5" />} label={t("finance.settlementHistory")} />
                    </div>
                    <button
                        type="button"
                        title={t("common.refresh")}
                        aria-label={t("common.refresh")}
                        disabled={isLoading || isRefreshing}
                        onClick={() => {
                            if (activeTab === "overview") fetchOverview(true);
                            else if (activeTab === "history") refreshHistory();
                            else if (effectiveSettlementBranchId) {
                                apiRequest(supplierAccountsUrl(Number(effectiveSettlementBranchId), true))
                                    .then((suppliersData) => {
                                        const rows = Array.isArray(suppliersData) ? suppliersData : [];
                                        setSuppliers(rows.map((row: any) => ({
                                            id: row.id,
                                            name: row.display_name || row.name || `#${row.id}`,
                                        })));
                                    })
                                    .catch(console.error);
                            }
                        }}
                        className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing || historyLoading) && "animate-spin")} />
                    </button>
                </div>
            </div>

            {activeTab === "overview" && (
                <div className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        {kpis.map((kpi) => (
                            <Card key={kpi.label} className={cn("border bg-white/70 rounded-xl", kpi.border)}>
                                <CardContent className={cn("p-3.5", kpi.bg)}>
                                    <div className="flex items-center justify-between mb-2">
                                        <p className="text-[9px] font-bold text-ink/40 truncate">{kpi.label}</p>
                                        <kpi.icon className={cn("w-4 h-4", kpi.color)} />
                                    </div>
                                    <p className={cn("text-xl font-black font-vazirmatn tabular-nums leading-none", kpi.color)}>
                                        {isLoading && !stats
                                            ? "…"
                                            : (kpi as { unavailable?: boolean }).unavailable
                                                ? "—"
                                                : formatNumber(kpi.value ?? 0)}
                                        {!((kpi as { unavailable?: boolean }).unavailable) && (
                                            <span className="text-[10px] text-ink/30 ms-1 font-bold">{kpi.symbol}</span>
                                        )}
                                    </p>
                                    <p className="text-[9px] text-ink/30 mt-1.5">{kpi.hint}</p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    <ProfitCharts currency={currency} />

                    {activeTab === "overview" && <LedgerReportsPanel />}

                    <Card className="border border-white/70 bg-white/60 rounded-2xl overflow-hidden">
                        <CardHeader className="px-4 py-3 border-b border-ink/5 flex flex-row items-center justify-between">
                            <CardTitle className="text-[13px] font-black font-vazirmatn text-ink">
                                {t("finance.topItems")}
                            </CardTitle>
                            <span className="text-[9px] font-bold text-ink/30">{t("finance.thisMonth")}</span>
                        </CardHeader>
                        <CardContent className="p-3 space-y-1.5">
                            {isLoading && !stats ? (
                                Array.from({ length: 3 }).map((_, i) => (
                                    <div key={i} className="h-12 bg-parchment/20 rounded-xl animate-pulse" />
                                ))
                            ) : stats?.top_books?.length ? (
                                stats.top_books.map((item, i) => (
                                    <div key={i} className="flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/80 transition-colors">
                                        <div className={cn(
                                            "w-6 h-6 rounded-lg bg-gradient-to-br flex items-center justify-center font-black text-[10px] text-white font-vazirmatn shrink-0",
                                            RANK_COLORS[i] || RANK_COLORS[2]
                                        )}>
                                            {formatNumber(i + 1)}
                                        </div>
                                        <div className="flex-1 min-w-0 space-y-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-[12px] font-black font-vazirmatn text-ink truncate text-end flex-1">
                                                    {item.title}
                                                </span>
                                                <span className="text-[9px] text-ink/30 font-bold shrink-0">
                                                    {t("finance.salesCount", { count: item.total_sold })}
                                                </span>
                                            </div>
                                            <div className="h-1 bg-ink/5 rounded-full overflow-hidden">
                                                <div
                                                    className={cn("h-full rounded-full bg-gradient-to-r", RANK_COLORS[i] || RANK_COLORS[2])}
                                                    style={{ width: `${(Number(item.total_revenue || 0) / maxProfit) * 100}%` }}
                                                />
                                            </div>
                                        </div>
                                        <div className="text-end shrink-0">
                                            <span className="text-[12px] font-black text-primary font-vazirmatn tabular-nums">
                                                {formatNumber(Number(item.total_revenue || 0))}
                                            </span>
                                            <span className="text-[8px] text-primary/35 ms-0.5">{currencySymbol}</span>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <div className="py-12 text-center text-ink/25 text-[11px] font-black">
                                    {t("finance.noTopItems")}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {activeTab === "settlement" && (
                <div className="space-y-6">
                    {branchPicker}
                    {!canUseSettlement ? (
                        <Card className="border border-white/70 bg-white/70 rounded-2xl">
                            <CardContent className="p-8 text-center text-[12px] font-black text-ink/35">
                                {t("expenses.form.selectBranch")}
                            </CardContent>
                        </Card>
                    ) : (
                        <>
                            <SettlementWizard
                                suppliers={suppliers}
                                onCalculate={handleCalculateSettlement}
                                onConfirm={handleConfirmSettlement}
                                settlementData={settlementData}
                                breakdown={settlementBreakdown}
                                isLoading={isSettlementLoading}
                                isConfirming={isConfirming}
                                currencySymbol={currencySymbol}
                                branchId={Number(effectiveSettlementBranchId)}
                                disabled={!canUseSettlement}
                                sessionKey={settlementSessionKey}
                            />
                            <BulkSettlementPanel
                                branchId={Number(effectiveSettlementBranchId)}
                                currency={currency}
                                currencySymbol={currencySymbol}
                                reloadKey={settlementSessionKey}
                            />
                        </>
                    )}
                </div>
            )}

            {activeTab === "history" && (
                <div className="space-y-4">
                    {historyScopePicker}
                    {!historyAggregate && !effectiveSettlementBranchId ? (
                        <Card className="border border-white/70 bg-white/70 rounded-2xl">
                            <CardContent className="p-8 text-center text-[12px] font-black text-ink/35">
                                {t("expenses.form.selectBranch")}
                            </CardContent>
                        </Card>
                    ) : historyLoading && !settlements.length ? (
                        Array.from({ length: 4 }).map((_, i) => (
                            <div key={i} className="h-16 bg-parchment/20 rounded-xl animate-pulse" />
                        ))
                    ) : settlements.length === 0 ? (
                        <Card className="border border-white/70 bg-white/70 rounded-2xl">
                            <CardContent className="p-10 text-center text-ink/30 text-[12px] font-black flex flex-col items-center gap-2">
                                <AlertTriangle className="w-5 h-5 text-ink/20" />
                                {t("finance.historyEmpty")}
                            </CardContent>
                        </Card>
                    ) : (
                        settlements.map((s) => (
                            <div
                                key={s.id}
                                className="rounded-xl border border-white/80 bg-white/75 px-3.5 py-3 flex flex-col sm:flex-row sm:items-center gap-3"
                            >
                                <div className="flex items-start gap-2.5 flex-1 min-w-0">
                                    <div className="w-8 h-8 rounded-lg bg-primary/10 border border-primary/10 flex items-center justify-center shrink-0">
                                        <FileText className="w-3.5 h-3.5 text-primary" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="text-[12px] font-black font-vazirmatn text-ink truncate">
                                                {s.settlement_number}
                                            </span>
                                            <span className="text-[9px] font-mono text-ink/30">
                                                {s.payment_method}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2.5 mt-1 flex-wrap text-[9px] text-ink/35">
                                            <span className="flex items-center gap-0.5">
                                                <Building2 className="w-2.5 h-2.5" />
                                                {s.supplier?.name || "—"}
                                            </span>
                                            <span className="flex items-center gap-0.5">
                                                <CalendarDays className="w-2.5 h-2.5" />
                                                {formatPeriodDate(s.period_start)} — {formatPeriodDate(s.period_end)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2.5 shrink-0">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            printSettlement({
                                                supplierName: s.supplier?.name || "—",
                                                fromDate: formatPeriodDate(s.period_start),
                                                toDate: formatPeriodDate(s.period_end),
                                                items: [],
                                                formatNumber,
                                                currencySymbol: s.currency === "dinar"
                                                    ? t("common.currency.dinarSymbol")
                                                    : t("common.currency.tomanSymbol"),
                                                labels: buildSettlementPrintLabels(t),
                                                dir: "rtl",
                                                lang: language === "ar" ? "ar" : "fa",
                                                variant: "invoice",
                                                settledAmount: Number(s.amount || 0),
                                                docNumber: s.settlement_number,
                                                issueDate: formatPeriodDate(s.paid_at || s.created_at),
                                                settlementId: s.id,
                                            }).catch(() => notify.error("toast.invoicePrintError"));
                                        }}
                                        className="h-8 rounded-lg border border-ink/10 bg-white px-2.5 text-[10px] font-black text-ink/55 hover:border-primary/20 hover:text-primary"
                                    >
                                        {t("finance.settlement.printInvoice")}
                                    </button>
                                    <div className="text-end">
                                        <p className="text-[14px] font-black font-vazirmatn tabular-nums text-primary leading-none">
                                            {formatNumber(Number(s.amount || 0))}
                                        </p>
                                        <p className="text-[8px] text-ink/30 mt-0.5">
                                            {s.currency === "dinar"
                                                ? t("common.currency.dinarSymbol")
                                                : t("common.currency.tomanSymbol")}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

function TabBtn({ active, onClick, icon, label }: {
    active: boolean; onClick: () => void; icon: React.ReactNode; label: string;
}) {
    return (
        <button type="button" onClick={onClick}
            className={cn(
                "flex items-center gap-1.5 px-3 py-2 rounded-[9px] font-black text-[10px] font-vazirmatn transition-all",
                active
                    ? "bg-white shadow-md text-primary"
                    : "text-ink/35 hover:bg-white/50 hover:text-ink/60"
            )}>
            {icon}
            <span className="hidden sm:inline">{label}</span>
        </button>
    );
}
