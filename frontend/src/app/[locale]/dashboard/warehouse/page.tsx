"use client";

import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import {
    Warehouse, ArrowDown, ArrowUp, Package, User, BookOpen, History,
    Search, RefreshCw, AlertTriangle, Edit3, Building2,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { useRouter } from "@/i18n/routing";

const REASON_KEYS: Record<string, string> = {
    received_from_supplier: "warehouse.logTypes.received",
    transferred_to_branch: "warehouse.logTypes.transferred",
    returned_from_branch: "warehouse.logTypes.returned",
    adjustment: "warehouse.logTypes.adjustment",
    other: "warehouse.logTypes.other",
};

const DEFAULT_LOW_STOCK = 5;
const INV_PAGE_SIZE = 40;

interface BranchRow {
    id: number;
    name: string;
    type?: string;
    status?: string;
}

interface InventoryItem {
    id: number;
    quantity: number;
    type?: string;
    book?: { id: number; title?: string; author?: string; isbn?: string } | null;
    supplier?: { name?: string } | null;
}

interface WarehouseLogItem {
    id: number;
    direction: "in" | "out";
    quantity: number;
    handler_name?: string;
    reason?: string;
    log_date?: string;
    related_transfer_id?: number | null;
    book?: { title?: string } | null;
}

function dedupeBranches(list: BranchRow[]): BranchRow[] {
    const seen = new Set<string>();
    return list
        .filter((b) => {
            const key = (b.name || "").trim().toLowerCase();
            if (!key || seen.has(key)) return false;
            seen.add(key);
            return true;
        })
        .sort((a, b) => {
            if (a.type === "warehouse" && b.type !== "warehouse") return -1;
            if (b.type === "warehouse" && a.type !== "warehouse") return 1;
            return (a.name || "").localeCompare(b.name || "", "fa");
        });
}

function ListSkeleton({ rows = 5 }: { rows?: number }) {
    return (
        <div className="divide-y divide-ink/5">
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="flex items-center gap-4 px-5 py-4 animate-pulse">
                    <div className="w-10 h-10 rounded-xl bg-parchment/40" />
                    <div className="flex-1 space-y-2">
                        <div className="h-3.5 w-40 bg-parchment/50 rounded" />
                        <div className="h-2 w-28 bg-parchment/30 rounded" />
                    </div>
                    <div className="h-5 w-8 bg-parchment/40 rounded" />
                </div>
            ))}
        </div>
    );
}

export default function WarehousePage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;
    const { user } = useAuth();
    const router = useRouter();
    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const [allBranches, setAllBranches] = useState<BranchRow[]>([]);
    const [activeBranch, setActiveBranch] = useState<BranchRow | null>(null);
    const [lowStockThreshold, setLowStockThreshold] = useState(DEFAULT_LOW_STOCK);
    const [inventory, setInventory] = useState<InventoryItem[]>([]);
    const [logs, setLogs] = useState<WarehouseLogItem[]>([]);
    const [stats, setStats] = useState({ total_in: 0, total_out: 0, current_stock: 0 });

    const [isLoading, setIsLoading] = useState(true);
    const [isLoadingLogs, setIsLoadingLogs] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [logsLoaded, setLogsLoaded] = useState(false);
    const [logPage, setLogPage] = useState(1);
    const [logHasMore, setLogHasMore] = useState(false);
    const [loadingMoreLogs, setLoadingMoreLogs] = useState(false);

    const [activeTab, setActiveTab] = useState<"inventory" | "log">("inventory");
    const [inventorySearch, setInventorySearch] = useState("");
    const [stockFilter, setStockFilter] = useState<"all" | "low" | "out">("all");
    const [invPage, setInvPage] = useState(1);
    const [logSearch, setLogSearch] = useState("");
    const [logDirectionFilter, setLogDirectionFilter] = useState<"all" | "in" | "out">("all");

    const branchOptions = useMemo(() => dedupeBranches(allBranches), [allBranches]);

    const resolveDefaultBranch = useCallback(async () => {
        const [branches, settings] = await Promise.all([
            apiRequest("/branches"),
            apiRequest("/settings").catch(() => ({ low_stock_threshold: DEFAULT_LOW_STOCK })),
        ]);
        const list = Array.isArray(branches) ? branches : [];
        setAllBranches(list);
        setLowStockThreshold(Number(settings.low_stock_threshold ?? DEFAULT_LOW_STOCK));

        const unique = dedupeBranches(list);
        if (user?.role === "warehouse_staff" && user.branch?.id) {
            return unique.find((b) => b.id === user.branch!.id)
                ?? unique.find((b) => b.type === "warehouse")
                ?? null;
        }
        if (user?.branch?.id && !isAdmin) {
            return unique.find((b) => b.id === user.branch!.id) ?? null;
        }
        return unique.find((b) => b.type === "warehouse") ?? unique[0] ?? null;
    }, [user, isAdmin]);

    const fetchCoreData = useCallback(async (branchId: number) => {
        const [inv, statData] = await Promise.all([
            apiRequest(`/warehouse/${branchId}/inventory`),
            apiRequest(`/warehouse/${branchId}/stats`),
        ]);
        setInventory(Array.isArray(inv) ? inv : []);
        setStats({
            total_in: Number(statData?.total_in || 0),
            total_out: Number(statData?.total_out || 0),
            current_stock: Number(statData?.current_stock || 0),
        });
    }, []);

    const fetchLogs = useCallback(async (
        branchId: number,
        pageNum = 1,
        append = false,
        direction: "all" | "in" | "out" = "all",
    ) => {
        if (append) setLoadingMoreLogs(true);
        else setIsLoadingLogs(true);
        try {
            const params = new URLSearchParams({
                branch_id: String(branchId),
                page: String(pageNum),
                per_page: "50",
            });
            if (direction !== "all") params.set("direction", direction);
            const logData = await apiRequest(`/warehouse/logs?${params.toString()}`);
            const list: WarehouseLogItem[] = logData?.data ?? (Array.isArray(logData) ? logData : []);
            setLogs((prev) => (append ? [...prev, ...list] : list));
            setLogPage(pageNum);
            const lastPage = Number(logData?.last_page ?? 1);
            setLogHasMore(pageNum < lastPage);
            setLogsLoaded(true);
        } catch (error) {
            console.error("Warehouse logs fetch failed:", error);
            notifyRef.current.error("warehouse.loadError");
            if (!append) setLogs([]);
        } finally {
            setIsLoadingLogs(false);
            setLoadingMoreLogs(false);
        }
    }, []);

    const loadPage = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        setLoadError(null);
        try {
            const branch = activeBranch ?? await resolveDefaultBranch();
            if (!branch) {
                setLoadError(t("warehouse.errors.noWarehouse"));
                return;
            }
            setActiveBranch(branch);
            await fetchCoreData(branch.id);
            if (activeTab === "log" || logsLoaded) {
                await fetchLogs(branch.id, 1, false, logDirectionFilter);
            }
        } catch (error) {
            console.error("Warehouse fetch failed:", error);
            setLoadError(t("warehouse.loadError"));
            notifyRef.current.error("warehouse.loadError");
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [activeBranch, resolveDefaultBranch, fetchCoreData, fetchLogs, activeTab, logsLoaded, logDirectionFilter, t]);

    const switchBranch = async (branchId: number) => {
        const branch = branchOptions.find((b) => b.id === branchId);
        if (!branch) return;
        setActiveBranch(branch);
        setLogsLoaded(false);
        setLogs([]);
        setInvPage(1);
        setIsRefreshing(true);
        try {
            await fetchCoreData(branch.id);
            if (activeTab === "log") {
                await fetchLogs(branch.id, 1, false, logDirectionFilter);
            }
        } catch {
            notifyRef.current.error("warehouse.loadError");
        } finally {
            setIsRefreshing(false);
            setIsLoading(false);
        }
    };

    useEffect(() => {
        loadPage();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (activeTab === "log" && activeBranch && !logsLoaded && !isLoadingLogs) {
            fetchLogs(activeBranch.id, 1, false, logDirectionFilter);
        }
    }, [activeTab, activeBranch, logsLoaded, isLoadingLogs, fetchLogs, logDirectionFilter]);

    // Re-fetch logs when direction filter changes (server-side) after first load
    useEffect(() => {
        if (activeTab !== "log" || !activeBranch || !logsLoaded) return;
        fetchLogs(activeBranch.id, 1, false, logDirectionFilter);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [logDirectionFilter]);

    useEffect(() => {
        setInvPage(1);
    }, [inventorySearch, stockFilter, activeBranch?.id]);

    const lowStockCount = useMemo(
        () => inventory.filter((i) => i.quantity > 0 && i.quantity <= lowStockThreshold).length,
        [inventory, lowStockThreshold]
    );
    const outStockCount = useMemo(
        () => inventory.filter((i) => i.quantity <= 0).length,
        [inventory]
    );

    const filteredInventory = useMemo(() => {
        let result = inventory;
        if (stockFilter === "low") {
            result = result.filter((i) => i.quantity > 0 && i.quantity <= lowStockThreshold);
        } else if (stockFilter === "out") {
            result = result.filter((i) => i.quantity <= 0);
        }
        const q = inventorySearch.trim().toLowerCase();
        if (!q) return result;
        return result.filter((item) =>
            item.book?.title?.toLowerCase().includes(q) ||
            item.book?.author?.toLowerCase().includes(q) ||
            item.book?.isbn?.toLowerCase().includes(q) ||
            item.supplier?.name?.toLowerCase().includes(q)
        );
    }, [inventory, inventorySearch, stockFilter, lowStockThreshold]);

    const invTotalPages = Math.max(1, Math.ceil(filteredInventory.length / INV_PAGE_SIZE));
    const safeInvPage = Math.min(invPage, invTotalPages);
    const pageInventory = filteredInventory.slice(
        (safeInvPage - 1) * INV_PAGE_SIZE,
        safeInvPage * INV_PAGE_SIZE
    );

    const filteredLogs = useMemo(() => {
        const q = logSearch.trim().toLowerCase();
        if (!q) return logs;
        return logs.filter((log) =>
            log.book?.title?.toLowerCase().includes(q) ||
            log.handler_name?.toLowerCase().includes(q) ||
            (REASON_KEYS[log.reason || ""] ? t(REASON_KEYS[log.reason || ""]) : log.reason || "")
                .toLowerCase()
                .includes(q)
        );
    }, [logs, logSearch, t]);

    const openLogPage = (direction: "in" | "out") => {
        const qs = new URLSearchParams({ direction });
        if (activeBranch?.id) qs.set("branch", String(activeBranch.id));
        router.push(`/dashboard/warehouse/log?${qs.toString()}`);
    };

    const openEditLog = (log: WarehouseLogItem) => {
        if (log.related_transfer_id) {
            notify.error("warehouse.errors.notEditable");
            return;
        }
        router.push(`/dashboard/warehouse/log?id=${log.id}`);
    };

    const kpis = [
        {
            label: t("warehouse.stats.totalIn"),
            value: stats.total_in,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/40",
            icon: ArrowDown,
        },
        {
            label: t("warehouse.stats.totalOut"),
            value: stats.total_out,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/40",
            icon: ArrowUp,
        },
        {
            label: t("warehouse.stats.physicalStock"),
            value: stats.current_stock,
            color: "text-primary",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
            icon: Package,
        },
        {
            label: t("warehouse.stats.lowStock"),
            value: lowStockCount,
            color: "text-amber-600",
            border: "border-amber-100",
            bg: "bg-amber-50/40",
            icon: AlertTriangle,
        },
    ];

    if (isLoading && !activeBranch) {
        return (
            <div className="space-y-4 pb-8">
                <div className="h-16 bg-parchment/20 rounded-xl animate-pulse" />
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="h-20 bg-parchment/20 rounded-xl animate-pulse" />
                    ))}
                </div>
                <ListSkeleton rows={6} />
            </div>
        );
    }

    if (loadError && !activeBranch) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-4">
                <AlertTriangle className="w-10 h-10 text-rose-400" />
                <p className="text-ink/50 font-vazirmatn text-sm">{loadError}</p>
                <Button onClick={() => loadPage()}>{t("common.refresh")}</Button>
            </div>
        );
    }

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-white border border-white shadow-sm flex items-center justify-center">
                        <Warehouse className="w-5 h-5 text-primary" />
                    </div>
                    <div>
                        <h1 className="text-xl font-black font-vazirmatn text-ink">{t("warehouse.title")}</h1>
                        <p className="text-[10px] text-ink/35 font-bold mt-0.5">
                            {activeBranch?.name || t("nav.warehouse")} · {t("warehouse.subtitle")}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2 items-center">
                    {isAdmin && branchOptions.length > 0 && (
                        <FilterSelect
                            value={String(activeBranch?.id ?? "")}
                            onChange={(v) => switchBranch(parseInt(v, 10))}
                            options={branchOptions.map((b) => ({ value: String(b.id), label: b.name }))}
                            icon={<Building2 className="w-3.5 h-3.5" />}
                        />
                    )}
                    <button
                        type="button"
                        title={t("common.refresh")}
                        aria-label={t("common.refresh")}
                        disabled={isRefreshing}
                        onClick={() => loadPage(true)}
                        className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", isRefreshing && "animate-spin")} />
                    </button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 px-3 rounded-xl text-[11px] border-emerald-200 bg-emerald-50/50 text-emerald-700"
                        onClick={() => openLogPage("in")}
                    >
                        <ArrowDown className="w-3.5 h-3.5 ms-1" /> {t("warehouse.logIn")}
                    </Button>
                    <Button
                        size="sm"
                        className="h-9 px-3 rounded-xl text-[11px] bg-rose-500 text-white hover:bg-rose-600"
                        onClick={() => openLogPage("out")}
                    >
                        <ArrowUp className="w-3.5 h-3.5 ms-1" /> {t("warehouse.logOut")}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
                {kpis.map((s) => (
                    <Card key={s.label} className={cn("border bg-white/70 rounded-xl", s.border)}>
                        <CardContent className={cn("p-3.5", s.bg)}>
                            <div className="flex items-center justify-between mb-1.5">
                                <p className="text-[9px] font-bold text-ink/40 truncate">{s.label}</p>
                                <s.icon className={cn("w-3.5 h-3.5 shrink-0", s.color)} />
                            </div>
                            <p className={cn("text-xl font-black font-vazirmatn tabular-nums leading-none", s.color)}>
                                {isRefreshing && !inventory.length ? "…" : formatNumber(s.value)}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex gap-1 bg-white/50 border border-white/70 rounded-xl p-1 w-fit">
                    {[
                        { key: "inventory" as const, label: t("warehouse.tabs.inventory"), icon: Package, count: inventory.length },
                        { key: "log" as const, label: t("warehouse.tabs.log"), icon: History, count: logsLoaded ? logs.length : null },
                    ].map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setActiveTab(tab.key)}
                            className={cn(
                                "flex items-center gap-1.5 px-3.5 py-2 rounded-[9px] font-black text-[10px] font-vazirmatn transition-all",
                                activeTab === tab.key ? "bg-white shadow-sm text-primary" : "text-ink/35"
                            )}
                        >
                            <tab.icon className="w-3.5 h-3.5" />
                            {tab.label}
                            {tab.count != null && (
                                <span className="text-[9px] opacity-50">({formatNumber(tab.count)})</span>
                            )}
                        </button>
                    ))}
                </div>

                <div className="relative flex-1 max-w-md">
                    <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/25" />
                    <input
                        value={activeTab === "inventory" ? inventorySearch : logSearch}
                        onChange={(e) =>
                            activeTab === "inventory"
                                ? setInventorySearch(e.target.value)
                                : setLogSearch(e.target.value)
                        }
                        placeholder={activeTab === "inventory" ? t("warehouse.searchInventory") : t("warehouse.searchLog")}
                        className="w-full h-9 ps-9 pe-3 rounded-xl border border-white bg-white/70 text-[11px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                    />
                </div>
            </div>

            {activeTab === "inventory" && (
                <div className="flex gap-1.5 flex-wrap">
                    {([
                        { key: "all" as const, label: t("warehouse.stockFilter.all"), count: inventory.length },
                        { key: "low" as const, label: t("warehouse.stockFilter.low"), count: lowStockCount },
                        { key: "out" as const, label: t("warehouse.stockFilter.out"), count: outStockCount },
                    ]).map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            onClick={() => setStockFilter(f.key)}
                            className={cn(
                                "h-8 px-3 rounded-lg text-[10px] font-black font-vazirmatn transition-all border flex items-center gap-1.5",
                                stockFilter === f.key
                                    ? "bg-white shadow-sm text-primary border-primary/15"
                                    : "bg-white/50 text-ink/35 border-transparent hover:text-ink/60"
                            )}
                        >
                            {f.label}
                            <span className="tabular-nums opacity-60">{formatNumber(f.count)}</span>
                        </button>
                    ))}
                </div>
            )}

            {activeTab === "log" && (
                <div className="flex gap-1.5 flex-wrap">
                    {(["all", "in", "out"] as const).map((dir) => (
                        <button
                            key={dir}
                            type="button"
                            onClick={() => setLogDirectionFilter(dir)}
                            className={cn(
                                "h-8 px-3 rounded-lg text-[10px] font-black font-vazirmatn transition-all border",
                                logDirectionFilter === dir
                                    ? dir === "in"
                                        ? "bg-emerald-500 text-white border-emerald-500"
                                        : dir === "out"
                                            ? "bg-rose-500 text-white border-rose-500"
                                            : "bg-primary text-white border-primary"
                                    : "bg-white/70 text-ink/40 border-white hover:border-primary/20"
                            )}
                        >
                            {dir === "all" ? t("common.all") : dir === "in" ? t("warehouse.logIn") : t("warehouse.logOut")}
                        </button>
                    ))}
                </div>
            )}

            {activeTab === "inventory" ? (
                <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                    {isRefreshing && !inventory.length ? (
                        <ListSkeleton rows={4} />
                    ) : pageInventory.length === 0 ? (
                        <div className="p-10 text-center text-ink/30 text-[12px] font-black font-vazirmatn">
                            {inventorySearch || stockFilter !== "all" ? t("common.noResults") : t("warehouse.empty.inventory")}
                        </div>
                    ) : (
                        <>
                            <div className="divide-y divide-ink/5">
                                {pageInventory.map((item) => {
                                    const low = item.quantity > 0 && item.quantity <= lowStockThreshold;
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-center justify-between gap-3 px-4 md:px-5 py-3.5 hover:bg-white/60 transition-colors"
                                        >
                                            <div className="flex items-center gap-3 min-w-0">
                                                <div className={cn(
                                                    "w-9 h-9 rounded-lg flex items-center justify-center shrink-0 border",
                                                    low ? "bg-rose-50 border-rose-100" : "bg-parchment/30 border-ink/5"
                                                )}>
                                                    <BookOpen className={cn("w-4 h-4", low ? "text-rose-400" : "text-ink/25")} />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="text-[13px] font-black font-vazirmatn truncate">
                                                        {item.book?.title}
                                                    </p>
                                                    <div className="flex flex-wrap items-center gap-1.5 mt-0.5">
                                                        {item.book?.author && (
                                                            <span className="text-[9px] text-ink/35 font-vazirmatn">{item.book.author}</span>
                                                        )}
                                                        <Badge className={cn(
                                                            "text-[8px] font-black border",
                                                            item.type === "consignment"
                                                                ? "bg-amber-50 text-amber-600 border-amber-100"
                                                                : "bg-sky-50 text-sky-600 border-sky-100"
                                                        )}>
                                                            {item.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}
                                                        </Badge>
                                                        {low && (
                                                            <Badge className="text-[8px] font-black bg-rose-50 text-rose-500 border-rose-100">
                                                                {t("inventory.lowStock")}
                                                            </Badge>
                                                        )}
                                                        {item.quantity <= 0 && (
                                                            <Badge className="text-[8px] font-black bg-parchment text-ink/40 border-ink/5">
                                                                {t("inventory.outOfStock")}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <p className={cn(
                                                "text-lg font-black font-vazirmatn shrink-0 tabular-nums",
                                                low || item.quantity <= 0 ? "text-rose-500" : "text-ink"
                                            )}>
                                                {formatNumber(item.quantity)}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                            {invTotalPages > 1 && (
                                <div className="flex items-center justify-between px-4 py-3 border-t border-ink/5 text-[10px] font-bold text-ink/35">
                                    <span className="font-vazirmatn">
                                        {t("inventory.showingEntries", {
                                            start: formatNumber((safeInvPage - 1) * INV_PAGE_SIZE + 1),
                                            end: formatNumber(Math.min(safeInvPage * INV_PAGE_SIZE, filteredInventory.length)),
                                            total: formatNumber(filteredInventory.length),
                                        })}
                                    </span>
                                    <div className="flex gap-1.5">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 px-2.5 rounded-lg text-[10px]"
                                            disabled={safeInvPage <= 1}
                                            onClick={() => setInvPage((p) => Math.max(1, p - 1))}
                                        >
                                            {t("common.previous")}
                                        </Button>
                                        <span className="h-8 px-2.5 rounded-lg bg-primary text-white text-[10px] font-vazirmatn tabular-nums flex items-center">
                                            {formatNumber(safeInvPage)} / {formatNumber(invTotalPages)}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 px-2.5 rounded-lg text-[10px]"
                                            disabled={safeInvPage >= invTotalPages}
                                            onClick={() => setInvPage((p) => Math.min(invTotalPages, p + 1))}
                                        >
                                            {t("common.next")}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </Card>
            ) : (
                <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                    {isLoadingLogs && !logs.length ? (
                        <ListSkeleton rows={5} />
                    ) : filteredLogs.length === 0 ? (
                        <div className="p-10 text-center text-ink/30 text-[12px] font-black font-vazirmatn">
                            {logSearch || logDirectionFilter !== "all" ? t("common.noResults") : t("warehouse.empty.log")}
                        </div>
                    ) : (
                        <>
                            <div className="divide-y divide-ink/5">
                                {filteredLogs.map((log) => (
                                    <div key={log.id} className="flex items-center gap-3 md:gap-4 px-4 md:px-5 py-3.5 hover:bg-white/60 group">
                                        <div className={cn(
                                            "w-9 h-9 rounded-lg flex items-center justify-center border shrink-0",
                                            log.direction === "in" ? "bg-emerald-50 border-emerald-100" : "bg-rose-50 border-rose-100"
                                        )}>
                                            {log.direction === "in"
                                                ? <ArrowDown className="w-4 h-4 text-emerald-500" />
                                                : <ArrowUp className="w-4 h-4 text-rose-500" />}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[13px] font-black font-vazirmatn truncate">{log.book?.title}</p>
                                            <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5 text-[9px] text-ink/35 font-vazirmatn">
                                                <span className="inline-flex items-center gap-0.5">
                                                    <User className="w-2.5 h-2.5" /> {log.handler_name}
                                                </span>
                                                <span>
                                                    {REASON_KEYS[log.reason || ""]
                                                        ? t(REASON_KEYS[log.reason || ""])
                                                        : log.reason}
                                                </span>
                                                <span>{String(log.log_date || "").slice(0, 10)}</span>
                                            </div>
                                        </div>
                                        <p className={cn(
                                            "text-base font-black font-vazirmatn shrink-0 tabular-nums",
                                            log.direction === "in" ? "text-emerald-500" : "text-rose-500"
                                        )}>
                                            {log.direction === "in" ? "+" : "−"}{formatNumber(log.quantity)}
                                        </p>
                                        {!log.related_transfer_id ? (
                                            <button
                                                type="button"
                                                onClick={() => openEditLog(log)}
                                                className="p-2 rounded-lg border border-ink/5 bg-white/50 hover:border-primary/20 hover:bg-primary/5 text-ink/30 hover:text-primary shrink-0"
                                                title={t("common.edit")}
                                            >
                                                <Edit3 className="w-3.5 h-3.5" />
                                            </button>
                                        ) : (
                                            <span className="w-8 shrink-0" />
                                        )}
                                    </div>
                                ))}
                            </div>
                            {logHasMore && !logSearch.trim() && (
                                <div className="p-3 border-t border-ink/5">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="w-full h-9 rounded-xl text-[11px]"
                                        disabled={loadingMoreLogs}
                                        onClick={() =>
                                            activeBranch &&
                                            fetchLogs(activeBranch.id, logPage + 1, true, logDirectionFilter)
                                        }
                                    >
                                        {loadingMoreLogs ? t("common.loading") : t("warehouse.loadMore")}
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </Card>
            )}
        </div>
    );
}
