"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import {
    Building2, Plus, UserCog, Globe, AlertOctagon, RefreshCw,
    Pencil, X, Warehouse, Store, Users, FolderOpen, Activity,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useRouter } from "@/i18n/routing";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { RequireRole } from "@/components/auth/RequireRole";

interface BranchRow {
    id: number;
    name: string;
    city?: string | null;
    country?: string | null;
    type: "store" | "warehouse" | string;
    status: "active" | "inactive" | string;
    total_stock?: number | null;
    users_count?: number | null;
}

type BranchForm = {
    name: string;
    city: string;
    country: string;
    type: "store" | "warehouse";
    status: "active" | "inactive";
};

const QUICK_LINKS = [
    { labelKey: "admin.userManagement", icon: UserCog, color: "text-primary", href: "/dashboard/admin/users" },
    { labelKey: "admin.currencySettings", icon: Globe, color: "text-accent", href: "/dashboard/admin/currency" },
    { labelKey: "admin.categorySettings", icon: FolderOpen, color: "text-emerald-600", href: "/dashboard/admin/categories" },
    { labelKey: "admin.activityLog", icon: Activity, color: "text-teal-600", href: "/dashboard/admin/activity" },
] as const;

function emptyForm(defaultCountry: string): BranchForm {
    return {
        name: "",
        city: "",
        country: defaultCountry,
        type: "store",
        status: "active",
    };
}

function dedupeBranches(list: BranchRow[]): BranchRow[] {
    const byName = new Map<string, BranchRow>();
    for (const b of list) {
        const key = (b.name || "").trim().toLowerCase();
        if (!key) continue;
        const existing = byName.get(key);
        if (!existing) {
            byName.set(key, b);
            continue;
        }
        const curScore = Number(b.total_stock || 0) + Number(b.users_count || 0);
        const prevScore = Number(existing.total_stock || 0) + Number(existing.users_count || 0);
        if (curScore > prevScore || (curScore === prevScore && b.id < existing.id)) {
            byName.set(key, b);
        }
    }
    return Array.from(byName.values()).sort((a, b) => {
        if (a.type !== b.type) return a.type === "warehouse" ? -1 : 1;
        return a.name.localeCompare(b.name, "fa");
    });
}

export default function AdminPage() {
    return (
        <RequireRole roles={["super_admin", "admin"]}>
            <AdminPageContent />
        </RequireRole>
    );
}

function AdminPageContent() {
    const { t, formatNumber, isArabic } = useTranslation();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;
    const router = useRouter();

    const defaultCountry = isArabic ? "عراق" : "ایران";

    const [branches, setBranches] = useState<BranchRow[]>([]);
    const [lowStockThreshold, setLowStockThreshold] = useState(5);
    const [savedThreshold, setSavedThreshold] = useState(5);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isSavingThreshold, setIsSavingThreshold] = useState(false);
    const [isSavingBranch, setIsSavingBranch] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<BranchForm>(() => emptyForm(defaultCountry));
    const [search, setSearch] = useState("");

    const fetchData = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const [bData, settings] = await Promise.all([
                apiRequest("/branches"),
                apiRequest("/settings"),
            ]);
            const list = Array.isArray(bData) ? bData : [];
            setBranches(dedupeBranches(list));
            const threshold = Number(settings?.low_stock_threshold ?? 5);
            setLowStockThreshold(threshold);
            setSavedThreshold(threshold);
        } catch (error) {
            console.error("Admin fetch failed:", error);
            notifyRef.current.error("admin.loadError");
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return branches;
        return branches.filter((b) =>
            (b.name || "").toLowerCase().includes(q) ||
            (b.city || "").toLowerCase().includes(q)
        );
    }, [branches, search]);

    const stats = useMemo(() => {
        const stores = branches.filter((b) => b.type === "store").length;
        const warehouses = branches.filter((b) => b.type === "warehouse").length;
        const active = branches.filter((b) => b.status === "active").length;
        const stock = branches.reduce((a, b) => a + Number(b.total_stock || 0), 0);
        const users = branches.reduce((a, b) => a + Number(b.users_count || 0), 0);
        return { stores, warehouses, active, stock, users, total: branches.length };
    }, [branches]);

    const countryLabel = (country: string) =>
        country === "عراق" || country === "iraq" ? t("countries.iraq") : t("countries.iran");

    const branchTypeLabel = (type: string) =>
        type === "warehouse" ? t("admin.branchType.warehouse") : t("admin.branchType.store");

    const openCreate = () => {
        setEditingId(null);
        setForm(emptyForm(defaultCountry));
        setShowForm(true);
    };

    const openEdit = (branch: BranchRow) => {
        setEditingId(branch.id);
        setForm({
            name: branch.name,
            city: branch.city || "",
            country: branch.country || defaultCountry,
            type: branch.type === "warehouse" ? "warehouse" : "store",
            status: branch.status === "inactive" ? "inactive" : "active",
        });
        setShowForm(true);
    };

    const closeForm = (force = false) => {
        if (isSavingBranch && !force) return;
        setShowForm(false);
        setEditingId(null);
        setForm(emptyForm(defaultCountry));
    };

    const handleSaveBranch = async () => {
        if (!form.name.trim() || !form.city.trim()) {
            notify.error("admin.form.required");
            return;
        }
        setIsSavingBranch(true);
        try {
            if (editingId) {
                const updated = await apiRequest(`/branches/${editingId}`, {
                    method: "PUT",
                    body: JSON.stringify(form),
                });
                setBranches((prev) =>
                    dedupeBranches(prev.map((b) => (b.id === editingId ? { ...b, ...updated } : b)))
                );
                notify.success("toast.branchUpdateSuccess");
            } else {
                const created = await apiRequest("/branches", {
                    method: "POST",
                    body: JSON.stringify({ ...form, status: "active" }),
                });
                setBranches((prev) => dedupeBranches([...prev, created]));
                notify.success("toast.branchAddSuccess");
            }
            closeForm(true);
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error(editingId ? "toast.branchUpdateError" : "toast.branchAddError");
        } finally {
            setIsSavingBranch(false);
        }
    };

    const toggleStatus = async (branch: BranchRow) => {
        const next = branch.status === "active" ? "inactive" : "active";
        setTogglingId(branch.id);
        try {
            const updated = await apiRequest(`/branches/${branch.id}`, {
                method: "PUT",
                body: JSON.stringify({ status: next }),
            });
            setBranches((prev) =>
                prev.map((b) => (b.id === branch.id ? { ...b, ...updated, status: next } : b))
            );
            notify.success("toast.branchUpdateSuccess");
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.branchUpdateError");
        } finally {
            setTogglingId(null);
        }
    };

    const saveThreshold = async () => {
        if (lowStockThreshold === savedThreshold) return;
        if (lowStockThreshold < 1 || lowStockThreshold > 100) {
            notify.error("admin.thresholdInvalid");
            return;
        }
        setIsSavingThreshold(true);
        try {
            await apiRequest("/settings", {
                method: "PUT",
                body: JSON.stringify({ low_stock_threshold: lowStockThreshold }),
            });
            setSavedThreshold(lowStockThreshold);
            notify.success("toast.settingsSaved");
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.settingsSaveError");
        } finally {
            setIsSavingThreshold(false);
        }
    };

    const thresholdDirty = lowStockThreshold !== savedThreshold;

    const kpis = [
        {
            label: t("admin.kpi.branches"),
            value: stats.total,
            hint: `${formatNumber(stats.stores)} ${t("admin.branchType.store")} · ${formatNumber(stats.warehouses)} ${t("admin.branchType.warehouse")}`,
            icon: Building2,
            color: "text-primary",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
        },
        {
            label: t("admin.kpi.active"),
            value: stats.active,
            hint: t("admin.kpi.activeHint"),
            icon: Store,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/40",
        },
        {
            label: t("admin.kpi.stock"),
            value: stats.stock,
            hint: t("common.units.volume"),
            icon: Warehouse,
            color: "text-amber-600",
            border: "border-amber-100",
            bg: "bg-amber-50/40",
        },
        {
            label: t("admin.kpi.users"),
            value: stats.users,
            hint: t("admin.kpi.usersHint"),
            icon: Users,
            color: "text-ink",
            border: "border-ink/8",
            bg: "bg-white/50",
        },
    ];

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("admin.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-0.5">{t("admin.branchManagement")}</p>
                </div>
                <div className="flex items-center gap-2">
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
                    <Button variant="primary" size="sm" className="h-9 px-4 rounded-xl text-[11px]" onClick={openCreate}>
                        <Plus className="w-3.5 h-3.5 ms-1.5" />
                        {t("admin.addBranch")}
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
                                {isLoading && !branches.length ? "…" : formatNumber(kpi.value)}
                            </p>
                            <p className="text-[9px] text-ink/30 mt-1.5 truncate">{kpi.hint}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
                <div className="lg:col-span-2 space-y-4">
                    <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                        <CardHeader className="px-4 py-3 border-b border-ink/5 flex flex-row items-center justify-between gap-3">
                            <CardTitle className="text-[13px] font-black font-vazirmatn text-ink">
                                {t("admin.branches")}
                            </CardTitle>
                            <input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t("admin.searchBranches")}
                                className="h-8 w-40 sm:w-52 rounded-lg border border-ink/8 bg-white px-2.5 text-[11px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            />
                        </CardHeader>
                        <CardContent className="p-2 space-y-0.5">
                            {isLoading && !branches.length ? (
                                Array.from({ length: 4 }).map((_, i) => (
                                    <div key={i} className="h-14 bg-parchment/20 rounded-xl animate-pulse" />
                                ))
                            ) : filtered.length === 0 ? (
                                <p className="py-10 text-center text-[12px] font-black text-ink/30">
                                    {t("admin.emptyBranches")}
                                </p>
                            ) : (
                                filtered.map((branch) => (
                                    <div
                                        key={branch.id}
                                        className="flex items-center gap-3 p-3 rounded-xl hover:bg-white/80 transition-colors"
                                    >
                                        <div className={cn(
                                            "w-9 h-9 rounded-lg border flex items-center justify-center shrink-0",
                                            branch.type === "warehouse"
                                                ? "bg-amber-50 border-amber-100"
                                                : "bg-primary/10 border-primary/10"
                                        )}>
                                            {branch.type === "warehouse" ? (
                                                <Warehouse className="w-4 h-4 text-amber-600" />
                                            ) : (
                                                <Building2 className="w-4 h-4 text-primary" />
                                            )}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[13px] font-black font-vazirmatn text-ink truncate">
                                                {branch.name}
                                            </p>
                                            <p className="text-[9px] text-ink/35 mt-0.5 truncate">
                                                {branch.city} · {countryLabel(branch.country || "")} · {branchTypeLabel(branch.type)}
                                                {Number(branch.users_count || 0) > 0 && (
                                                    <> · {formatNumber(Number(branch.users_count))} {t("admin.kpi.usersHint")}</>
                                                )}
                                            </p>
                                        </div>
                                        <div className="hidden sm:block text-end shrink-0">
                                            <p className="text-[8px] text-ink/25 font-bold">{t("inventory.stock")}</p>
                                            <p className="text-[11px] font-black font-vazirmatn tabular-nums text-ink/70">
                                                {formatNumber(Number(branch.total_stock || 0))}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            disabled={togglingId === branch.id}
                                            onClick={() => toggleStatus(branch)}
                                            className="shrink-0"
                                            title={t("admin.toggleStatus")}
                                        >
                                            <Badge className={cn(
                                                "text-[8px] font-black h-5 px-2",
                                                branch.status === "active"
                                                    ? "bg-emerald-50 text-emerald-600 border-emerald-100"
                                                    : "bg-parchment text-ink/40 border-ink/5"
                                            )}>
                                                {branch.status === "active" ? t("admin.active") : t("admin.inactive")}
                                            </Badge>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => openEdit(branch)}
                                            className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:text-primary hover:bg-primary/5"
                                            title={t("common.edit")}
                                            aria-label={t("common.edit")}
                                        >
                                            <Pencil className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                        <CardHeader className="px-4 py-3 border-b border-ink/5">
                            <CardTitle className="text-[13px] font-black font-vazirmatn text-ink">
                                {t("admin.alertConfig")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-4">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-rose-100 bg-rose-50/40">
                                <div className="flex gap-3 min-w-0">
                                    <div className="w-9 h-9 rounded-lg bg-rose-50 border border-rose-100 flex items-center justify-center shrink-0">
                                        <AlertOctagon className="w-4 h-4 text-rose-500" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-[12px] font-black font-vazirmatn text-ink">
                                            {t("admin_settings.criticalStockThreshold")}
                                        </p>
                                        <p className="text-[10px] text-ink/40 mt-0.5">
                                            {t("admin_settings.criticalStockDescription")}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <input
                                        type="number"
                                        min={1}
                                        max={100}
                                        value={lowStockThreshold}
                                        onChange={(e) => setLowStockThreshold(Number(e.target.value))}
                                        className="w-16 h-9 bg-white border border-ink/10 rounded-lg px-2 text-center text-[12px] font-black font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                                    />
                                    <Button
                                        size="sm"
                                        className="h-9 px-3 rounded-lg text-[10px]"
                                        disabled={!thresholdDirty || isSavingThreshold}
                                        onClick={saveThreshold}
                                    >
                                        {isSavingThreshold ? t("common.saving") : t("common.save")}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                        <CardHeader className="px-4 py-3 border-b border-ink/5">
                            <CardTitle className="text-[11px] font-black font-vazirmatn text-ink/50">
                                {t("admin.quickAccess")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-2 space-y-0.5">
                            {QUICK_LINKS.map((action) => (
                                <button
                                    key={action.href}
                                    type="button"
                                    onClick={() => router.push(action.href)}
                                    className="w-full flex items-center gap-3 p-3 rounded-xl hover:bg-white/80 transition-colors text-start"
                                >
                                    <action.icon className={cn("w-4 h-4", action.color)} />
                                    <span className="text-[11px] font-black font-vazirmatn text-ink/70">
                                        {t(action.labelKey)}
                                    </span>
                                </button>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {showForm && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink/30 backdrop-blur-sm"
                    onClick={(e) => e.target === e.currentTarget && closeForm()}
                >
                    <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl p-5 space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-[15px] font-black font-vazirmatn">
                                {editingId ? t("admin.editBranch") : t("admin.addBranch")}
                            </h3>
                            <button type="button" onClick={() => closeForm()} disabled={isSavingBranch}>
                                <X className="w-4 h-4 text-ink/40" />
                            </button>
                        </div>
                        <input
                            placeholder={t("admin.form.branchName")}
                            value={form.name}
                            onChange={(e) => setForm((b) => ({ ...b, name: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                        />
                        <input
                            placeholder={t("admin.form.city")}
                            value={form.city}
                            onChange={(e) => setForm((b) => ({ ...b, city: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                        />
                        <div className="grid grid-cols-2 gap-2">
                            <select
                                value={form.country}
                                onChange={(e) => setForm((b) => ({ ...b, country: e.target.value }))}
                                className="h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="ایران">{t("countries.iran")}</option>
                                <option value="عراق">{t("countries.iraq")}</option>
                            </select>
                            <select
                                value={form.type}
                                onChange={(e) => setForm((b) => ({ ...b, type: e.target.value as "store" | "warehouse" }))}
                                className="h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="store">{t("admin.branchType.store")}</option>
                                <option value="warehouse">{t("admin.branchType.warehouse")}</option>
                            </select>
                        </div>
                        {editingId && (
                            <select
                                value={form.status}
                                onChange={(e) => setForm((b) => ({ ...b, status: e.target.value as "active" | "inactive" }))}
                                className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="active">{t("admin.active")}</option>
                                <option value="inactive">{t("admin.inactive")}</option>
                            </select>
                        )}
                        <div className="flex gap-2 pt-1">
                            <Button variant="ghost" className="flex-1 h-10 rounded-xl" disabled={isSavingBranch} onClick={() => closeForm()}>
                                {t("common.cancel")}
                            </Button>
                            <Button variant="primary" className="flex-1 h-10 rounded-xl font-black" disabled={isSavingBranch} onClick={handleSaveBranch}>
                                {isSavingBranch
                                    ? t("common.saving")
                                    : editingId
                                        ? t("common.save")
                                        : t("admin.submitBranch")}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
