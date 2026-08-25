"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
    AlertTriangle, Bell, Banknote, CheckCircle2, ChevronLeft,
    CreditCard, Package, RefreshCw, Search, Truck, X,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useRouter } from "@/i18n/routing";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { NOTIFICATION_QUERY_KEYS } from "@/hooks/useNotificationInbox";

type TransferAlertType =
    | "transfer_pending"
    | "transfer_incoming"
    | "transfer_shipped"
    | "transfer_sending"
    | "transfer_received";
type AlertType = "low_stock" | "check_due" | "credit_due" | TransferAlertType;
type AlertFilter = "all" | "low_stock" | "check_due" | "credit_due" | "transfer";

interface Notification {
    type: AlertType;
    message: string;
    data: Record<string, unknown>;
}

function isTransferAlert(type: string): type is TransferAlertType {
    return type.startsWith("transfer_");
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

function alertKey(n: Notification, i: number): string {
    const d = n.data || {};
    return `${n.type}-${i}-${String(d.transfer_id ?? d.inventory_id ?? d.check_id ?? d.invoice_id ?? i)}`;
}

export default function NotificationsPage() {
    const { t, formatNumber, language } = useTranslation();
    const router = useRouter();
    const [filter, setFilter] = useState<AlertFilter>("all");
    const [search, setSearch] = useState("");

    const { data: notifications = [], isLoading, isFetching, refetch } = useQuery({
        queryKey: NOTIFICATION_QUERY_KEYS.reports,
        queryFn: async () => {
            const data = await apiRequest("/reports/notifications");
            return [
                ...(data.transfers ?? []),
                ...(data.low_stock ?? []),
                ...(data.due_checks ?? []),
                ...(data.due_credits ?? []),
            ] as Notification[];
        },
        staleTime: 0,
        refetchInterval: 15_000,
        refetchOnWindowFocus: true,
        refetchOnMount: "always",
    });
    usePageReady(!isLoading);
    const isRefreshing = isFetching && !isLoading;

    const alertLabel = (type: AlertType) => {
        if (type.startsWith("transfer_") && type !== "transfer_received") return t("common.notifications.transferSending");
        if (type === "transfer_received") return t("common.notifications.transferReceived");
        if (type === "low_stock") return t("common.notifications.lowStock");
        if (type === "check_due") return t("common.notifications.checkDue");
        return t("common.notifications.creditDue");
    };

    const alertMessage = (n: Notification) => {
        const d = n.data || {};
        if (n.type.startsWith("transfer_") && n.type !== "transfer_received") {
            return t("common.notifications.transferSendingMsg", {
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

    const counts = useMemo(() => ({
        all: notifications.length,
        transfer: notifications.filter((n) => isTransferAlert(n.type)).length,
        low_stock: notifications.filter((n) => n.type === "low_stock").length,
        check_due: notifications.filter((n) => n.type === "check_due").length,
        credit_due: notifications.filter((n) => n.type === "credit_due").length,
    }), [notifications]);

    const filtered = useMemo(() => {
        const byType = filter === "all"
            ? notifications
            : filter === "transfer"
                ? notifications.filter((n) => isTransferAlert(n.type))
                : notifications.filter((n) => n.type === filter);

        const q = search.trim().toLowerCase();
        if (!q) return byType;
        return byType.filter((n) => {
            const d = n.data || {};
            const haystack = [
                n.message,
                n.type,
                String(d.book_title ?? ""),
                String(d.branch_name ?? ""),
                String(d.from_branch ?? ""),
                String(d.to_branch ?? ""),
                String(d.check_number ?? ""),
                String(d.payer_name ?? ""),
                String(d.invoice_number ?? ""),
                String(d.customer_name ?? ""),
            ].join(" ").toLowerCase();
            return haystack.includes(q);
        });
    }, [notifications, filter, search]);

    const filters: { key: AlertFilter; label: string; count: number }[] = [
        { key: "all", label: t("common.notifications.filterAll"), count: counts.all },
        { key: "transfer", label: t("common.notifications.transfer"), count: counts.transfer },
        { key: "low_stock", label: t("common.notifications.lowStock"), count: counts.low_stock },
        { key: "check_due", label: t("common.notifications.checkDue"), count: counts.check_due },
        { key: "credit_due", label: t("common.notifications.creditDue"), count: counts.credit_due },
    ];

    return (
        <div className="space-y-5 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-ink/5">
                <div>
                    <div className="flex items-center gap-2 mb-1">
                        <Bell className="w-4 h-4 text-primary" />
                        <h1 className="text-xl font-black font-vazirmatn text-ink tracking-tight">
                            {t("common.notifications.title")}
                        </h1>
                        {counts.all > 0 && (
                            <Badge className="text-[9px]">{formatNumber(counts.all)}</Badge>
                        )}
                    </div>
                    <p className="text-[11px] text-ink/40 font-vazirmatn font-medium">
                        {counts.all > 0
                            ? t("common.notifications.count", { count: formatNumber(counts.all) })
                            : t("common.notifications.pageSubtitle")}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => refetch()}
                    disabled={isRefreshing || isLoading}
                    className="h-9 gap-2"
                >
                    <RefreshCw className={cn("w-3.5 h-3.5", (isRefreshing || isLoading) && "animate-spin")} />
                    {t("common.refresh")}
                </Button>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">
                {filters.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => setFilter(f.key)}
                        className={cn(
                            "rounded-xl border px-3 py-3 text-start transition-all",
                            filter === f.key
                                ? "bg-white border-primary/20 shadow-sm"
                                : "bg-white/50 border-ink/[0.05] hover:bg-white hover:border-ink/10"
                        )}
                    >
                        <p className={cn(
                            "text-[10px] font-black font-vazirmatn",
                            filter === f.key ? "text-primary" : "text-ink/40"
                        )}>
                            {f.label}
                        </p>
                        <p className="text-lg font-black font-vazirmatn text-ink tabular-nums mt-1 tracking-tight">
                            {isLoading ? "…" : formatNumber(f.count)}
                        </p>
                    </button>
                ))}
            </div>

            <Card className="border border-white/60 bg-white/70 backdrop-blur-xl shadow-sm rounded-2xl overflow-hidden">
                <CardHeader className="p-4 px-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-ink/5">
                    <CardTitle className="text-sm font-black font-vazirmatn text-ink">
                        {t("common.notifications.listTitle")}
                    </CardTitle>
                    <div className="relative w-full sm:w-64">
                        <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/30" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t("common.notifications.searchPlaceholder")}
                            className="w-full h-9 ps-9 pe-8 rounded-lg border border-ink/[0.06] bg-white/70 text-[11px] font-vazirmatn text-ink placeholder:text-ink/30 focus:outline-none focus:border-primary/30"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch("")}
                                className="absolute end-2.5 top-1/2 -translate-y-1/2 text-ink/30 hover:text-ink/60"
                                aria-label={t("common.clear")}
                            >
                                <X className="w-3.5 h-3.5" />
                            </button>
                        )}
                    </div>
                </CardHeader>

                <CardContent className="p-2 px-3 pb-3">
                    <div className="space-y-0.5">
                        {isLoading ? (
                            Array.from({ length: 6 }).map((_, i) => (
                                <div key={i} className="h-16 bg-parchment/30 rounded-xl animate-pulse m-1" />
                            ))
                        ) : filtered.length > 0 ? (
                            filtered.map((notif, i) => {
                                const isTransfer = isTransferAlert(notif.type);
                                const isLow = notif.type === "low_stock";
                                const isCheck = notif.type === "check_due";
                                const Icon = isTransfer
                                    ? Truck
                                    : isLow
                                        ? Package
                                        : isCheck
                                            ? Banknote
                                            : CreditCard;
                                const tone = isTransfer
                                    ? notif.type === "transfer_received"
                                        ? "bg-emerald-50 border-emerald-100 text-emerald-600"
                                        : "bg-sky-50 border-sky-100 text-sky-600"
                                    : isLow
                                        ? "bg-rose-50 border-rose-100 text-rose-500"
                                        : isCheck
                                            ? "bg-amber-50 border-amber-100 text-amber-600"
                                            : "bg-sky-50 border-sky-100 text-sky-600";
                                const badge = isTransfer
                                    ? notif.type === "transfer_received"
                                        ? "bg-emerald-100 text-emerald-700"
                                        : "bg-sky-100 text-sky-700"
                                    : isLow
                                        ? "bg-rose-100 text-rose-600"
                                        : isCheck
                                            ? "bg-amber-100 text-amber-700"
                                            : "bg-sky-100 text-sky-700";

                                return (
                                    <button
                                        key={alertKey(notif, i)}
                                        type="button"
                                        onClick={() => router.push(alertHref(notif))}
                                        className="w-full flex items-center gap-3 p-3 rounded-xl transition-all text-start hover:bg-white/80 border border-transparent hover:border-white/60 group"
                                    >
                                        <div className={cn(
                                            "w-9 h-9 rounded-lg border flex items-center justify-center shrink-0",
                                            tone
                                        )}>
                                            <Icon className="w-4 h-4" />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[12px] font-vazirmatn font-bold text-ink leading-snug">
                                                {alertMessage(notif)}
                                            </p>
                                            <div className="flex items-center gap-2 mt-1.5 flex-wrap">
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
                                            "w-4 h-4 text-ink/15 group-hover:text-primary transition-colors shrink-0",
                                            language === "ar" && "rotate-180"
                                        )} />
                                    </button>
                                );
                            })
                        ) : (
                            <div className="py-16 text-center flex flex-col items-center gap-2.5 text-ink/25">
                                {search || filter !== "all" ? (
                                    <AlertTriangle className="w-8 h-8" />
                                ) : (
                                    <CheckCircle2 className="w-8 h-8 text-emerald-400/70" />
                                )}
                                <p className="text-[11px] font-black font-vazirmatn">
                                    {search || filter !== "all"
                                        ? t("common.noResults")
                                        : t("common.notifications.noNotifications")}
                                </p>
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
