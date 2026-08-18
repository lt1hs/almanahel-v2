"use client";

import React, { useState, useEffect, useCallback, useRef, useMemo } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Truck, MapPin, RefreshCw, Plus, Warehouse, AlertTriangle, Package } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Card, CardContent } from "@/components/ui/Card";
import { LowStockAlerts } from "@/components/distribution/LowStockAlerts";
import { TransferWizard } from "@/components/distribution/TransferWizard";
import { TransferTimeline } from "@/components/distribution/TransferTimeline";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";

interface BranchRow {
    id: number;
    name: string;
    city: string;
    country: string;
    type: string;
    status?: string;
    total_stock?: number;
    total_titles?: number;
}

interface AlertItem {
    id: string;
    title: string;
    branch: string;
    branchId?: number;
    bookId?: number;
    currentStock: number;
    threshold: number;
    priority: "high" | "medium" | "low";
}

interface TransferNotif {
    type: string;
    message: string;
    data?: {
        transfer_id?: number;
        book_title?: string;
        quantity?: number;
        from_branch?: string;
        to_branch?: string;
        status?: string;
    };
}

function dedupeBranches(list: BranchRow[]): BranchRow[] {
    const seen = new Set<string>();
    return list.filter((b) => {
        const key = (b.name || "").trim().toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

export default function DistributionPage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;
    const { user } = useAuth();
    const wizardRef = useRef<HTMLDivElement>(null);

    const [branches, setBranches] = useState<BranchRow[]>([]);
    const [transfers, setTransfers] = useState<any[]>([]);
    const [alerts, setAlerts] = useState<AlertItem[]>([]);
    const [transferNotifs, setTransferNotifs] = useState<TransferNotif[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [prefillFrom, setPrefillFrom] = useState("");
    const [prefillTo, setPrefillTo] = useState("");
    const [showSuccess, setShowSuccess] = useState(false);
    const [transferPage, setTransferPage] = useState(1);
    const [transferHasMore, setTransferHasMore] = useState(false);
    const [loadingMoreTransfers, setLoadingMoreTransfers] = useState(false);
    const [updatingTransferId, setUpdatingTransferId] = useState<number | null>(null);

    const fetchData = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const [bData, tData, aData] = await Promise.all([
                apiRequest("/branches"),
                apiRequest("/transfers?per_page=30&page=1"),
                apiRequest("/reports/notifications"),
            ]);

            const raw = Array.isArray(bData) ? bData : [];
            const branchList = dedupeBranches(
                raw.map((b: any) => ({
                    id: Number(b.id),
                    name: String(b.name || ""),
                    city: String(b.city || b.name || ""),
                    country: String(b.country || ""),
                    type: String(b.type || "store"),
                    status: b.status,
                    total_stock: Number(b.total_stock) || 0,
                    total_titles: Number(b.total_titles) || 0,
                }))
            );
            setBranches(branchList);

            const transferList = tData?.data ?? (Array.isArray(tData) ? tData : []);
            setTransfers(transferList);
            setTransferPage(1);
            setTransferHasMore(Number(tData?.current_page ?? 1) < Number(tData?.last_page ?? 1));

            setTransferNotifs(Array.isArray(aData?.transfers) ? aData.transfers : []);

            const lowStock = Array.isArray(aData?.low_stock) ? aData.low_stock : [];
            setAlerts(
                lowStock.map((item: any, i: number) => {
                    const d = item.data || {};
                    const qty = Number(d.quantity ?? 0);
                    const threshold = Number(d.threshold ?? 5);
                    return {
                        id: String(d.inventory_id ?? i),
                        title: String(d.book_title || item.message?.split('"')[1] || t("distribution.bookFallback")),
                        branch: String(d.branch_name || item.message?.split('"')[3] || t("distribution.branchFallback")),
                        branchId: d.branch_id ? Number(d.branch_id) : undefined,
                        bookId: d.book_id ? Number(d.book_id) : undefined,
                        currentStock: qty,
                        threshold,
                        priority: (qty <= 1 ? "high" : "medium") as "high" | "medium",
                    };
                })
            );
        } catch (error) {
            console.error("Failed to fetch distribution data:", error);
            notifyRef.current.error("distribution.loadError");
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [t]);

    const loadMoreTransfers = useCallback(async () => {
        if (loadingMoreTransfers || !transferHasMore) return;
        setLoadingMoreTransfers(true);
        try {
            const next = transferPage + 1;
            const tData = await apiRequest(`/transfers?per_page=30&page=${next}`);
            const list = tData?.data ?? [];
            setTransfers((prev) => [...prev, ...(Array.isArray(list) ? list : [])]);
            setTransferPage(next);
            setTransferHasMore(Number(tData?.current_page ?? next) < Number(tData?.last_page ?? next));
        } catch {
            notifyRef.current.error("distribution.loadError");
        } finally {
            setLoadingMoreTransfers(false);
        }
    }, [loadingMoreTransfers, transferHasMore, transferPage]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const warehouseId = useMemo(
        () => branches.find((b) => b.type === "warehouse")?.id,
        [branches]
    );

    const isHqUser = user?.role === "admin" || user?.role === "super_admin";

    const scopedBranches = useMemo(() => {
        if (!user || isHqUser || user.role === "warehouse_staff") return branches;
        const ids = new Set<number>();
        if (user.branch_id) ids.add(Number(user.branch_id));
        (user.iraq_only_visible_branches || []).forEach((id) => ids.add(Number(id)));
        // Hubs POS may ship to (display + destination), but not control as source
        branches.forEach((b) => {
            if (b.type === "warehouse") ids.add(Number(b.id));
            const city = (b.city || "").trim();
            const name = (b.name || "").trim();
            if (b.type === "store" && (city === "قم" || name.includes("قم"))) {
                ids.add(Number(b.id));
            }
        });
        if (!ids.size) return [];
        return branches.filter((b) => ids.has(Number(b.id)));
    }, [branches, user, isHqUser]);

    const scopedAlerts = useMemo(() => {
        if (!user || isHqUser || user.role === "warehouse_staff") return alerts;
        const ids = new Set(scopedBranches.map((b) => Number(b.id)));
        return alerts.filter((a) => !a.branchId || ids.has(Number(a.branchId)));
    }, [alerts, scopedBranches, user, isHqUser]);

    const stats = useMemo(() => ({
        inTransit: transfers.filter((tr) => tr.status === "pending" || tr.status === "shipped").length,
        received: transfers.filter((tr) => tr.status === "received").length,
        totalStock: scopedBranches.reduce((a, b) => a + (Number(b.total_stock) || 0), 0),
        alerts: scopedAlerts.length,
    }), [transfers, scopedBranches, scopedAlerts]);

    const scrollToWizard = () => {
        wizardRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    };

    useEffect(() => {
        if (typeof window === "undefined") return;
        if (!new URLSearchParams(window.location.search).get("book")) return;
        const timer = window.setTimeout(() => scrollToWizard(), 300);
        return () => window.clearTimeout(timer);
    }, []);

    const handleAlertClick = (alert: AlertItem) => {
        // Admin/warehouse: restock from warehouse → branch. POS: return from own POS → hub.
        if (isHqUser || user?.role === "warehouse_staff") {
            const from = warehouseId ? String(warehouseId) : "";
            const to = alert.branchId ? String(alert.branchId) : "";
            if (from) setPrefillFrom(from);
            if (to && to !== from) setPrefillTo(to);
        } else {
            if (user?.branch_id) setPrefillFrom(String(user.branch_id));
            const hub = warehouseId
                ? String(warehouseId)
                : String(branches.find((b) => (b.city || "").trim() === "قم" || (b.name || "").includes("قم"))?.id || "");
            if (hub) setPrefillTo(hub);
        }
        scrollToWizard();
    };

    const handleBranchClick = (branch: BranchRow) => {
        if (isHqUser || user?.role === "warehouse_staff") {
            if (branch.type === "warehouse") {
                setPrefillFrom(String(branch.id));
                setPrefillTo("");
            } else {
                setPrefillFrom(warehouseId ? String(warehouseId) : "");
                setPrefillTo(String(branch.id));
            }
        } else {
            // POS: always ship from own store; warehouse/Qom only as destination
            if (user?.branch_id) setPrefillFrom(String(user.branch_id));
            if (branch.type === "warehouse" || (branch.city || "").trim() === "قم" || (branch.name || "").includes("قم")) {
                setPrefillTo(String(branch.id));
            } else {
                setPrefillTo(warehouseId ? String(warehouseId) : "");
            }
        }
        scrollToWizard();
    };

    const handleTransferSuccess = () => {
        setShowSuccess(true);
        setPrefillFrom("");
        setPrefillTo("");
        fetchData(true);
        setTimeout(() => setShowSuccess(false), 3500);
    };

    const handleUpdateStatus = async (id: number, status: "shipped" | "received" | "cancelled") => {
        setUpdatingTransferId(id);
        try {
            await apiRequest(`/transfers/${id}/status`, {
                method: "PUT",
                body: JSON.stringify({ status }),
            });
            notify.success(
                status === "shipped"
                    ? "distribution.timeline.shippedOk"
                    : status === "received"
                        ? "distribution.timeline.receivedOk"
                        : "distribution.timeline.cancelledOk"
            );
            await fetchData(true);
        } catch (err) {
            notify.rawError((err as Error).message || t("distribution.timeline.statusUpdateFailed"));
        } finally {
            setUpdatingTransferId(null);
        }
    };

    const activeBranches = useMemo(
        () => scopedBranches.filter((b) => b.status === "active" || !b.status),
        [scopedBranches]
    );

    const actionableNotifs = useMemo(
        () => transferNotifs.filter((n) =>
            n.type === "transfer_sending" || n.type === "transfer_shipped" || n.type === "transfer_pending" || n.type === "transfer_incoming"
        ),
        [transferNotifs]
    );

    const kpis = [
        {
            label: t("distribution.sending"),
            value: stats.inTransit,
            icon: Truck,
            color: "text-primary",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
        },
        {
            label: t("distribution.receiving"),
            value: stats.received,
            icon: Package,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/40",
        },
        {
            label: t("distribution.networkStock"),
            value: stats.totalStock,
            icon: Warehouse,
            color: "text-sky-600",
            border: "border-sky-100",
            bg: "bg-sky-50/40",
        },
        {
            label: t("distribution.stockAlert"),
            value: stats.alerts,
            icon: AlertTriangle,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/40",
        },
    ];

    return (
        <div className="relative min-h-[70vh] space-y-4 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink flex items-center gap-2.5">
                        <span className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center">
                            <Truck className="w-4 h-4 text-primary" />
                        </span>
                        {t("distribution.title")}
                    </h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-1">
                        {t("distribution.subtitle")}
                    </p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
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
                    <Button
                        variant="primary"
                        size="sm"
                        className="h-9 px-4 rounded-xl text-[11px] font-black"
                        onClick={scrollToWizard}
                    >
                        <Plus className="w-3.5 h-3.5 ms-1.5" />
                        {t("distribution.newTransfer")}
                    </Button>
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
                            <p className={cn("text-xl font-black font-vazirmatn tabular-nums leading-none", kpi.color)}>
                                {isLoading && !transfers.length ? "…" : formatNumber(kpi.value)}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {actionableNotifs.length > 0 && (
                <div className="space-y-2">
                    {actionableNotifs.slice(0, 4).map((n) => {
                        const Icon = Truck;
                        return (
                            <div
                                key={`${n.type}-${n.data?.transfer_id ?? n.message}`}
                                className="rounded-2xl border px-4 py-3 flex items-start gap-3 bg-sky-50/80 border-sky-100"
                            >
                                <div className="w-8 h-8 rounded-xl border flex items-center justify-center shrink-0 bg-sky-100 border-sky-200 text-sky-700">
                                    <Icon className="w-4 h-4" />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-[11px] font-black font-vazirmatn text-ink">
                                        {t("common.notifications.transferSending")}
                                    </p>
                                    <p className="text-[10px] text-ink/55 mt-0.5 font-vazirmatn leading-relaxed">
                                        {n.message}
                                    </p>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {!isLoading && activeBranches.length > 0 && (
                <div className="rounded-2xl border border-white/70 bg-white/70 p-3.5">
                    <p className="text-[10px] font-black text-ink/30 mb-2.5 flex items-center gap-1.5">
                        <MapPin className="w-3 h-3" /> {t("distribution.activeBranches")}
                    </p>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                        {activeBranches.map((b) => (
                            <button
                                key={b.id}
                                type="button"
                                onClick={() => handleBranchClick(b)}
                                className="text-start rounded-xl border border-ink/8 bg-parchment/30 hover:bg-white hover:border-primary/20 px-3 py-2.5 transition-all"
                            >
                                <p className="text-[11px] font-black font-vazirmatn text-ink truncate">{b.name}</p>
                                <p className="text-[9px] text-ink/35 mt-0.5 truncate">
                                    {b.city}
                                    {b.type === "warehouse" ? ` · ${t("inventory.form.branchStock.warehouse")}` : ""}
                                </p>
                                <p className="text-[13px] font-black font-vazirmatn tabular-nums text-ink/80 mt-1.5 leading-tight flex items-baseline gap-2 flex-wrap">
                                    <span>
                                        {formatNumber(b.total_stock || 0)}
                                        <span className="text-[9px] text-ink/30 ms-1 font-bold">{t("distribution.volumeUnit")}</span>
                                    </span>
                                    <span className="text-ink/20 text-[10px] font-bold">·</span>
                                    <span className="text-[11px] text-ink/45">
                                        {formatNumber(b.total_titles || 0)}
                                        <span className="text-[8px] text-ink/30 ms-1 font-bold">{t("distribution.titleUnit")}</span>
                                    </span>
                                </p>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <AnimatePresence>
                {showSuccess && (
                    <motion.div
                        initial={{ opacity: 0, y: -16 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -8 }}
                        className="fixed top-6 inset-x-4 sm:inset-x-auto sm:end-6 sm:w-96 z-50 px-4 py-3.5 rounded-2xl bg-emerald-600 text-white shadow-2xl shadow-emerald-600/30 flex items-center gap-3"
                    >
                        <div className="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                            <Truck className="w-4 h-4" />
                        </div>
                        <div>
                            <p className="text-[12px] font-black font-vazirmatn">{t("distribution.transferSuccessTitle")}</p>
                            <p className="text-[10px] text-white/70 mt-0.5">{t("distribution.transferSuccessDetail")}</p>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <div ref={wizardRef}>
                <TransferWizard
                    branches={isHqUser || user?.role === "warehouse_staff" ? branches : scopedBranches}
                    userName={user?.name}
                    userRole={user?.role}
                    userBranchId={user?.branch_id ?? null}
                    onSuccess={handleTransferSuccess}
                    prefillFrom={prefillFrom}
                    prefillTo={prefillTo}
                    recentTransfers={transfers}
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-12 xl:items-start">
                <div className="xl:col-span-8">
                    <TransferTimeline
                        transfers={transfers}
                        branches={branches}
                        isLoading={isLoading && !transfers.length}
                        onNewTransfer={scrollToWizard}
                        hasMore={transferHasMore}
                        loadingMore={loadingMoreTransfers}
                        onLoadMore={loadMoreTransfers}
                        user={user}
                        onUpdateStatus={handleUpdateStatus}
                        updatingId={updatingTransferId}
                    />
                </div>
                <div className="xl:col-span-4">
                    <LowStockAlerts
                        alerts={scopedAlerts}
                        isLoading={isLoading && !scopedAlerts.length}
                        onSelect={handleAlertClick}
                    />
                </div>
            </div>
        </div>
    );
}
