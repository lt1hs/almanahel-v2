"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import { motion } from "framer-motion";
import {
    ArrowRight, FolderOpen, Plus, Pencil, Trash2, RefreshCw, Save, X,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";

interface CategoryRow {
    name: string;
    books_count: number;
}

export default function BookCategoriesPage() {
    const { t, formatNumber, isArabic } = useTranslation();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;
    const router = useRouter();
    const { user } = useAuth();
    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const [categories, setCategories] = useState<CategoryRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [newName, setNewName] = useState("");
    const [isAdding, setIsAdding] = useState(false);
    const [editingFrom, setEditingFrom] = useState<string | null>(null);
    const [editingTo, setEditingTo] = useState("");
    const [savingName, setSavingName] = useState<string | null>(null);
    const [deletingName, setDeletingName] = useState<string | null>(null);

    const applyResponse = (data: { categories?: CategoryRow[] }) => {
        setCategories(Array.isArray(data?.categories) ? data.categories : []);
    };

    const fetchCategories = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const data = await apiRequest("/book-categories");
            applyResponse(data);
        } catch (error) {
            console.error("Categories fetch failed:", error);
            notifyRef.current.error("categorySettings.loadError");
            setCategories([]);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchCategories();
    }, [fetchCategories]);

    const handleAdd = async () => {
        const name = newName.trim();
        if (!name) {
            notify.error("categorySettings.nameRequired");
            return;
        }
        setIsAdding(true);
        try {
            const data = await apiRequest("/book-categories", {
                method: "POST",
                body: JSON.stringify({ name }),
            });
            applyResponse(data);
            setNewName("");
            notify.success("categorySettings.added");
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("categorySettings.saveError");
        } finally {
            setIsAdding(false);
        }
    };

    const startEdit = (name: string) => {
        setEditingFrom(name);
        setEditingTo(name);
    };

    const cancelEdit = () => {
        setEditingFrom(null);
        setEditingTo("");
    };

    const handleRename = async () => {
        if (!editingFrom) return;
        const to = editingTo.trim();
        if (!to) {
            notify.error("categorySettings.nameRequired");
            return;
        }
        if (to === editingFrom) {
            cancelEdit();
            return;
        }
        setSavingName(editingFrom);
        try {
            const data = await apiRequest("/book-categories/rename", {
                method: "PUT",
                body: JSON.stringify({ from: editingFrom, to }),
            });
            applyResponse(data);
            cancelEdit();
            notify.success("categorySettings.renamed");
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("categorySettings.saveError");
        } finally {
            setSavingName(null);
        }
    };

    const handleDelete = async (row: CategoryRow) => {
        const clearBooks = row.books_count > 0;
        const confirmMsg = clearBooks
            ? t("categorySettings.confirmDeleteInUse", { count: formatNumber(row.books_count), name: row.name })
            : t("categorySettings.confirmDelete", { name: row.name });
        if (!confirm(confirmMsg)) return;

        setDeletingName(row.name);
        try {
            const qs = clearBooks ? "?clear_books=1" : "";
            const data = await apiRequest(
                `/book-categories/${encodeURIComponent(row.name)}${qs}`,
                { method: "DELETE" }
            );
            applyResponse(data);
            if (editingFrom === row.name) cancelEdit();
            notify.success("categorySettings.deleted");
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("categorySettings.saveError");
        } finally {
            setDeletingName(null);
        }
    };

    if (!isAdmin) {
        return (
            <div className="p-8 text-center">
                <p className="text-sm font-bold text-ink/40">{t("categorySettings.accessDenied")}</p>
                <Button variant="ghost" className="mt-4" onClick={() => router.push("/dashboard")}>
                    {t("common.back")}
                </Button>
            </div>
        );
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            className="space-y-5 pb-12 max-w-3xl"
        >
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-10 w-10 p-0 rounded-xl border border-ink/5 bg-white/70 shrink-0"
                        onClick={() => router.push("/dashboard/admin")}
                    >
                        <ArrowRight className={cn("w-4 h-4", isArabic && "rotate-180")} />
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-xl font-black font-vazirmatn text-ink truncate">
                            {t("categorySettings.title")}
                        </h1>
                        <p className="text-[10px] text-ink/35 font-bold mt-0.5">
                            {t("categorySettings.subtitle")}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    title={t("common.refresh")}
                    disabled={isLoading || isRefreshing}
                    onClick={() => fetchCategories(true)}
                    className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                >
                    <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing) && "animate-spin")} />
                </button>
            </div>

            <Card className="border border-white/70 bg-white/70 rounded-2xl">
                <CardContent className="p-4 space-y-3">
                    <p className="text-[10px] font-black text-ink/40 uppercase tracking-widest">
                        {t("categorySettings.addNew")}
                    </p>
                    <div className="flex flex-col sm:flex-row gap-2">
                        <input
                            type="text"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            onKeyDown={(e) => e.key === "Enter" && handleAdd()}
                            placeholder={t("categorySettings.namePlaceholder")}
                            className="flex-1 h-10 rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                        />
                        <Button
                            variant="primary"
                            size="sm"
                            className="h-10 px-4 rounded-xl text-[11px] shrink-0"
                            disabled={isAdding}
                            onClick={handleAdd}
                        >
                            <Plus className="w-3.5 h-3.5 ms-1.5" />
                            {isAdding ? t("common.saving") : t("categorySettings.add")}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div className="space-y-2">
                {isLoading && !categories.length ? (
                    Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="h-14 rounded-xl bg-parchment/20 animate-pulse" />
                    ))
                ) : categories.length === 0 ? (
                    <Card className="border border-white/70 bg-white/70 rounded-2xl">
                        <CardContent className="p-10 text-center text-ink/30 text-[12px] font-black">
                            {t("categorySettings.empty")}
                        </CardContent>
                    </Card>
                ) : (
                    categories.map((row) => {
                        const isEditing = editingFrom === row.name;
                        const busy = savingName === row.name || deletingName === row.name;
                        return (
                            <div
                                key={row.name}
                                className="rounded-xl border border-white/80 bg-white/75 px-3.5 py-3 flex items-center gap-3"
                            >
                                <div className="w-9 h-9 rounded-lg bg-primary/5 border border-primary/10 flex items-center justify-center shrink-0">
                                    <FolderOpen className="w-4 h-4 text-primary/70" />
                                </div>
                                <div className="flex-1 min-w-0">
                                    {isEditing ? (
                                        <input
                                            type="text"
                                            value={editingTo}
                                            onChange={(e) => setEditingTo(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === "Enter") handleRename();
                                                if (e.key === "Escape") cancelEdit();
                                            }}
                                            className="w-full h-9 rounded-lg border border-primary/30 bg-white px-2.5 text-[13px] font-vazirmatn font-black outline-none focus:ring-2 focus:ring-primary/15"
                                            autoFocus
                                        />
                                    ) : (
                                        <>
                                            <p className="text-[13px] font-black font-vazirmatn text-ink truncate">
                                                {row.name}
                                            </p>
                                            <p className="text-[9px] text-ink/35 mt-0.5">
                                                {t("categorySettings.booksCount", {
                                                    count: formatNumber(row.books_count),
                                                })}
                                            </p>
                                        </>
                                    )}
                                </div>
                                <div className="flex items-center gap-0.5 shrink-0">
                                    {isEditing ? (
                                        <>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={handleRename}
                                                className="h-8 w-8 flex items-center justify-center rounded-lg text-primary hover:bg-primary/5 disabled:opacity-40"
                                                title={t("common.save")}
                                            >
                                                <Save className="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={cancelEdit}
                                                className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:bg-ink/5"
                                                title={t("common.cancel")}
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        </>
                                    ) : (
                                        <>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => startEdit(row.name)}
                                                className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:text-primary hover:bg-primary/5 disabled:opacity-40"
                                                title={t("common.edit")}
                                            >
                                                <Pencil className="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => handleDelete(row)}
                                                className="h-8 w-8 flex items-center justify-center rounded-lg text-ink/30 hover:text-rose-500 hover:bg-rose-50 disabled:opacity-40"
                                                title={t("common.delete")}
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </motion.div>
    );
}
