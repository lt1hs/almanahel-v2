"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
    Activity, ArrowRight, Download, Filter, RefreshCw, Search, X, Calendar,
    User as UserIcon, Building2, ShieldAlert,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { apiDownload, apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";

interface ActivityRow {
    id: number;
    module: string;
    action: string;
    description: string;
    subject_type?: string | null;
    subject_id?: number | null;
    properties?: Record<string, unknown> | null;
    ip_address?: string | null;
    user_agent?: string | null;
    created_at: string;
    user?: { id: number; name: string; email: string; role: string } | null;
    branch?: { id: number; name: string; city?: string } | null;
}

interface MetaPayload {
    modules: string[];
    actions: string[];
    known_modules: string[];
    known_actions: string[];
    users: { id: number; name: string; email: string }[];
    branches: { id: number; name: string; city?: string }[];
}

type Filters = {
    date_from: string;
    date_to: string;
    module: string;
    action: string;
    user_id: string;
    branch_id: string;
    q: string;
};

const EMPTY_FILTERS: Filters = {
    date_from: "",
    date_to: "",
    module: "",
    action: "",
    user_id: "",
    branch_id: "",
    q: "",
};

function buildQuery(filters: Filters, page?: number) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) params.set(key, value);
    });
    if (page) params.set("page", String(page));
    params.set("per_page", "25");
    return params.toString();
}

function formatDateTime(value: string, locale: string) {
    try {
        return new Intl.DateTimeFormat(locale === "ar" ? "ar" : "fa-IR", {
            dateStyle: "short",
            timeStyle: "medium",
        }).format(new Date(value));
    } catch {
        return value;
    }
}

export default function ActivityLogPage() {
    const { t, isArabic } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const { user } = useAuth();
    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
    const [applied, setApplied] = useState<Filters>(EMPTY_FILTERS);
    const [rows, setRows] = useState<ActivityRow[]>([]);
    const [meta, setMeta] = useState<MetaPayload | null>(null);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [isLoading, setIsLoading] = useState(true);
    const [isExporting, setIsExporting] = useState(false);
    const [selected, setSelected] = useState<ActivityRow | null>(null);

    useEffect(() => {
        if (!isAdmin) {
            router.replace("/dashboard");
        }
    }, [isAdmin, router]);

    const loadMeta = useCallback(async () => {
        try {
            const data = await apiRequest("/activity-logs/meta");
            setMeta(data);
        } catch {
            /* ignore — filters still usable via known lists */
        }
    }, []);

    const loadLogs = useCallback(async (nextPage = 1, nextFilters = applied) => {
        setIsLoading(true);
        try {
            const data = await apiRequest(`/activity-logs?${buildQuery(nextFilters, nextPage)}`);
            setRows(Array.isArray(data?.data) ? data.data : []);
            setPage(Number(data?.current_page || 1));
            setLastPage(Number(data?.last_page || 1));
            setTotal(Number(data?.total || 0));
        } catch (error) {
            console.error(error);
            notify.error("activityLog.loadError");
            setRows([]);
        } finally {
            setIsLoading(false);
        }
    }, [applied, notify]);

    useEffect(() => {
        if (!isAdmin) return;
        loadMeta();
        loadLogs(1, EMPTY_FILTERS);
    }, [isAdmin]); // eslint-disable-line react-hooks/exhaustive-deps

    const moduleOptions = useMemo(() => {
        const list = meta?.modules?.length
            ? meta.modules
            : meta?.known_modules || [];
        return list;
    }, [meta]);

    const actionOptions = useMemo(() => {
        const list = meta?.actions?.length
            ? meta.actions
            : meta?.known_actions || [];
        return list;
    }, [meta]);

    const moduleLabel = (key: string) => {
        const path = `activityLog.modules.${key}`;
        const translated = t(path);
        return translated === path ? key : translated;
    };

    const actionLabel = (key: string) => {
        const path = `activityLog.actions.${key}`;
        const translated = t(path);
        return translated === path ? key : translated;
    };

    const applyFilters = () => {
        setApplied(filters);
        setSelected(null);
        loadLogs(1, filters);
    };

    const resetFilters = () => {
        setFilters(EMPTY_FILTERS);
        setApplied(EMPTY_FILTERS);
        setSelected(null);
        loadLogs(1, EMPTY_FILTERS);
    };

    const handleExport = async () => {
        setIsExporting(true);
        try {
            await apiDownload(
                `/activity-logs/export?${buildQuery(applied)}`,
                `activity-logs-${new Date().toISOString().slice(0, 10)}.csv`
            );
            notify.success("activityLog.exportSuccess");
        } catch (error) {
            console.error(error);
            notify.error("activityLog.exportError");
        } finally {
            setIsExporting(false);
        }
    };

    if (!isAdmin) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-3 text-ink/40">
                <ShieldAlert className="w-8 h-8" />
                <p className="text-sm font-black font-vazirmatn">{t("activityLog.forbidden")}</p>
            </div>
        );
    }

    return (
        <div className="space-y-4 pb-10">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-start gap-3">
                    <button
                        type="button"
                        onClick={() => router.push("/dashboard/admin")}
                        className="mt-0.5 w-9 h-9 rounded-xl border border-white bg-white/70 flex items-center justify-center text-ink/40 hover:text-primary"
                        aria-label={t("common.back")}
                    >
                        <ArrowRight className={cn("w-4 h-4", isArabic && "rotate-180")} />
                    </button>
                    <div>
                        <h1 className="text-xl font-black font-vazirmatn text-ink flex items-center gap-2">
                            <Activity className="w-5 h-5 text-primary" />
                            {t("activityLog.title")}
                        </h1>
                        <p className="text-[10px] text-ink/35 font-bold mt-0.5">
                            {t("activityLog.subtitle")}
                            {total > 0 && (
                                <span className="ms-2 text-primary/70">
                                    ({total.toLocaleString(isArabic ? "ar" : "fa-IR")})
                                </span>
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={isLoading}
                        onClick={() => loadLogs(page, applied)}
                        className="gap-1.5"
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5", isLoading && "animate-spin")} />
                        {t("common.refresh")}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        disabled={isExporting || isLoading}
                        onClick={handleExport}
                        className="gap-1.5"
                    >
                        <Download className="w-3.5 h-3.5" />
                        {isExporting ? t("common.loading") : t("activityLog.export")}
                    </Button>
                </div>
            </div>

            <Card className="border border-white/70 bg-white/70 rounded-2xl">
                <CardHeader className="px-4 py-3 border-b border-ink/5 flex flex-row items-center gap-2">
                    <Filter className="w-3.5 h-3.5 text-ink/35" />
                    <CardTitle className="text-[12px] font-black font-vazirmatn text-ink">
                        {t("activityLog.filters")}
                    </CardTitle>
                </CardHeader>
                <CardContent className="p-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                        <Field label={t("activityLog.dateFrom")}>
                            <input
                                type="date"
                                value={filters.date_from}
                                onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            />
                        </Field>
                        <Field label={t("activityLog.dateTo")}>
                            <input
                                type="date"
                                value={filters.date_to}
                                onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            />
                        </Field>
                        <Field label={t("activityLog.module")}>
                            <select
                                value={filters.module}
                                onChange={(e) => setFilters((f) => ({ ...f, module: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="">{t("activityLog.allModules")}</option>
                                {moduleOptions.map((m) => (
                                    <option key={m} value={m}>{moduleLabel(m)}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t("activityLog.action")}>
                            <select
                                value={filters.action}
                                onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="">{t("activityLog.allActions")}</option>
                                {actionOptions.map((a) => (
                                    <option key={a} value={a}>{actionLabel(a)}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t("activityLog.user")}>
                            <select
                                value={filters.user_id}
                                onChange={(e) => setFilters((f) => ({ ...f, user_id: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="">{t("activityLog.allUsers")}</option>
                                {(meta?.users || []).map((u) => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t("activityLog.branch")}>
                            <select
                                value={filters.branch_id}
                                onChange={(e) => setFilters((f) => ({ ...f, branch_id: e.target.value }))}
                                className="w-full h-9 rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="">{t("activityLog.allBranches")}</option>
                                {(meta?.branches || []).map((b) => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t("activityLog.search")} className="sm:col-span-2">
                            <div className="relative">
                                <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/30" />
                                <input
                                    type="search"
                                    value={filters.q}
                                    onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
                                    onKeyDown={(e) => e.key === "Enter" && applyFilters()}
                                    placeholder={t("activityLog.searchPlaceholder")}
                                    className="w-full h-9 rounded-xl border border-ink/10 bg-white ps-9 pe-3 text-[12px] font-bold text-ink outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/15"
                                />
                            </div>
                        </Field>
                    </div>
                    <div className="flex gap-2 mt-3 justify-end">
                        <Button type="button" variant="outline" size="sm" onClick={resetFilters}>
                            {t("activityLog.reset")}
                        </Button>
                        <Button type="button" size="sm" onClick={applyFilters}>
                            {t("activityLog.apply")}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div className="grid grid-cols-1 xl:grid-cols-5 gap-4">
                <Card className="xl:col-span-3 border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-end border-collapse min-w-[640px]">
                                <thead>
                                    <tr className="bg-white/50 border-b border-ink/5">
                                        {[
                                            t("activityLog.time"),
                                            t("activityLog.user"),
                                            t("activityLog.module"),
                                            t("activityLog.action"),
                                            t("activityLog.description"),
                                            t("activityLog.branch"),
                                        ].map((h) => (
                                            <th key={h} className="px-3 py-2.5 text-[9px] font-black text-ink/35 uppercase tracking-wider">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/[0.04]">
                                    {isLoading ? (
                                        Array.from({ length: 6 }).map((_, i) => (
                                            <tr key={i}>
                                                <td colSpan={6} className="px-3 py-3">
                                                    <div className="h-8 rounded-lg bg-parchment/30 animate-pulse" />
                                                </td>
                                            </tr>
                                        ))
                                    ) : rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-3 py-16 text-center text-[11px] font-black text-ink/25">
                                                {t("activityLog.empty")}
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                onClick={() => setSelected(row)}
                                                className={cn(
                                                    "cursor-pointer hover:bg-primary/[0.03] transition-colors",
                                                    selected?.id === row.id && "bg-primary/[0.06]"
                                                )}
                                            >
                                                <td className="px-3 py-2.5 text-[10px] font-bold text-ink/50 whitespace-nowrap">
                                                    <span className="inline-flex items-center gap-1">
                                                        <Calendar className="w-3 h-3 opacity-40" />
                                                        {formatDateTime(row.created_at, isArabic ? "ar" : "fa")}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2.5 text-[11px] font-black text-ink truncate max-w-[120px]">
                                                    {row.user?.name || "—"}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <Badge variant="outline" className="text-[9px] font-bold">
                                                        {moduleLabel(row.module)}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <Badge className="text-[9px] font-bold bg-primary/10 text-primary border-0">
                                                        {actionLabel(row.action)}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2.5 text-[11px] font-bold text-ink/80 truncate max-w-[220px]">
                                                    {row.description}
                                                </td>
                                                <td className="px-3 py-2.5 text-[10px] font-bold text-ink/40 truncate max-w-[100px]">
                                                    {row.branch?.name || "—"}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {lastPage > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-ink/5">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={page <= 1 || isLoading}
                                    onClick={() => loadLogs(page - 1, applied)}
                                >
                                    {t("activityLog.prev")}
                                </Button>
                                <span className="text-[10px] font-black text-ink/40">
                                    {page} / {lastPage}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={page >= lastPage || isLoading}
                                    onClick={() => loadLogs(page + 1, applied)}
                                >
                                    {t("activityLog.next")}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="xl:col-span-2 border border-white/70 bg-white/70 rounded-2xl">
                    <CardHeader className="px-4 py-3 border-b border-ink/5 flex flex-row items-center justify-between">
                        <CardTitle className="text-[12px] font-black font-vazirmatn text-ink">
                            {t("activityLog.detail")}
                        </CardTitle>
                        {selected && (
                            <button type="button" onClick={() => setSelected(null)} className="text-ink/30 hover:text-ink">
                                <X className="w-4 h-4" />
                            </button>
                        )}
                    </CardHeader>
                    <CardContent className="p-4">
                        {!selected ? (
                            <p className="text-[11px] text-ink/30 font-bold text-center py-16">
                                {t("activityLog.selectRow")}
                            </p>
                        ) : (
                            <div className="space-y-3">
                                <p className="text-[13px] font-black font-vazirmatn text-ink leading-relaxed">
                                    {selected.description}
                                </p>
                                <DetailRow icon={Calendar} label={t("activityLog.time")} value={formatDateTime(selected.created_at, isArabic ? "ar" : "fa")} />
                                <DetailRow icon={UserIcon} label={t("activityLog.user")} value={selected.user ? `${selected.user.name} (${selected.user.email})` : "—"} />
                                <DetailRow icon={Building2} label={t("activityLog.branch")} value={selected.branch?.name || "—"} />
                                <DetailRow label={t("activityLog.module")} value={moduleLabel(selected.module)} />
                                <DetailRow label={t("activityLog.action")} value={actionLabel(selected.action)} />
                                <DetailRow
                                    label={t("activityLog.subject")}
                                    value={
                                        selected.subject_type
                                            ? `${selected.subject_type} #${selected.subject_id ?? "—"}`
                                            : "—"
                                    }
                                />
                                <DetailRow label={t("activityLog.ip")} value={selected.ip_address || "—"} />
                                {selected.user_agent && (
                                    <div>
                                        <p className="text-[9px] font-black text-ink/30 mb-1">{t("activityLog.userAgent")}</p>
                                        <p className="text-[10px] text-ink/50 break-all leading-relaxed">{selected.user_agent}</p>
                                    </div>
                                )}
                                {selected.properties && Object.keys(selected.properties).length > 0 && (
                                    <div>
                                        <p className="text-[9px] font-black text-ink/30 mb-1.5">{t("activityLog.properties")}</p>
                                        <pre className="text-[10px] font-mono bg-parchment/40 border border-ink/5 rounded-xl p-3 overflow-auto max-h-56 text-ink/70 whitespace-pre-wrap break-all">
                                            {JSON.stringify(selected.properties, null, 2)}
                                        </pre>
                                    </div>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

function Field({
    label,
    children,
    className,
}: {
    label: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn("space-y-1", className)}>
            <label className="text-[9px] font-black text-ink/40 uppercase tracking-wider block">{label}</label>
            {children}
        </div>
    );
}

function DetailRow({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string;
    icon?: React.ComponentType<{ className?: string }>;
}) {
    return (
        <div className="flex items-start justify-between gap-3 py-1.5 border-b border-ink/[0.04]">
            <span className="text-[9px] font-black text-ink/30 uppercase tracking-wider shrink-0 flex items-center gap-1">
                {Icon && <Icon className="w-3 h-3" />}
                {label}
            </span>
            <span className="text-[11px] font-bold text-ink text-end break-all">{value}</span>
        </div>
    );
}
