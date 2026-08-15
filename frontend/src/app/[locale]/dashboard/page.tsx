"use client";

import React, { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Variants, motion } from "framer-motion";
import {
    BookOpen,
    Users,
    TrendingUp,
    AlertTriangle,
    ArrowUpRight,
    Plus,
    Calendar,
    ChevronLeft,
    Bell,
    Package,
    Banknote,
    CreditCard,
    RefreshCw,
    Truck,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useRouter } from "@/i18n/routing";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";

const containerVariants: Variants = {
    hidden: { opacity: 0 },
    show: {
        opacity: 1,
        transition: { staggerChildren: 0.03 },
    },
};

const itemVariants: Variants = {
    hidden: { opacity: 0, y: 8 },
    show: { opacity: 1, y: 0, transition: { duration: 0.2, ease: "easeOut" } },
};

interface DashboardData {
    total_titles: number;
    total_stock: number;
    total_books: number;
    today_sales_toman: number;
    today_sales_dinar: number;
    today_invoice_count?: number;
    total_suppliers: number;
    total_branches?: number;
    low_stock_count: number;
    out_of_stock_count?: number;
    pending_checks: number;
    inventory_value_toman?: number;
    inventory_value_dinar?: number;
}

type TransferAlertType =
    | "transfer_pending"
    | "transfer_incoming"
    | "transfer_shipped"
    | "transfer_received";
type AlertType = "low_stock" | "check_due" | "credit_due" | TransferAlertType;
type AlertFilter = "all" | "low_stock" | "check_due" | "credit_due" | "transfer";

function isTransferAlert(type: string): type is TransferAlertType {
    return type.startsWith("transfer_");
}

interface Notification {
    type: AlertType;
    message: string;
    data: Record<string, unknown>;
}

async function fetchDashboardBundle() {
    const [stats, notifs] = await Promise.all([
        apiRequest("/reports/dashboard"),
        apiRequest("/reports/notifications"),
    ]);
    return {
        stats: stats as DashboardData,
        notifications: [
            ...(notifs.transfers ?? []),
            ...(notifs.low_stock ?? []),
            ...(notifs.due_checks ?? []),
            ...(notifs.due_credits ?? []),
        ] as Notification[],
    };
}

function formatDue(value: unknown): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

function alertHref(n: Notification): string {
    if (isTransferAlert(n.type)) return "/dashboard/distribution";
    if (n.type === "low_stock") return "/dashboard/inventory";
    if (n.type === "check_due") return "/dashboard/checks";
    if (n.type === "credit_due") {
        const id = n.data?.invoice_id;
        return id ? `/dashboard/invoices?id=${id}` : "/dashboard/credits";
    }
    return "/dashboard";
}

export default function DashboardPage() {
    const { user } = useAuth();
    const { t, formatNumber, language, isArabic } = useTranslation();
    const router = useRouter();
    const [alertFilter, setAlertFilter] = useState<AlertFilter>("all");

    const { data: bundle, isLoading, isFetching, refetch } = useQuery({
        queryKey: ["dashboard"],
        queryFn: fetchDashboardBundle,
    });

    const data = bundle?.stats ?? null;
    const notifications = bundle?.notifications ?? [];

    const counts = useMemo(() => ({
        all: notifications.length,
        transfer: notifications.filter((n) => isTransferAlert(n.type)).length,
        low_stock: notifications.filter((n) => n.type === "low_stock").length,
        check_due: notifications.filter((n) => n.type === "check_due").length,
        credit_due: notifications.filter((n) => n.type === "credit_due").length,
    }), [notifications]);

    const filteredAlerts = useMemo(() => {
        const list = alertFilter === "all"
            ? notifications
            : alertFilter === "transfer"
                ? notifications.filter((n) => isTransferAlert(n.type))
                : notifications.filter((n) => n.type === alertFilter);
        return list.slice(0, 12);
    }, [notifications, alertFilter]);

    const alertLabel = (type: AlertType) => {
        if (type === "transfer_pending") return t("common.notifications.transferPending");
        if (type === "transfer_incoming") return t("common.notifications.transferIncoming");
        if (type === "transfer_shipped") return t("common.notifications.transferShipped");
        if (type === "transfer_received") return t("common.notifications.transferReceived");
        if (type === "low_stock") return t("common.notifications.lowStock");
        if (type === "check_due") return t("common.notifications.checkDue");
        return t("common.notifications.creditDue");
    };

    const alertMessage = (n: Notification) => {
        const d = n.data || {};
        if (n.type === "transfer_pending") {
            return t("common.notifications.transferPendingMsg", {
                book: String(d.book_title || t("distribution.bookFallback")),
                to: String(d.to_branch || t("distribution.branchFallback")),
            });
        }
        if (n.type === "transfer_incoming") {
            return t("common.notifications.transferIncomingMsg", {
                book: String(d.book_title || t("distribution.bookFallback")),
                from: String(d.from_branch || t("distribution.branchFallback")),
            });
        }
        if (n.type === "transfer_shipped") {
            return t("common.notifications.transferShippedMsg", {
                book: String(d.book_title || t("distribution.bookFallback")),
                from: String(d.from_branch || t("distribution.branchFallback")),
            });
        }
        if (n.type === "transfer_received") {
            return t("common.notifications.transferReceivedMsg", {
                book: String(d.book_title || t("distribution.bookFallback")),
                to: String(d.to_branch || t("distribution.branchFallback")),
            });
        }
        if (n.type === "low_stock") {
            if (!d.book_title) return n.message;
            return t("common.notifications.lowStockMsg", {
                book: String(d.book_title || "—"),
                branch: String(d.branch_name || "—"),
                qty: formatNumber(Number(d.quantity || 0)),
            });
        }
        if (n.type === "check_due") {
            if (!d.check_number) return n.message;
            return t("common.notifications.checkDueMsg", {
                number: String(d.check_number || "—"),
                payer: String(d.payer_name || "—"),
                date: formatDue(d.due_date),
            });
        }
        if (!d.invoice_number) return n.message;
        return t("common.notifications.creditDueMsg", {
            invoice: String(d.invoice_number || "—"),
            customer: String(d.customer_name || "—"),
            date: formatDue(d.due_date),
        });
    };

    const alertMeta = (n: Notification) => {
        const d = n.data || {};
        if (isTransferAlert(n.type)) {
            return t("common.notifications.transferMeta", {
                qty: formatNumber(Number(d.quantity || 0)),
                from: String(d.from_branch || "—"),
                to: String(d.to_branch || "—"),
            });
        }
        if (n.type === "low_stock") {
            return t("common.notifications.lowStockMeta", {
                qty: formatNumber(Number(d.quantity || 0)),
                threshold: formatNumber(Number(d.threshold || 0)),
            });
        }
        return formatDue(d.due_date);
    };

    const viewAllHref = () => {
        if (alertFilter === "transfer") return "/dashboard/distribution";
        if (alertFilter === "check_due") return "/dashboard/checks";
        if (alertFilter === "credit_due") return "/dashboard/credits";
        if (alertFilter === "low_stock") return "/dashboard/inventory";
        if (counts.transfer >= counts.low_stock && counts.transfer >= counts.check_due && counts.transfer >= counts.credit_due) {
            return "/dashboard/distribution";
        }
        if (counts.check_due >= counts.low_stock && counts.check_due >= counts.credit_due) {
            return "/dashboard/checks";
        }
        if (counts.credit_due > counts.low_stock) return "/dashboard/credits";
        return "/dashboard/inventory";
    };

    const stats = [
        {
            titleKey: "dashboard.totalBooks",
            value: data?.total_titles ?? data?.total_books ?? 0,
            icon: BookOpen,
            color: "text-primary",
            href: "/dashboard/inventory",
        },
        {
            titleKey: "dashboard.totalStock",
            value: data?.total_stock ?? 0,
            icon: Package,
            color: "text-emerald-600",
            href: "/dashboard/inventory",
        },
        {
            titleKey: "dashboard.totalSuppliers",
            value: data?.total_suppliers || 0,
            icon: Users,
            color: "text-accent",
            href: "/dashboard/suppliers",
        },
        {
            titleKey: "dashboard.totalBranches",
            value: data?.total_branches || 0,
            icon: Truck,
            color: "text-sky-600",
            href: "/dashboard/admin",
        },
        {
            titleKey: "dashboard.todaySales",
            value: isArabic ? (data?.today_sales_dinar || 0) : (data?.today_sales_toman || 0),
            suffixKey: isArabic ? "common.dinar" : "common.toman",
            icon: TrendingUp,
            color: "text-emerald-500",
            href: "/dashboard/sales",
        },
        {
            titleKey: "dashboard.inventoryValue",
            value: isArabic
                ? (data?.inventory_value_dinar || 0)
                : (data?.inventory_value_toman || 0),
            suffixKey: isArabic ? "common.dinar" : "common.toman",
            icon: Banknote,
            color: "text-amber-600",
            href: "/dashboard/reports",
        },
        {
            titleKey: "dashboard.lowStockBooks",
            value: data?.low_stock_count || 0,
            icon: AlertTriangle,
            color: "text-rose-500",
            href: "/dashboard/inventory",
        },
        {
            titleKey: "dashboard.pendingChecks",
            value: data?.pending_checks || 0,
            icon: CreditCard,
            color: "text-violet-600",
            href: "/dashboard/checks",
        },
    ];

    const filters: { key: AlertFilter; label: string; count: number }[] = [
        { key: "all", label: t("common.notifications.filterAll"), count: counts.all },
        { key: "transfer", label: t("common.notifications.transfer"), count: counts.transfer },
        { key: "low_stock", label: t("common.notifications.lowStock"), count: counts.low_stock },
        { key: "check_due", label: t("common.notifications.checkDue"), count: counts.check_due },
        { key: "credit_due", label: t("common.notifications.creditDue"), count: counts.credit_due },
    ];

    return (
        <motion.div
            variants={containerVariants}
            initial="hidden"
            animate="show"
            className="space-y-6 pb-8 relative"
        >
            <div className="absolute top-0 right-0 -translate-y-1/2 w-48 h-48 bg-primary/5 rounded-full blur-[80px] pointer-events-none" />
            <div className="absolute bottom-0 left-0 translate-y-1/2 w-64 h-64 bg-accent/5 rounded-full blur-[100px] pointer-events-none" />

            <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-ink/5">
                <motion.div variants={itemVariants}>
                    <div className="flex items-center gap-2 mb-1 text-primary/60 font-bold text-[9px] uppercase tracking-[0.2em]">
                        <div className="w-6 h-[1px] bg-primary/20" />
                        <Calendar className="w-3 h-3" />
                        <span>
                            {new Date().toLocaleDateString(language === "ar" ? "ar-IQ" : "fa-IR", {
                                weekday: "long",
                                day: "numeric",
                                month: "long",
                            })}
                        </span>
                    </div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink tracking-tight">
                        {t("dashboard.welcomeBack")}, <span className="text-primary/90">{user?.name}</span>
                    </h1>
                </motion.div>

                <motion.div variants={itemVariants} className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 px-3 hover:bg-white/50 backdrop-blur-sm rounded-lg text-[10px] font-bold border border-ink/5"
                        disabled={isFetching}
                        onClick={() => refetch()}
                    >
                        <RefreshCw className={cn("w-3 h-3 ms-1", isFetching && "animate-spin")} />
                        {t("common.refresh")}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        className="h-8 px-4 rounded-lg shadow-lg shadow-primary/5 text-[10px] font-bold active:scale-95 transition-all"
                        onClick={() => router.push("/dashboard/inventory/new")}
                    >
                        <Plus className="w-3.5 h-3.5 ms-1.5" />
                        {t("inventory.addBook")}
                    </Button>
                </motion.div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {stats.map((stat) => (
                    <motion.div
                        key={stat.titleKey}
                        variants={itemVariants}
                        whileHover={{ y: -3 }}
                        className="group relative cursor-pointer"
                        onClick={() => router.push(stat.href)}
                    >
                        <Card className="relative border border-white/60 bg-white/70 backdrop-blur-xl shadow-sm hover:shadow-lg transition-all duration-500 rounded-[14px] overflow-hidden group-hover:border-b-primary/30">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between mb-4">
                                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-white to-parchment border border-ink/5 flex items-center justify-center shadow-inner group-hover:scale-105 transition-transform duration-500">
                                        <stat.icon className={cn("w-4.5 h-4.5", stat.color, "drop-shadow-sm")} />
                                    </div>
                                    <ArrowUpRight className="w-3.5 h-3.5 text-ink/20 group-hover:text-primary/50 transition-colors" />
                                </div>

                                <div className="space-y-0.5">
                                    <p className="text-[9px] font-bold text-ink/40 uppercase tracking-widest leading-none">
                                        {t(stat.titleKey)}
                                    </p>
                                    <div className="flex items-baseline gap-1.5">
                                        <h4 className="text-lg font-black font-vazirmatn text-ink tabular-nums tracking-tighter">
                                            {isLoading ? "…" : formatNumber(stat.value)}
                                        </h4>
                                        {stat.suffixKey && (
                                            <span className="text-[9px] font-vazirmatn text-ink/30 font-medium">
                                                {t(stat.suffixKey)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <motion.div variants={itemVariants} className="lg:col-span-2">
                    <Card className="border border-white/60 bg-white/70 backdrop-blur-xl shadow-sm rounded-2xl overflow-hidden">
                        <CardHeader className="p-4 px-5 flex flex-row items-center justify-between border-b border-ink/5 gap-3">
                            <div className="min-w-0">
                                <CardTitle className="text-sm font-black font-vazirmatn text-ink">
                                    {t("common.notifications.title")}
                                </CardTitle>
                                <p className="text-[9px] text-ink/40 mt-0.5 font-medium">
                                    {counts.all > 0
                                        ? t("common.notifications.count", { count: formatNumber(counts.all) })
                                        : t("common.notifications.noNotifications")}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.push(viewAllHref())}
                                className="text-[10px] font-bold text-primary/70 hover:text-primary transition-all flex items-center gap-1 shrink-0"
                            >
                                {t("common.viewAll")}
                                <ChevronLeft className={cn("w-3 h-3", language === "ar" && "rotate-180")} />
                            </button>
                        </CardHeader>

                        <div className="px-3 pt-3 flex gap-1 overflow-x-auto">
                            {filters.map((f) => (
                                <button
                                    key={f.key}
                                    type="button"
                                    onClick={() => setAlertFilter(f.key)}
                                    className={cn(
                                        "px-2.5 py-1.5 rounded-lg text-[10px] font-black font-vazirmatn whitespace-nowrap transition-all flex items-center gap-1.5",
                                        alertFilter === f.key
                                            ? "bg-white shadow-sm text-primary border border-primary/10"
                                            : "text-ink/35 hover:text-ink/60 border border-transparent"
                                    )}
                                >
                                    {f.label}
                                    <span className={cn(
                                        "min-w-[1.1rem] h-4 px-1 rounded-md text-[9px] font-vazirmatn tabular-nums flex items-center justify-center",
                                        alertFilter === f.key ? "bg-primary/10 text-primary" : "bg-ink/5 text-ink/30"
                                    )}>
                                        {formatNumber(f.count)}
                                    </span>
                                </button>
                            ))}
                        </div>

                        <CardContent className="p-2 px-3 pb-3">
                            <div className="space-y-0.5 max-h-[360px] overflow-y-auto">
                                {isLoading ? (
                                    Array.from({ length: 4 }).map((_, i) => (
                                        <div key={i} className="h-14 bg-parchment/30 rounded-xl animate-pulse m-1" />
                                    ))
                                ) : filteredAlerts.length > 0 ? (
                                    filteredAlerts.map((notif, i) => {
                                        const isTransfer = isTransferAlert(notif.type);
                                        const isLow = notif.type === "low_stock";
                                        const isCheck = notif.type === "check_due";
                                        const Icon = isTransfer ? Truck : isLow ? Package : isCheck ? Banknote : CreditCard;
                                        const tone = isTransfer
                                            ? notif.type === "transfer_shipped"
                                                ? "bg-emerald-50 border-emerald-100 text-emerald-600"
                                                : notif.type === "transfer_pending"
                                                    ? "bg-amber-50 border-amber-100 text-amber-600"
                                                    : notif.type === "transfer_incoming"
                                                        ? "bg-sky-50 border-sky-100 text-sky-600"
                                                        : "bg-emerald-50 border-emerald-100 text-emerald-600"
                                            : isLow
                                                ? "bg-rose-50 border-rose-100 text-rose-500"
                                                : isCheck
                                                    ? "bg-amber-50 border-amber-100 text-amber-600"
                                                    : "bg-sky-50 border-sky-100 text-sky-600";
                                        const badge = isTransfer
                                            ? notif.type === "transfer_shipped"
                                                ? "bg-emerald-100 text-emerald-700"
                                                : notif.type === "transfer_pending"
                                                    ? "bg-amber-100 text-amber-700"
                                                    : notif.type === "transfer_incoming"
                                                        ? "bg-sky-100 text-sky-700"
                                                        : "bg-emerald-100 text-emerald-700"
                                            : isLow
                                                ? "bg-rose-100 text-rose-600"
                                                : isCheck
                                                    ? "bg-amber-100 text-amber-700"
                                                    : "bg-sky-100 text-sky-700";

                                        return (
                                            <button
                                                key={`${notif.type}-${i}-${String(notif.data?.transfer_id ?? notif.data?.inventory_id ?? notif.data?.check_id ?? notif.data?.invoice_id ?? i)}`}
                                                type="button"
                                                onClick={() => router.push(alertHref(notif))}
                                                className="w-full flex items-center gap-3 p-2.5 rounded-xl transition-all text-start hover:bg-white/70 border border-transparent hover:border-white/60 group"
                                            >
                                                <div className={cn(
                                                    "w-8 h-8 rounded-lg border flex items-center justify-center shrink-0",
                                                    tone
                                                )}>
                                                    <Icon className="w-3.5 h-3.5" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-[12px] font-vazirmatn font-bold text-ink leading-snug line-clamp-2">
                                                        {alertMessage(notif)}
                                                    </p>
                                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                                        <span className={cn(
                                                            "text-[8px] px-1.5 py-0.5 rounded-md font-black",
                                                            badge
                                                        )}>
                                                            {alertLabel(notif.type)}
                                                        </span>
                                                        <span className="text-[9px] text-ink/30 font-vazirmatn tabular-nums">
                                                            {alertMeta(notif)}
                                                        </span>
                                                    </div>
                                                </div>
                                                <ChevronLeft className={cn(
                                                    "w-3.5 h-3.5 text-ink/15 group-hover:text-primary transition-colors shrink-0",
                                                    language === "ar" && "rotate-180"
                                                )} />
                                            </button>
                                        );
                                    })
                                ) : (
                                    <div className="py-14 text-center flex flex-col items-center gap-2.5 text-ink/25">
                                        <Bell className="w-8 h-8" />
                                        <p className="text-[11px] font-black font-vazirmatn">
                                            {t("common.notifications.noNotifications")}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                <motion.div variants={itemVariants} className="space-y-6">
                    <Card className="border border-white/60 bg-white/70 backdrop-blur-xl shadow-sm rounded-[18px] overflow-hidden">
                        <CardHeader className="p-4 px-5 pb-3 border-b border-ink/5">
                            <div className="flex items-center gap-2">
                                <Plus className="w-3.5 h-3.5 text-primary" />
                                <CardTitle className="text-[11px] font-black text-ink uppercase tracking-widest">
                                    {t("dashboard.quickActions")}
                                </CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="p-3 grid grid-cols-2 gap-2">
                            {[
                                { label: t("sales.newSale"), icon: Banknote, href: "/dashboard/sales", tone: "text-emerald-600 bg-emerald-50 border-emerald-100" },
                                { label: t("inventory.addBook"), icon: Plus, href: "/dashboard/inventory/new", tone: "text-primary bg-primary/5 border-primary/10" },
                                { label: t("nav.distribution"), icon: Truck, href: "/dashboard/distribution", tone: "text-sky-600 bg-sky-50 border-sky-100" },
                                { label: t("nav.inventory"), icon: Package, href: "/dashboard/inventory", tone: "text-amber-600 bg-amber-50 border-amber-100" },
                            ].map((action) => (
                                <button
                                    key={action.href}
                                    type="button"
                                    onClick={() => router.push(action.href)}
                                    className="flex flex-col items-start gap-2.5 p-3 rounded-xl border border-ink/5 bg-white/50 hover:bg-white hover:border-ink/10 hover:shadow-sm transition-all text-start"
                                >
                                    <span className={cn("w-8 h-8 rounded-lg border flex items-center justify-center", action.tone)}>
                                        <action.icon className="w-3.5 h-3.5" />
                                    </span>
                                    <span className="text-[11px] font-black font-vazirmatn text-ink leading-tight">
                                        {action.label}
                                    </span>
                                </button>
                            ))}
                        </CardContent>
                    </Card>

                    <Card className="border border-white/60 bg-white/70 backdrop-blur-xl shadow-sm rounded-[18px] overflow-hidden">
                        <CardHeader className="p-4 px-5 pb-3 border-b border-ink/5">
                            <div className="flex items-center gap-2">
                                <Calendar className="w-3.5 h-3.5 text-primary" />
                                <CardTitle className="text-[11px] font-black text-ink uppercase tracking-widest">
                                    {t("dashboard.todaySnapshot")}
                                </CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="p-2">
                            {[
                                {
                                    label: t("dashboard.todayInvoices"),
                                    value: data?.today_invoice_count ?? 0,
                                    href: "/dashboard/sales",
                                    icon: TrendingUp,
                                    tone: "text-emerald-600 bg-emerald-50",
                                },
                                {
                                    label: t("dashboard.openTransfers"),
                                    value: counts.transfer,
                                    href: "/dashboard/distribution",
                                    icon: Truck,
                                    tone: "text-sky-600 bg-sky-50",
                                },
                                {
                                    label: t("dashboard.lowStockBooks"),
                                    value: data?.low_stock_count ?? 0,
                                    href: "/dashboard/inventory",
                                    icon: AlertTriangle,
                                    tone: "text-rose-500 bg-rose-50",
                                },
                                {
                                    label: t("dashboard.pendingChecks"),
                                    value: data?.pending_checks ?? 0,
                                    href: "/dashboard/checks",
                                    icon: CreditCard,
                                    tone: "text-amber-600 bg-amber-50",
                                },
                            ].map((row) => (
                                <button
                                    key={row.href + row.label}
                                    type="button"
                                    onClick={() => router.push(row.href)}
                                    className="w-full flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/70 transition-colors text-start group"
                                >
                                    <div className={cn("w-8 h-8 rounded-lg flex items-center justify-center shrink-0", row.tone)}>
                                        <row.icon className="w-3.5 h-3.5" />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-[11px] font-bold text-ink/50">{row.label}</p>
                                    </div>
                                    <span className="text-[14px] font-black font-vazirmatn tabular-nums text-ink">
                                        {isLoading ? "…" : formatNumber(row.value)}
                                    </span>
                                    <ChevronLeft className={cn(
                                        "w-3.5 h-3.5 text-ink/15 group-hover:text-primary transition-colors",
                                        language === "ar" && "rotate-180"
                                    )} />
                                </button>
                            ))}
                        </CardContent>
                    </Card>
                </motion.div>
            </div>
        </motion.div>
    );
}
