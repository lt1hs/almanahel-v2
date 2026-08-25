"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useState, useEffect, useCallback } from "react";
import { motion } from "framer-motion";
import {
    BarChart3, TrendingUp, BookOpen, AlertTriangle, CreditCard,
    Building2, Globe2, ArrowRight, HandCoins,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useRouter } from "@/i18n/routing";
import { apiRequest } from "@/lib/api";
import { buildBookLowStockUrl } from "@/lib/bookCatalogRequests";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import { RequireRole } from "@/components/auth/RequireRole";

export default function ReportsPage() {
    return (
        <RequireRole roles={["super_admin", "admin", "accountant"]}>
            <ReportsPageContent />
        </RequireRole>
    );
}

function ReportsPageContent() {
    const { t, formatNumber, isArabic, isDinar } = useTranslation();
    const { user } = useAuth();
    const router = useRouter();
    const currencySymbol = isDinar ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");

    const [dashboard, setDashboard] = useState<any>(null);
    const [branches, setBranches] = useState<any[]>([]);
    const [topBooks, setTopBooks] = useState<any[]>([]);
    const [iraqProfit, setIraqProfit] = useState<any>(null);
    const [lowStock, setLowStock] = useState<any[]>([]);
    const [distribution, setDistribution] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);

    const fetchReports = useCallback(async () => {
        setIsLoading(true);
        try {
            const lowStockUrl = user?.branch_id
                ? buildBookLowStockUrl(
                    { role: user.role, branch_id: user.branch_id },
                    Number(user.branch_id)
                )
                : null;

            const [dash, branchData, books, iraq, stock, dist] = await Promise.all([
                apiRequest("/reports/dashboard"),
                apiRequest("/reports/all-branches"),
                apiRequest(`/reports/top-books?currency=${isDinar ? "dinar" : "toman"}`),
                apiRequest(`/reports/iraq-profit?currency=${isDinar ? "dinar" : "toman"}`).catch(() => null),
                lowStockUrl ? apiRequest(lowStockUrl).catch(() => []) : Promise.resolve([]),
                apiRequest("/reports/distribution-from-qom").catch(() => null),
            ]);
            setDashboard(dash);
            setBranches(Array.isArray(branchData) ? branchData : []);
            setTopBooks(Array.isArray(books) ? books : []);
            setIraqProfit(iraq);
            setLowStock(Array.isArray(stock) ? stock : []);
            setDistribution(dist);
        } catch (error) {
            console.error("Failed to fetch reports:", error);
        } finally {
            setIsLoading(false);
        }
    }, [isDinar, user?.branch_id, user?.role]);

    useEffect(() => {
        fetchReports();
    }, [fetchReports]);

    const totalNet = branches.reduce(
        (a, b) => a + Number(isDinar ? b.net_profit_dinar || 0 : b.net_profit_toman || 0),
        0
    );

    const kpiItems = [
        { label: t("reports.kpi.todaySalesToman"), value: dashboard?.today_sales_toman, icon: TrendingUp, color: "text-emerald-500" },
        { label: t("reports.kpi.todaySalesDinar"), value: dashboard?.today_sales_dinar, icon: Globe2, color: "text-sky-500" },
        { label: isDinar ? t("finance.branchProfit.currencyDinar") : t("finance.branchProfit.currencyToman"), value: totalNet, icon: BarChart3, color: "text-primary" },
        { label: t("reports.kpi.lowStockAlert"), value: lowStock.length || dashboard?.low_stock_count, icon: AlertTriangle, color: "text-rose-500" },
    ];

    const quickLinks = [
        { label: t("reports.links.overdueChecks"), href: "/dashboard/checks", icon: CreditCard },
        { label: t("reports.links.overdueCredits"), href: "/dashboard/credits", icon: HandCoins },
        { label: t("reports.links.finance"), href: "/dashboard/finance", icon: BarChart3 },
        { label: t("reports.links.distribution"), href: "/dashboard/distribution", icon: Building2 },
    ];

    return (
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} className="space-y-6 pb-10">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("reports.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">
                        {t("reports.subtitle")}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" className="h-9 rounded-xl text-[11px]" onClick={fetchReports} disabled={isLoading}>
                        {t("common.refresh")}
                    </Button>
                    <Button variant="ghost" size="sm" className="h-9 rounded-xl text-[11px]"
                        onClick={() => router.push("/dashboard/finance/branch-profit")}>
                        {t("nav.branchProfit")}
                        <ArrowRight className={cn("w-3.5 h-3.5 ms-1", isArabic && "rotate-180")} />
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                {kpiItems.map((kpi, i) => (
                    <Card key={i} className="border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl">
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between mb-2">
                                <p className="text-[9px] font-black text-ink/30 uppercase tracking-widest">{kpi.label}</p>
                                <kpi.icon className={cn("w-4 h-4 opacity-40", kpi.color)} />
                            </div>
                            <p className={cn("text-xl font-black font-vazirmatn", kpi.color)}>
                                {isLoading ? "…" : formatNumber(kpi.value || 0)}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card className="border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl">
                    <CardHeader className="px-5 py-4 border-b border-ink/5">
                        <CardTitle className="text-[14px] font-black font-vazirmatn flex items-center gap-2">
                            <Building2 className="w-4 h-4 text-primary" />
                            {t("reports.branchPerformanceMonth")}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0 divide-y divide-ink/5">
                        {isLoading ? (
                            Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-14 animate-pulse bg-parchment/20" />)
                        ) : branches.map((item) => (
                            <div key={item.branch.id} className="px-5 py-3.5 flex items-center justify-between">
                                <div>
                                    <p className="text-[12px] font-black font-vazirmatn">{item.branch.name}</p>
                                    <p className="text-[9px] text-ink/35">{item.branch.city}</p>
                                </div>
                                <div className="text-end">
                                    <p className="text-[12px] font-black text-primary font-vazirmatn">
                                        {formatNumber(item.net_profit_toman)} {t("common.currency.tomanSymbol")}
                                    </p>
                                    {item.net_profit_dinar > 0 && (
                                        <p className="text-[9px] text-ink/35">{formatNumber(item.net_profit_dinar)} {t("common.currency.dinarSymbol")}</p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card className="border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl">
                    <CardHeader className="px-5 py-4 border-b border-ink/5">
                        <CardTitle className="text-[14px] font-black font-vazirmatn flex items-center gap-2">
                            <BookOpen className="w-4 h-4 text-amber-500" />
                            {t("dashboard.topBooks")}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0 divide-y divide-ink/5">
                        {isLoading ? (
                            Array.from({ length: 5 }).map((_, i) => <div key={i} className="h-12 animate-pulse bg-parchment/20" />)
                        ) : topBooks.slice(0, 8).map((book, i) => (
                            <div key={i} className="px-5 py-3 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2 min-w-0">
                                    <Badge className="text-[8px] shrink-0">{i + 1}</Badge>
                                    <span className="text-[11px] font-black font-vazirmatn truncate">{book.title}</span>
                                </div>
                                <span className="text-[11px] font-black text-primary shrink-0">
                                    {formatNumber(book.total_revenue || book.revenue || 0)} {currencySymbol}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                {iraqProfit && (
                    <Card className="border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl lg:col-span-2">
                        <CardHeader className="px-5 py-4 border-b border-ink/5">
                            <CardTitle className="text-[14px] font-black font-vazirmatn flex items-center gap-2">
                                <Globe2 className="w-4 h-4 text-sky-500" />
                                {t("reports.iraqProfit.title")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-5 space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                {[
                                    { label: t("reports.iraqProfit.revenue"), value: iraqProfit.revenue },
                                    { label: t("reports.iraqProfit.expenses"), value: iraqProfit.expenses },
                                    { label: t("reports.iraqProfit.netProfit"), value: iraqProfit.net_profit },
                                    { label: t("reports.iraqProfit.salesCount"), value: iraqProfit.sales_count },
                                ].map((item, i) => (
                                    <div key={i} className="p-3 rounded-xl bg-ink/[0.02] border border-ink/5">
                                        <p className="text-[9px] text-ink/30 font-black uppercase mb-1">{item.label}</p>
                                        <p className="text-lg font-black font-vazirmatn text-ink">{formatNumber(item.value || 0)}</p>
                                    </div>
                                ))}
                            </div>
                            {(iraqProfit.iraq_local || iraqProfit.qom_distributed) && (
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                                    {iraqProfit.iraq_local && (
                                        <div className="p-3 rounded-xl bg-sky-50/60 border border-sky-100/80">
                                            <p className="text-[10px] font-black text-sky-700/80 mb-2">
                                                {t("reports.iraqProfit.iraqLocal")}
                                            </p>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-vazirmatn text-ink/70">
                                                <span>{t("reports.iraqProfit.revenue")}: {formatNumber(iraqProfit.iraq_local.revenue || 0)}</span>
                                                <span>{t("reports.iraqProfit.netProfit")}: {formatNumber(iraqProfit.iraq_local.net_profit || 0)}</span>
                                                <span>{t("reports.iraqProfit.salesCount")}: {formatNumber(iraqProfit.iraq_local.sales_count || 0)}</span>
                                            </div>
                                        </div>
                                    )}
                                    {iraqProfit.qom_distributed && (
                                        <div className="p-3 rounded-xl bg-primary/[0.04] border border-primary/10">
                                            <p className="text-[10px] font-black text-primary/80 mb-2">
                                                {t("reports.iraqProfit.qomDistributed")}
                                            </p>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-vazirmatn text-ink/70">
                                                <span>{t("reports.iraqProfit.revenue")}: {formatNumber(iraqProfit.qom_distributed.revenue || 0)}</span>
                                                <span>{t("reports.iraqProfit.netProfit")}: {formatNumber(iraqProfit.qom_distributed.net_profit || 0)}</span>
                                                <span>{t("reports.iraqProfit.salesCount")}: {formatNumber(iraqProfit.qom_distributed.sales_count || 0)}</span>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {distribution?.summary?.length > 0 && (
                    <Card className="border border-white/70 bg-white/70 backdrop-blur-xl rounded-2xl lg:col-span-2">
                        <CardHeader className="px-5 py-4 border-b border-ink/5">
                            <CardTitle className="text-[14px] font-black font-vazirmatn flex items-center gap-2">
                                <Building2 className="w-4 h-4 text-primary" />
                                {t("reports.distributionFromQom.title")}
                            </CardTitle>
                            <p className="text-[9px] text-ink/35 mt-1">{t("reports.distributionFromQom.subtitle")}</p>
                        </CardHeader>
                        <CardContent className="p-0 divide-y divide-ink/5">
                            {distribution.summary.map((row: any) => (
                                <div key={row.branch_id} className="px-5 py-3.5 flex items-center justify-between">
                                    <div>
                                        <p className="text-[12px] font-black font-vazirmatn">{row.branch_name}</p>
                                        <p className="text-[9px] text-ink/35">
                                            {formatNumber(row.transfer_count)} {t("reports.distributionFromQom.transferCount")}
                                        </p>
                                    </div>
                                    <Badge className="text-[10px] font-black">
                                        {formatNumber(row.total_books)} {t("reports.distributionFromQom.totalBooks")}
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card className="border border-rose-100 bg-rose-50/30 backdrop-blur-xl rounded-2xl lg:col-span-2">
                    <CardHeader className="px-5 py-4 border-b border-rose-100">
                        <CardTitle className="text-[14px] font-black font-vazirmatn flex items-center gap-2 text-rose-600">
                            <AlertTriangle className="w-4 h-4" />
                            {t("reports.lowStockBooks")} ({lowStock.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0 divide-y divide-rose-100/60 max-h-64 overflow-y-auto">
                        {lowStock.length === 0 ? (
                            <p className="p-5 text-[11px] text-ink/30 text-center">{t("distribution.alerts.healthy")}</p>
                        ) : lowStock.slice(0, 15).map((item, i) => (
                            <div key={i} className="px-5 py-3 flex items-center justify-between">
                                <div>
                                    <p className="text-[11px] font-black font-vazirmatn">{item.book?.title}</p>
                                    <p className="text-[9px] text-ink/35">{item.branch?.name}</p>
                                </div>
                                <Badge className="text-[9px] bg-rose-100 text-rose-600 border-rose-200">
                                    {item.quantity} {t("common.units.volume")}
                                </Badge>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-wrap gap-2">
                {quickLinks.map((link) => (
                    <Button key={link.href} variant="outline" size="sm" className="h-9 rounded-xl text-[10px] font-black"
                        onClick={() => router.push(link.href)}>
                        <link.icon className="w-3.5 h-3.5 ms-1" />
                        {link.label}
                    </Button>
                ))}
            </div>
        </motion.div>
    );
}
