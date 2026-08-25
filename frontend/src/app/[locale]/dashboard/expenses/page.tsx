"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import {
    Wallet, Plus, Trash2, Building2, CalendarDays, X,
    RefreshCw, Pencil, Search,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { Input } from "@/components/ui/Input";
import { formatPriceDisplay, parsePriceDigits } from "@/lib/bookFormUtils";

const CATEGORY_KEYS = [
    "rent", "salary", "utilities", "transport", "marketing", "office", "other",
] as const;

type CategoryKey = (typeof CATEGORY_KEYS)[number];

/** Legacy rows stored Persian/Arabic display labels instead of keys */
const LEGACY_CATEGORY: Record<string, CategoryKey> = {
    "اجاره": "rent",
    "حقوق": "salary",
    "آب و برق": "utilities",
    "حمل و نقل": "transport",
    "تبلیغات": "marketing",
    "لوازم اداری": "office",
    "سایر": "other",
    "إيجار": "rent",
    "رواتب": "salary",
    "ماء وكهرباء": "utilities",
    "نقل": "transport",
    "إعلانات": "marketing",
    "لوازم مكتبية": "office",
    "أخرى": "other",
};

interface ExpenseRow {
    id: number;
    branch_id: number;
    amount: number;
    currency: "toman" | "dinar";
    category: string;
    description?: string | null;
    date: string;
    branch?: { id: number; name: string } | null;
}

interface BranchRow {
    id: number;
    name: string;
    type?: string;
}

function normalizeCategory(raw: string): CategoryKey {
    if ((CATEGORY_KEYS as readonly string[]).includes(raw)) return raw as CategoryKey;
    return LEGACY_CATEGORY[raw] || "other";
}

function monthBounds(ym: string): { from: string; to: string } | null {
    if (!/^\d{4}-\d{2}$/.test(ym)) return null;
    const [y, m] = ym.split("-").map(Number);
    const last = new Date(y, m, 0).getDate();
    return {
        from: `${ym}-01`,
        to: `${ym}-${String(last).padStart(2, "0")}`,
    };
}

function emptyForm(currency: "toman" | "dinar") {
    return {
        branch_id: "",
        amount: "",
        currency,
        category: "other" as CategoryKey,
        description: "",
        date: new Date().toISOString().split("T")[0],
    };
}

export default function ExpensesPage() {
    const { t, formatNumber, preferredCurrency } = useTranslation();
    const notify = useNotify();
    const { user } = useAuth();
    const isAdmin = user?.role === "admin" || user?.role === "super_admin";
    const userBranchId = user?.branch_id
        ? Number(user.branch_id)
        : user?.branch?.id
            ? Number(user.branch.id)
            : null;
    const defaultCurrency: "toman" | "dinar" = preferredCurrency;
    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");

    const [expenses, setExpenses] = useState<ExpenseRow[]>([]);
    const [branches, setBranches] = useState<BranchRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState(() => emptyForm(defaultCurrency));

    const notifyRef = React.useRef(notify);
    notifyRef.current = notify;

    const [branchFilter, setBranchFilter] = useState("");
    const [categoryFilter, setCategoryFilter] = useState("");
    const [monthFilter, setMonthFilter] = useState("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);

    const categoryLabel = useCallback(
        (raw: string) => t(`expenses.categories.${normalizeCategory(raw)}`),
        [t]
    );

    const allowedBranchIds = useMemo(() => {
        if (isAdmin) return null as number[] | null;
        const ids = new Set<number>();
        if (userBranchId) ids.add(userBranchId);
        (user?.iraq_only_visible_branches || []).forEach((id) => ids.add(Number(id)));
        return Array.from(ids);
    }, [isAdmin, userBranchId, user?.iraq_only_visible_branches]);

    const fetchBranches = useCallback(async () => {
        try {
            const bData = await apiRequest("/branches");
            const list = Array.isArray(bData) ? bData : [];
            const stores = list.filter((b: BranchRow) => b.type === "store" || !b.type);
            const seen = new Set<string>();
            let filtered = stores.filter((b: BranchRow) => {
                const key = `${b.name}|${b.type || "store"}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
            if (allowedBranchIds) {
                filtered = filtered.filter((b: BranchRow) =>
                    allowedBranchIds.includes(Number(b.id))
                );
            }
            setBranches(filtered);
        } catch {
            notifyRef.current.error("expenses.loadError");
        }
    }, [allowedBranchIds]);

    const buildQuery = useCallback((pageNum: number) => {
        const params = new URLSearchParams();
        params.set("per_page", "50");
        params.set("page", String(pageNum));
        if (isAdmin) {
            if (branchFilter) params.set("branch_id", branchFilter);
        } else if (userBranchId) {
            params.set("branch_id", String(userBranchId));
        }
        const bounds = monthBounds(monthFilter);
        if (bounds) {
            params.set("date_from", bounds.from);
            params.set("date_to", bounds.to);
        }
        return `/expenses?${params.toString()}`;
    }, [branchFilter, monthFilter, isAdmin, userBranchId]);

    const fetchExpenses = useCallback(async (soft = false, pageNum = 1, append = false) => {
        if (append) setLoadingMore(true);
        else if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const data = await apiRequest(buildQuery(pageNum));
            const list: ExpenseRow[] = data?.data ?? (Array.isArray(data) ? data : []);
            setExpenses((prev) => (append ? [...prev, ...list] : list));
            setPage(pageNum);
            const lastPage = Number(data?.last_page ?? 1);
            setHasMore(pageNum < lastPage);
        } catch (error) {
            console.error("Expenses fetch failed:", error);
            notifyRef.current.error("expenses.loadError");
            if (!append) setExpenses([]);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
            setLoadingMore(false);
        }
    }, [buildQuery]);

    useEffect(() => {
        fetchBranches();
    }, [fetchBranches]);

    useEffect(() => {
        fetchExpenses(false, 1, false);
    }, [fetchExpenses]);

    const filtered = useMemo(() => {
        let list = expenses;
        if (categoryFilter) {
            list = list.filter((e) => normalizeCategory(e.category) === categoryFilter);
        }
        const q = search.trim().toLowerCase();
        if (!q) return list;
        return list.filter((e) => {
            const cat = categoryLabel(e.category).toLowerCase();
            const desc = (e.description || "").toLowerCase();
            const branch = (e.branch?.name || "").toLowerCase();
            return cat.includes(q) || desc.includes(q) || branch.includes(q);
        });
    }, [expenses, search, categoryFilter, categoryLabel]);

    const totals = useMemo(() => {
        let toman = 0;
        let dinar = 0;
        for (const e of filtered) {
            if (e.currency === "dinar") dinar += Number(e.amount) || 0;
            else toman += Number(e.amount) || 0;
        }
        return { toman, dinar, count: filtered.length };
    }, [filtered]);

    const lockedBranchId = !isAdmin && userBranchId ? String(userBranchId) : "";

    const openCreate = () => {
        setEditingId(null);
        setForm({
            ...emptyForm(defaultCurrency),
            branch_id: lockedBranchId || (branches[0] ? String(branches[0].id) : ""),
        });
        setShowForm(true);
    };

    const openEdit = (exp: ExpenseRow) => {
        setEditingId(exp.id);
        setForm({
            branch_id: String(exp.branch_id),
            amount: parsePriceDigits(String(Math.round(Number(exp.amount) || 0))),
            currency: exp.currency,
            category: normalizeCategory(exp.category),
            description: exp.description || "",
            date: String(exp.date).slice(0, 10),
        });
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setEditingId(null);
        setForm(emptyForm(defaultCurrency));
    };

    const handleSubmit = async () => {
        const branchId = !isAdmin && userBranchId
            ? userBranchId
            : parseInt(form.branch_id, 10);
        const amount = Number(parsePriceDigits(form.amount));
        if (!branchId || !amount) {
            notify.error("toast.branchAmountRequired");
            return;
        }
        setIsSaving(true);
        const payload = {
            branch_id: branchId,
            amount,
            currency: form.currency,
            category: form.category,
            description: form.description || null,
            date: form.date,
        };
        try {
            if (editingId) {
                await apiRequest(`/expenses/${editingId}`, {
                    method: "PUT",
                    body: JSON.stringify(payload),
                });
                notify.success("toast.expenseSaveSuccess");
            } else {
                await apiRequest("/expenses", {
                    method: "POST",
                    body: JSON.stringify(payload),
                });
                notify.success("toast.expenseSaveSuccess");
            }
            closeForm();
            fetchExpenses(true, 1, false);
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.expenseSaveError");
        } finally {
            setIsSaving(false);
        }
    };

    const handleDelete = async (id: number) => {
        if (!confirm(t("expenses.confirmDelete"))) return;
        setDeletingId(id);
        try {
            await apiRequest(`/expenses/${id}`, { method: "DELETE" });
            notify.success("toast.expenseDeleteSuccess");
            setExpenses((prev) => prev.filter((e) => e.id !== id));
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.expenseDeleteError");
        } finally {
            setDeletingId(null);
        }
    };

    const kpis = [
        {
            label: t("expenses.kpi.totalToman"),
            value: totals.toman,
            suffix: tomanSymbol,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/40",
        },
        {
            label: t("expenses.kpi.totalDinar"),
            value: totals.dinar,
            suffix: dinarSymbol,
            color: "text-amber-600",
            border: "border-amber-100",
            bg: "bg-amber-50/40",
        },
        {
            label: t("expenses.kpi.count"),
            value: totals.count,
            suffix: "",
            color: "text-ink",
            border: "border-ink/8",
            bg: "bg-white/50",
        },
    ];

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("expenses.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold mt-0.5">{t("expenses.subtitle")}</p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        title={t("common.refresh")}
                        aria-label={t("common.refresh")}
                        disabled={isLoading || isRefreshing}
                        onClick={() => fetchExpenses(true, 1, false)}
                        className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing) && "animate-spin")} />
                    </button>
                    <Button variant="primary" size="sm" className="h-9 px-4 rounded-xl text-[11px]" onClick={openCreate}>
                        <Plus className="w-3.5 h-3.5 ms-1.5" /> {t("expenses.add")}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                {kpis.map((kpi) => (
                    <Card key={kpi.label} className={cn("border bg-white/70 rounded-xl", kpi.border)}>
                        <CardContent className={cn("p-3.5", kpi.bg)}>
                            <p className="text-[9px] font-bold text-ink/40 mb-1.5 truncate">{kpi.label}</p>
                            <p className={cn("text-xl font-black font-vazirmatn tabular-nums leading-none", kpi.color)}>
                                {isLoading && !expenses.length ? "…" : formatNumber(kpi.value)}
                                {kpi.suffix ? (
                                    <span className="text-[10px] text-ink/30 ms-1 font-bold">{kpi.suffix}</span>
                                ) : null}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-col sm:flex-row gap-2">
                <div className="relative flex-1">
                    <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/25" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("expenses.searchPlaceholder")}
                        className="w-full h-9 ps-9 pe-3 rounded-xl border border-white bg-white/70 text-[11px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                    />
                </div>
                {isAdmin ? (
                    <select
                        value={branchFilter}
                        onChange={(e) => setBranchFilter(e.target.value)}
                        className="h-9 rounded-xl border border-white bg-white/70 px-3 text-[11px] font-vazirmatn outline-none min-w-[140px]"
                    >
                        <option value="">{t("expenses.filters.allBranches")}</option>
                        {branches.map((b) => (
                            <option key={b.id} value={b.id}>{b.name}</option>
                        ))}
                    </select>
                ) : null}
                <select
                    value={categoryFilter}
                    onChange={(e) => setCategoryFilter(e.target.value)}
                    className="h-9 rounded-xl border border-white bg-white/70 px-3 text-[11px] font-vazirmatn outline-none min-w-[120px]"
                >
                    <option value="">{t("expenses.filters.allCategories")}</option>
                    {CATEGORY_KEYS.map((key) => (
                        <option key={key} value={key}>{t(`expenses.categories.${key}`)}</option>
                    ))}
                </select>
                <input
                    type="month"
                    value={monthFilter}
                    onChange={(e) => setMonthFilter(e.target.value)}
                    className="h-9 rounded-xl border border-white bg-white/70 px-3 text-[11px] font-vazirmatn outline-none"
                    title={t("expenses.filters.month")}
                />
            </div>

            <div className="space-y-2">
                {isLoading && !expenses.length ? (
                    Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="h-16 bg-parchment/20 rounded-xl animate-pulse" />
                    ))
                ) : filtered.length === 0 ? (
                    <Card className="border border-white/70 bg-white/70 rounded-2xl">
                        <CardContent className="p-10 text-center text-ink/30 text-[12px] font-black">
                            {t("expenses.empty")}
                        </CardContent>
                    </Card>
                ) : (
                    filtered.map((exp) => (
                        <div
                            key={exp.id}
                            className="rounded-xl border border-white/80 bg-white/75 px-3.5 py-3 flex items-center gap-3"
                        >
                            <div className="w-9 h-9 rounded-lg bg-rose-50 border border-rose-100 flex items-center justify-center shrink-0">
                                <Wallet className="w-4 h-4 text-rose-400" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-[13px] font-black font-vazirmatn text-ink">
                                        {categoryLabel(exp.category)}
                                    </span>
                                    {exp.branch?.name ? (
                                        <Badge className="text-[8px] gap-0.5">
                                            <Building2 className="w-2.5 h-2.5" />
                                            {exp.branch.name}
                                        </Badge>
                                    ) : null}
                                </div>
                                <p className="text-[9px] text-ink/35 mt-1 flex items-center gap-1.5 flex-wrap">
                                    <span className="truncate max-w-[220px]">{exp.description || "—"}</span>
                                    <span className="text-ink/20">·</span>
                                    <span className="inline-flex items-center gap-0.5 shrink-0">
                                        <CalendarDays className="w-2.5 h-2.5" />
                                        {String(exp.date).slice(0, 10)}
                                    </span>
                                </p>
                            </div>
                            <div className="text-end shrink-0">
                                <p className="text-[14px] font-black font-vazirmatn tabular-nums text-rose-500 leading-none">
                                    {formatNumber(Number(exp.amount))}
                                </p>
                                <p className="text-[8px] text-ink/30 mt-0.5">
                                    {exp.currency === "dinar" ? dinarSymbol : tomanSymbol}
                                </p>
                            </div>
                            <div className="flex items-center gap-0.5 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => openEdit(exp)}
                                    className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:text-primary hover:bg-primary/5"
                                    title={t("common.edit")}
                                    aria-label={t("common.edit")}
                                >
                                    <Pencil className="w-3.5 h-3.5" />
                                </button>
                                <button
                                    type="button"
                                    disabled={deletingId === exp.id}
                                    onClick={() => handleDelete(exp.id)}
                                    className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:text-rose-500 hover:bg-rose-50 disabled:opacity-40"
                                    title={t("common.delete")}
                                    aria-label={t("common.delete")}
                                >
                                    <Trash2 className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>
                    ))
                )}

                {hasMore && !search.trim() && !categoryFilter ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="w-full h-9 rounded-xl text-[11px]"
                        disabled={loadingMore}
                        onClick={() => fetchExpenses(true, page + 1, true)}
                    >
                        {loadingMore ? t("common.loading") : t("expenses.loadMore")}
                    </Button>
                ) : null}
            </div>

            {showForm && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink/30 backdrop-blur-sm"
                    onClick={(e) => e.target === e.currentTarget && !isSaving && closeForm()}
                >
                    <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl p-5 space-y-3">
                        <div className="flex justify-between items-center">
                            <h2 className="text-[15px] font-black font-vazirmatn">
                                {editingId ? t("expenses.form.editTitle") : t("expenses.form.title")}
                            </h2>
                            <button type="button" onClick={closeForm} disabled={isSaving}>
                                <X className="w-4 h-4 text-ink/40" />
                            </button>
                        </div>
                        {isAdmin ? (
                            <select
                                value={form.branch_id}
                                onChange={(e) => setForm((f) => ({ ...f, branch_id: e.target.value }))}
                                disabled={!!editingId}
                                className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15 disabled:opacity-50"
                            >
                                <option value="">{t("expenses.form.selectBranch")}</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        ) : (
                            <div className="w-full h-10 rounded-xl border border-ink/10 bg-parchment/30 px-3 flex items-center gap-2 text-[12px] font-vazirmatn text-ink/70">
                                <Building2 className="w-3.5 h-3.5 text-ink/35 shrink-0" />
                                <span className="truncate">
                                    {user?.branch?.name
                                        || branches.find((b) => Number(b.id) === userBranchId)?.name
                                        || "—"}
                                </span>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-2 items-end">
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder={t("expenses.form.amount")}
                                value={formatPriceDisplay(form.amount)}
                                onChange={(e) => setForm((f) => ({ ...f, amount: parsePriceDigits(e.target.value) }))}
                                className="h-12 bg-ink/[0.03] border-white focus:bg-white rounded-[10px] tabular-nums text-lg font-black text-ink"
                            />
                            <select
                                value={form.currency}
                                onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value as "toman" | "dinar" }))}
                                className="h-12 rounded-[10px] border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            >
                                <option value="toman">{t("common.toman")}</option>
                                <option value="dinar">{t("common.dinar")}</option>
                            </select>
                        </div>
                        <select
                            value={form.category}
                            onChange={(e) => setForm((f) => ({ ...f, category: e.target.value as CategoryKey }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                        >
                            {CATEGORY_KEYS.map((key) => (
                                <option key={key} value={key}>{t(`expenses.categories.${key}`)}</option>
                            ))}
                        </select>
                        <input
                            type="date"
                            value={form.date}
                            onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                        />
                        <textarea
                            placeholder={t("expenses.form.description")}
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                            className="w-full rounded-xl border border-ink/10 px-3 py-2 text-[12px] font-vazirmatn outline-none resize-none focus:ring-2 focus:ring-primary/15"
                            rows={2}
                        />
                        <Button
                            className="w-full h-10 rounded-xl font-black"
                            disabled={isSaving}
                            onClick={handleSubmit}
                        >
                            {isSaving
                                ? t("common.saving")
                                : editingId
                                    ? t("common.save")
                                    : t("expenses.add")}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
