"use client";

import React, { useState, useEffect, useMemo, useCallback } from "react";
import { AnimatePresence, motion } from "framer-motion";
import {
    ArrowDown, ArrowUp, ArrowRight, ArrowLeft, BookOpen, Calendar, Check,
    Loader2, Minus, Phone, Plus, Search, User, Package, Store,
    AlertTriangle, Scale, MoreHorizontal, Truck,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Input } from "@/components/ui/Input";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";

export interface WarehouseLogFormData {
    book_id: string;
    quantity: string;
    handler_name: string;
    handler_phone: string;
    reason: string;
    notes: string;
    log_date: string;
    /** When out + transferred_to_branch — destination POS */
    to_branch_id?: string;
    /** Creates a distribution transfer instead of a raw warehouse log */
    as_transfer?: boolean;
}

export interface WarehouseLogRecord {
    id: number;
    book_id: number;
    direction: "in" | "out";
    quantity: number;
    handler_name: string;
    handler_phone?: string | null;
    reason: string;
    notes?: string | null;
    log_date: string;
    related_transfer_id?: number | null;
    book?: { id: number; title: string; author?: string; isbn?: string };
}

export interface BranchOption {
    id: number;
    name: string;
    city?: string;
    type?: string;
    status?: string;
}

interface PurposeOption {
    reason: string;
    asTransfer?: boolean;
    icon: React.ElementType;
    titleKey: string;
    descKey: string;
}

interface WarehouseLogFormProps {
    direction: "in" | "out";
    inventory: any[];
    branches?: BranchOption[];
    isSubmitting: boolean;
    editLog?: WarehouseLogRecord | null;
    branchName?: string;
    branchId?: number;
    onBack: () => void;
    onSubmit: (data: WarehouseLogFormData) => void;
}

function formatLogDate(value: string | undefined): string {
    if (!value) return new Date().toISOString().split("T")[0];
    return value.split("T")[0];
}

export function WarehouseLogForm({
    direction,
    inventory,
    branches = [],
    isSubmitting,
    editLog,
    branchName,
    branchId,
    onBack,
    onSubmit,
}: WarehouseLogFormProps) {
    const { t, formatNumber } = useTranslation();
    const { user } = useAuth();
    const isEditMode = Boolean(editLog);
    const isIn = direction === "in";

    const [step, setStep] = useState(0);
    const [bookSearch, setBookSearch] = useState("");
    const [searchBooks, setSearchBooks] = useState<any[]>([]);
    const [isSearchingBooks, setIsSearchingBooks] = useState(false);
    const [asTransfer, setAsTransfer] = useState(!isIn);
    const [toBranchId, setToBranchId] = useState("");
    const [form, setForm] = useState<WarehouseLogFormData>({
        book_id: "",
        quantity: "1",
        handler_name: "",
        handler_phone: "",
        reason: isIn ? "received_from_supplier" : "transferred_to_branch",
        notes: "",
        log_date: new Date().toISOString().split("T")[0],
    });

    const purposeOptions: PurposeOption[] = useMemo(() => {
        if (isIn) {
            return [
                {
                    reason: "received_from_supplier",
                    icon: Package,
                    titleKey: "warehouse.purpose.inSupplier",
                    descKey: "warehouse.purpose.inSupplierDesc",
                },
                {
                    reason: "returned_from_branch",
                    icon: Store,
                    titleKey: "warehouse.purpose.inReturn",
                    descKey: "warehouse.purpose.inReturnDesc",
                },
                {
                    reason: "adjustment",
                    icon: Scale,
                    titleKey: "warehouse.purpose.inAdjust",
                    descKey: "warehouse.purpose.inAdjustDesc",
                },
                {
                    reason: "other",
                    icon: MoreHorizontal,
                    titleKey: "warehouse.purpose.other",
                    descKey: "warehouse.purpose.otherDesc",
                },
            ];
        }
        return [
            {
                reason: "transferred_to_branch",
                asTransfer: true,
                icon: Truck,
                titleKey: "warehouse.purpose.outTransfer",
                descKey: "warehouse.purpose.outTransferDesc",
            },
            {
                reason: "adjustment",
                asTransfer: false,
                icon: Scale,
                titleKey: "warehouse.purpose.outAdjust",
                descKey: "warehouse.purpose.outAdjustDesc",
            },
            {
                reason: "other",
                asTransfer: false,
                icon: MoreHorizontal,
                titleKey: "warehouse.purpose.other",
                descKey: "warehouse.purpose.otherDesc",
            },
        ];
    }, [isIn]);

    const stepKeys = useMemo(() => {
        if (isEditMode) return ["details", "confirm"] as const;
        return ["book", "purpose", "details", "confirm"] as const;
    }, [isEditMode]);

    const stepLabels = useMemo(() => ({
        book: t("warehouse.steps.book"),
        purpose: t("warehouse.steps.purpose"),
        details: t("warehouse.steps.details"),
        confirm: t("warehouse.steps.confirm"),
    }), [t]);

    const resetForm = useCallback(() => {
        setBookSearch("");
        setSearchBooks([]);
        setAsTransfer(!isIn);
        setToBranchId("");
        setStep(0);
        setForm({
            book_id: "",
            quantity: "1",
            handler_name: user?.name || "",
            handler_phone: "",
            reason: isIn ? "received_from_supplier" : "transferred_to_branch",
            notes: "",
            log_date: new Date().toISOString().split("T")[0],
        });
    }, [isIn, user?.name]);

    useEffect(() => {
        if (editLog) {
            setBookSearch(editLog.book?.title || "");
            setSearchBooks([]);
            setAsTransfer(false);
            setToBranchId("");
            setStep(0);
            setForm({
                book_id: String(editLog.book_id),
                quantity: String(editLog.quantity),
                handler_name: editLog.handler_name || "",
                handler_phone: editLog.handler_phone || "",
                reason: editLog.reason,
                notes: editLog.notes || "",
                log_date: formatLogDate(editLog.log_date),
            });
            return;
        }
        resetForm();
    }, [editLog, resetForm]);

    const destBranches = useMemo(
        () => branches.filter((b) => b.type === "store" && b.id !== branchId && (b.status === "active" || !b.status)),
        [branches, branchId]
    );

    const outBookOptions = useMemo(
        () => inventory.filter((item) => item.quantity > 0 && item.book?.id),
        [inventory]
    );

    const inQuickPicks = useMemo(() => inventory.filter((item) => item.book?.id).slice(0, 8), [inventory]);

    const filteredOutBooks = useMemo(() => {
        const q = bookSearch.trim().toLowerCase();
        if (!q) return outBookOptions;
        return outBookOptions.filter(
            (item) =>
                item.book.title?.toLowerCase().includes(q) ||
                item.book.author?.toLowerCase().includes(q) ||
                item.book.isbn?.toLowerCase().includes(q)
        );
    }, [outBookOptions, bookSearch]);

    const selectedInventoryItem = useMemo(
        () => inventory.find((item) => String(item.book?.id) === form.book_id),
        [inventory, form.book_id]
    );

    const selectedSearchBook = useMemo(
        () => searchBooks.find((b) => String(b.id) === form.book_id),
        [searchBooks, form.book_id]
    );

    const selectedBook = isEditMode
        ? editLog?.book
        : (selectedInventoryItem?.book || selectedSearchBook);

    const editMaxOutQty = isEditMode && !isIn && editLog
        ? (selectedInventoryItem?.quantity ?? 0) + editLog.quantity
        : undefined;
    const maxQty = isEditMode
        ? (isIn ? undefined : editMaxOutQty)
        : (!isIn && selectedInventoryItem ? selectedInventoryItem.quantity : undefined);

    const qtyNum = Math.max(1, parseInt(form.quantity, 10) || 1);
    const stockAfter = !isIn && maxQty != null ? Math.max(0, maxQty - qtyNum) : null;
    const selectedPurpose = purposeOptions.find((p) => p.reason === form.reason);
    const selectedDest = destBranches.find((b) => String(b.id) === toBranchId);
    const needsDest = !isIn && !isEditMode && asTransfer && form.reason === "transferred_to_branch";

    useEffect(() => {
        if (!isIn || isEditMode) return;
        const query = bookSearch.trim();
        if (query.length < 2) {
            setSearchBooks([]);
            return;
        }
        const timer = setTimeout(async () => {
            setIsSearchingBooks(true);
            try {
                const data = await apiRequest(`/books?search=${encodeURIComponent(query)}`);
                setSearchBooks(Array.isArray(data) ? data.slice(0, 24) : []);
            } catch {
                setSearchBooks([]);
            } finally {
                setIsSearchingBooks(false);
            }
        }, 320);
        return () => clearTimeout(timer);
    }, [isIn, isEditMode, bookSearch]);

    const selectBook = (bookId: string, title: string) => {
        setForm((f) => ({ ...f, book_id: bookId, quantity: "1" }));
        setBookSearch(title);
    };

    const selectPurpose = (opt: PurposeOption) => {
        setForm((f) => ({ ...f, reason: opt.reason }));
        setAsTransfer(Boolean(opt.asTransfer));
        if (!opt.asTransfer) setToBranchId("");
    };

    const adjustQty = (delta: number) => {
        setForm((f) => {
            const current = parseInt(f.quantity, 10) || 1;
            const next = Math.max(1, maxQty ? Math.min(maxQty, current + delta) : current + delta);
            return { ...f, quantity: String(next) };
        });
    };

    const canNext = () => {
        const key = stepKeys[step];
        if (key === "book") return Boolean(form.book_id);
        if (key === "purpose") return Boolean(form.reason);
        if (key === "details") {
            if (!form.handler_name || qtyNum < 1) return false;
            if (needsDest && !toBranchId) return false;
            if (maxQty != null && qtyNum > maxQty) return false;
            return true;
        }
        return true;
    };

    const handleSubmit = () => {
        onSubmit({
            ...form,
            to_branch_id: needsDest ? toBranchId : undefined,
            as_transfer: needsDest,
        });
    };

    const accent = isIn
        ? { soft: "bg-emerald-500/[0.06] border-emerald-500/15", icon: "bg-emerald-500/15 text-emerald-600", btn: "bg-emerald-600 hover:bg-emerald-700 shadow-emerald-600/20", chip: "bg-emerald-50 text-emerald-700 border-emerald-100" }
        : { soft: "bg-rose-500/[0.06] border-rose-500/15", icon: "bg-rose-500/15 text-rose-600", btn: "bg-rose-600 hover:bg-rose-700 shadow-rose-600/20", chip: "bg-rose-50 text-rose-700 border-rose-100" };

    const currentKey = stepKeys[step];
    const isLastStep = step >= stepKeys.length - 1;

    const renderPrimary = (className?: string) => (
        isLastStep ? (
            <Button
                className={cn("h-9 px-4 rounded-xl font-black text-[11px] text-white", accent.btn, className)}
                disabled={!canNext() || isSubmitting}
                onClick={handleSubmit}
            >
                {isSubmitting
                    ? t("common.submitting")
                    : isEditMode
                        ? t("common.saveChanges")
                        : needsDest
                            ? t("warehouse.purpose.startTransfer")
                            : t(isIn ? "warehouse.logIn" : "warehouse.logOut")}
            </Button>
        ) : (
            <Button
                className={cn("h-9 px-4 rounded-xl font-black text-[11px] text-white", accent.btn, className)}
                disabled={!canNext()}
                onClick={() => setStep(step + 1)}
            >
                {t("common.next")}
                <ArrowLeft className="w-3.5 h-3.5 me-1.5" />
            </Button>
        )
    );

    return (
        <div className="space-y-4 pb-8">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <button
                        type="button"
                        onClick={step > 0 ? () => setStep(step - 1) : onBack}
                        className="h-10 w-10 rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm flex items-center justify-center shrink-0"
                        aria-label={t("common.back")}
                    >
                        <ArrowRight className="w-4 h-4 text-ink/50" />
                    </button>
                    <div className={cn(
                        "w-10 h-10 rounded-xl border border-white shadow-sm flex items-center justify-center shrink-0",
                        isIn ? "bg-emerald-50" : "bg-rose-50"
                    )}>
                        {isIn
                            ? <ArrowDown className="w-5 h-5 text-emerald-600" />
                            : <ArrowUp className="w-5 h-5 text-rose-600" />}
                    </div>
                    <div className="min-w-0">
                        <h1 className="text-xl font-black font-vazirmatn text-ink truncate">
                            {t(isEditMode ? "warehouse.modal.titleEdit" : isIn ? "warehouse.modal.titleIn" : "warehouse.modal.titleOut")}
                        </h1>
                        <p className="text-[10px] text-ink/35 font-bold mt-0.5 truncate">
                            {branchName || t("nav.warehouse")} · {t("warehouse.subtitle")}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2 items-center">
                    <Badge className={cn("text-[8px] font-black border", accent.chip)}>
                        {isEditMode ? t("common.edit") : isIn ? t("warehouse.logIn") : t("warehouse.logOut")}
                    </Badge>
                    {step > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-9 px-3 rounded-xl text-[11px] font-black"
                            onClick={() => setStep(step - 1)}
                        >
                            {t("common.previous")}
                        </Button>
                    )}
                    {renderPrimary()}
                </div>
            </div>

            <div className="flex items-center gap-1 p-1 bg-white/50 border border-white/70 rounded-xl w-fit max-w-full overflow-x-auto">
                {stepKeys.map((key, i) => {
                    const done = i < step;
                    const active = i === step;
                    return (
                        <button
                            key={key}
                            type="button"
                            disabled={i > step}
                            onClick={() => i < step && setStep(i)}
                            className={cn(
                                "flex items-center gap-1.5 px-3 py-2 rounded-[9px] text-[10px] font-black whitespace-nowrap transition-all",
                                active ? "bg-white shadow-sm text-primary" :
                                done ? "text-ink/55 hover:text-ink/70" :
                                "text-ink/25"
                            )}
                        >
                            <span className={cn(
                                "min-w-[18px] h-[18px] rounded-md flex items-center justify-center text-[8px] tabular-nums",
                                active || done
                                    ? (isIn ? "bg-emerald-500/10 text-emerald-700" : "bg-rose-500/10 text-rose-700")
                                    : "bg-ink/5 text-ink/30"
                            )}>
                                {done ? <Check className="w-3 h-3" /> : formatNumber(i + 1)}
                            </span>
                            {stepLabels[key]}
                        </button>
                    );
                })}
            </div>

            <div className="grid gap-4 lg:grid-cols-12 lg:items-start">
                <div className="lg:col-span-8">
                    <div className="rounded-2xl border border-white/70 bg-white/70 overflow-hidden">
                        <AnimatePresence mode="wait">
                            <motion.div
                                key={currentKey}
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -4 }}
                                transition={{ duration: 0.15 }}
                                className="p-5 md:p-6"
                            >
                                    {currentKey === "book" && (
                                        <div className="space-y-4">
                                            <div>
                                                <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("warehouse.steps.bookTitle")}</h2>
                                                <p className="text-[11px] text-ink/40 mt-1">{t(isIn ? "warehouse.form.searchBookHint" : "warehouse.form.searchOutHint")}</p>
                                            </div>
                                            <div className="relative">
                                                <Search className="absolute inset-y-0 start-3 my-auto w-4 h-4 text-ink/25 pointer-events-none" />
                                                <input
                                                    value={bookSearch}
                                                    onChange={(e) => {
                                                        setBookSearch(e.target.value);
                                                        setForm((f) => ({ ...f, book_id: "" }));
                                                    }}
                                                    placeholder={t(isIn ? "warehouse.form.searchBookHint" : "warehouse.form.searchOutHint")}
                                                    className="w-full h-12 bg-parchment/40 border border-ink/8 focus:border-primary/30 focus:bg-white rounded-xl ps-10 pe-10 text-[13px] font-vazirmatn outline-none"
                                                />
                                                {isSearchingBooks && <Loader2 className="absolute inset-y-0 end-3 my-auto w-4 h-4 animate-spin text-primary" />}
                                            </div>

                                            {isIn && bookSearch.trim().length < 2 && inQuickPicks.length > 0 && (
                                                <div>
                                                    <p className="text-[9px] font-black text-ink/30 uppercase tracking-wider mb-2">{t("warehouse.form.quickPicks")}</p>
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                        {inQuickPicks.map((item) => (
                                                            <BookCard
                                                                key={item.book.id}
                                                                title={item.book.title}
                                                                subtitle={item.book.author}
                                                                meta={`${formatNumber(item.quantity)} ${t("common.quantity")}`}
                                                                selected={form.book_id === String(item.book.id)}
                                                                onClick={() => selectBook(String(item.book.id), item.book.title)}
                                                                accentIn={isIn}
                                                            />
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {(isIn ? bookSearch.trim().length >= 2 : true) && (
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-[380px] overflow-y-auto pe-1">
                                                    {(isIn ? searchBooks : filteredOutBooks).length === 0 ? (
                                                        <div className="sm:col-span-2 py-12 text-center text-[12px] text-ink/35 font-vazirmatn">
                                                            {!isIn && outBookOptions.length === 0
                                                                ? t("warehouse.empty.inventory")
                                                                : t("common.noResults")}
                                                        </div>
                                                    ) : isIn ? (
                                                        searchBooks.map((book) => (
                                                            <BookCard
                                                                key={book.id}
                                                                title={book.title}
                                                                subtitle={book.author}
                                                                meta={book.isbn}
                                                                selected={form.book_id === String(book.id)}
                                                                onClick={() => selectBook(String(book.id), book.title)}
                                                                accentIn
                                                            />
                                                        ))
                                                    ) : (
                                                        filteredOutBooks.map((item) => (
                                                            <BookCard
                                                                key={item.book.id}
                                                                title={item.book.title}
                                                                subtitle={item.book.author}
                                                                meta={`${formatNumber(item.quantity)} ${t("common.quantity")}`}
                                                                selected={form.book_id === String(item.book.id)}
                                                                onClick={() => selectBook(String(item.book.id), item.book.title)}
                                                                lowStock={item.quantity < 20}
                                                            />
                                                        ))
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {currentKey === "purpose" && (
                                        <div className="space-y-4">
                                            <div>
                                                <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("warehouse.steps.purposeTitle")}</h2>
                                                <p className="text-[11px] text-ink/40 mt-1">{t("warehouse.steps.purposeHint")}</p>
                                            </div>
                                            <div className="grid gap-2.5">
                                                {purposeOptions.map((opt) => {
                                                    const active = form.reason === opt.reason && asTransfer === Boolean(opt.asTransfer);
                                                    const Icon = opt.icon;
                                                    return (
                                                        <button
                                                            key={`${opt.reason}-${opt.asTransfer ? "t" : "l"}`}
                                                            type="button"
                                                            onClick={() => selectPurpose(opt)}
                                                            className={cn(
                                                                "text-start rounded-2xl border px-4 py-4 transition-all flex gap-3",
                                                                active
                                                                    ? isIn
                                                                        ? "border-emerald-300 bg-emerald-50/70 shadow-sm"
                                                                        : "border-rose-300 bg-rose-50/70 shadow-sm"
                                                                    : "border-ink/8 bg-parchment/20 hover:bg-white hover:border-ink/15"
                                                            )}
                                                        >
                                                            <div className={cn(
                                                                "w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border",
                                                                active ? accent.icon + " border-transparent" : "bg-white border-ink/8 text-ink/40"
                                                            )}>
                                                                <Icon className="w-5 h-5" />
                                                            </div>
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex items-center gap-2">
                                                                    <p className="text-[13px] font-black font-vazirmatn text-ink">{t(opt.titleKey)}</p>
                                                                    {opt.asTransfer && (
                                                                        <Badge className="text-[7px] font-black bg-sky-50 text-sky-700 border-sky-100">
                                                                            {t("warehouse.purpose.transferBadge")}
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                <p className="text-[11px] text-ink/45 font-vazirmatn mt-1 leading-relaxed">{t(opt.descKey)}</p>
                                                            </div>
                                                            {active && <Check className={cn("w-4 h-4 shrink-0 mt-1", isIn ? "text-emerald-600" : "text-rose-600")} />}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}

                                    {currentKey === "details" && (
                                        <div className="space-y-5">
                                            <div>
                                                <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("warehouse.steps.detailsTitle")}</h2>
                                                <p className="text-[11px] text-ink/40 mt-1">{t("warehouse.steps.detailsHint")}</p>
                                            </div>

                                            {needsDest && (
                                                <div>
                                                    <p className="text-[10px] font-black text-ink/40 uppercase tracking-wider mb-2">{t("warehouse.form.destination")}</p>
                                                    {destBranches.length === 0 ? (
                                                        <p className="text-[11px] text-rose-500 font-bold">{t("warehouse.errors.noDestBranches")}</p>
                                                    ) : (
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                            {destBranches.map((b) => (
                                                                <button
                                                                    key={b.id}
                                                                    type="button"
                                                                    onClick={() => setToBranchId(String(b.id))}
                                                                    className={cn(
                                                                        "text-start rounded-xl border px-3.5 py-3 transition-all",
                                                                        toBranchId === String(b.id)
                                                                            ? "border-rose-300 bg-rose-50/80"
                                                                            : "border-ink/8 bg-parchment/20 hover:bg-white"
                                                                    )}
                                                                >
                                                                    <p className="text-[12px] font-black font-vazirmatn text-ink">{b.name}</p>
                                                                    {b.city && <p className="text-[10px] text-ink/40 mt-0.5">{b.city}</p>}
                                                                </button>
                                                            ))}
                                                        </div>
                                                    )}
                                                    <p className="text-[10px] text-sky-700/80 font-vazirmatn mt-2 leading-relaxed">
                                                        {t("warehouse.purpose.transferNote")}
                                                    </p>
                                                </div>
                                            )}

                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="space-y-1.5">
                                                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">{t("warehouse.form.quantity")}</label>
                                                    <div className="flex items-center h-12 bg-parchment/30 border border-ink/8 rounded-xl overflow-hidden">
                                                        <button type="button" onClick={() => adjustQty(-1)} className="w-11 h-full flex items-center justify-center text-ink/40 hover:bg-white">
                                                            <Minus className="w-4 h-4" />
                                                        </button>
                                                        <input
                                                            type="number"
                                                            min={1}
                                                            max={maxQty}
                                                            value={form.quantity}
                                                            onChange={(e) => setForm((f) => ({ ...f, quantity: e.target.value }))}
                                                            className="flex-1 h-full text-center text-lg font-black font-vazirmatn bg-transparent outline-none"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => adjustQty(1)}
                                                            disabled={maxQty !== undefined && qtyNum >= maxQty}
                                                            className="w-11 h-full flex items-center justify-center text-ink/40 hover:bg-white disabled:opacity-30"
                                                        >
                                                            <Plus className="w-4 h-4" />
                                                        </button>
                                                    </div>
                                                    {!isIn && maxQty != null && (
                                                        <p className="text-[10px] text-ink/40 px-1">
                                                            {t("warehouse.form.availableStock")}: {formatNumber(maxQty)}
                                                            {stockAfter != null && (
                                                                <span className="text-ink/55"> · {t("warehouse.form.stockAfter")}: {formatNumber(stockAfter)}</span>
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">{t("warehouse.form.date")}</label>
                                                    <div className="relative">
                                                        <Calendar className="absolute inset-y-0 start-3 my-auto w-4 h-4 text-ink/25 pointer-events-none" />
                                                        <input
                                                            type="date"
                                                            value={form.log_date}
                                                            onChange={(e) => setForm((f) => ({ ...f, log_date: e.target.value }))}
                                                            className="w-full h-12 bg-parchment/30 border border-ink/8 focus:border-primary/30 rounded-xl ps-10 pe-3 text-[13px] font-vazirmatn outline-none"
                                                        />
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <Input
                                                    label={isIn ? t("warehouse.form.deliverer") : t("warehouse.form.receiver")}
                                                    value={form.handler_name}
                                                    onChange={(e) => setForm((f) => ({ ...f, handler_name: e.target.value }))}
                                                    icon={<User className="w-4 h-4" />}
                                                    className="h-11 bg-parchment/30 border-ink/8 rounded-xl"
                                                />
                                                <Input
                                                    label={t("warehouse.form.phone")}
                                                    value={form.handler_phone}
                                                    onChange={(e) => setForm((f) => ({ ...f, handler_phone: e.target.value }))}
                                                    icon={<Phone className="w-4 h-4" />}
                                                    className="h-11 bg-parchment/30 border-ink/8 rounded-xl"
                                                />
                                            </div>

                                            <div className="space-y-1.5">
                                                <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">{t("warehouse.form.notes")}</label>
                                                <textarea
                                                    rows={3}
                                                    value={form.notes}
                                                    onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
                                                    className="w-full rounded-xl border border-ink/8 bg-parchment/30 px-4 py-3 text-[13px] font-vazirmatn outline-none resize-none focus:border-primary/30 focus:bg-white"
                                                    placeholder={t(needsDest ? "warehouse.form.transferNotesPlaceholder" : "warehouse.form.notesPlaceholder")}
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentKey === "confirm" && (
                                        <div className="space-y-4">
                                            <div>
                                                <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("warehouse.steps.confirmTitle")}</h2>
                                                <p className="text-[11px] text-ink/40 mt-1">{t("warehouse.steps.confirmHint")}</p>
                                            </div>
                                            <div className={cn("rounded-2xl border p-4 space-y-3", accent.soft)}>
                                                <ConfirmRow label={t("gifts.form.book")} value={selectedBook?.title || "—"} />
                                                <ConfirmRow label={t("warehouse.form.quantity")} value={`${formatNumber(qtyNum)} ${t("common.quantity")}`} />
                                                {selectedPurpose && (
                                                    <ConfirmRow label={t("warehouse.form.reasonSection")} value={t(selectedPurpose.titleKey)} />
                                                )}
                                                {selectedDest && (
                                                    <ConfirmRow label={t("warehouse.form.destination")} value={selectedDest.name} />
                                                )}
                                                <ConfirmRow
                                                    label={isIn ? t("warehouse.form.deliverer") : t("warehouse.form.receiver")}
                                                    value={form.handler_name || "—"}
                                                />
                                                <ConfirmRow label={t("warehouse.form.date")} value={form.log_date} />
                                                {!isIn && stockAfter != null && (
                                                    <ConfirmRow label={t("warehouse.form.stockAfter")} value={formatNumber(stockAfter)} />
                                                )}
                                            </div>
                                            {needsDest && (
                                                <div className="flex items-start gap-2 rounded-xl border border-sky-100 bg-sky-50/70 px-3.5 py-3">
                                                    <Truck className="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
                                                    <p className="text-[11px] text-sky-800/80 font-vazirmatn leading-relaxed">
                                                        {t("warehouse.purpose.transferConfirm")}
                                                    </p>
                                                </div>
                                            )}
                                            {!isIn && maxQty != null && qtyNum > maxQty && (
                                                <div className="flex items-center gap-2 text-rose-600 text-[11px] font-bold">
                                                    <AlertTriangle className="w-4 h-4" />
                                                    {t("warehouse.errors.insufficientStock")}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </motion.div>
                            </AnimatePresence>
                        </div>
                    </div>

                    <aside className="lg:col-span-4 space-y-3">
                        <div className="rounded-2xl border border-white/70 bg-white/70 p-5 space-y-4">
                            <div className={cn("w-10 h-10 rounded-xl flex items-center justify-center border border-white shadow-sm", accent.icon)}>
                                {isIn ? <ArrowDown className="w-5 h-5" /> : <ArrowUp className="w-5 h-5" />}
                            </div>
                            <div>
                                <p className="text-[10px] font-black text-ink/35 uppercase tracking-widest">{t("warehouse.page.summary")}</p>
                                <p className="text-[14px] font-black font-vazirmatn text-ink mt-1 line-clamp-2">
                                    {selectedBook?.title || t("warehouse.page.pickBook")}
                                </p>
                                {selectedBook?.author && (
                                    <p className="text-[11px] text-ink/40 mt-0.5">{selectedBook.author}</p>
                                )}
                            </div>
                            <div className="space-y-2 text-[11px] font-vazirmatn">
                                <SummaryLine label={t("warehouse.steps.purpose")} value={selectedPurpose ? t(selectedPurpose.titleKey) : "—"} />
                                {selectedDest && <SummaryLine label={t("warehouse.form.destination")} value={selectedDest.name} />}
                                <SummaryLine label={t("warehouse.form.quantity")} value={form.book_id ? formatNumber(qtyNum) : "—"} />
                                {!isIn && maxQty != null && (
                                    <SummaryLine label={t("warehouse.form.availableStock")} value={formatNumber(maxQty)} />
                                )}
                            </div>
                            {needsDest && (
                                <p className="text-[10px] leading-relaxed text-sky-700/80 bg-sky-50 border border-sky-100 rounded-xl px-3 py-2">
                                    {t("warehouse.purpose.transferBadgeHint")}
                                </p>
                            )}
                        </div>

                        <div className="rounded-2xl border border-white/70 bg-white/70 p-3 flex flex-col gap-2">
                            <Button
                                variant="ghost"
                                className="h-10 rounded-xl font-black text-[11px] justify-center"
                                onClick={step > 0 ? () => setStep(step - 1) : onBack}
                            >
                                {step > 0 ? t("common.previous") : t("common.cancel")}
                            </Button>
                            {renderPrimary("w-full h-10")}
                        </div>
                    </aside>
                </div>
        </div>
    );
}

function BookCard({
    title,
    subtitle,
    meta,
    selected,
    onClick,
    lowStock,
    accentIn,
}: {
    title: string;
    subtitle?: string;
    meta?: string;
    selected: boolean;
    onClick: () => void;
    lowStock?: boolean;
    accentIn?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                "w-full text-start rounded-xl border px-3.5 py-3 transition-all flex gap-3",
                selected
                    ? accentIn
                        ? "border-emerald-300 bg-emerald-50/70 shadow-sm"
                        : "border-rose-300 bg-rose-50/70 shadow-sm"
                    : "border-ink/8 bg-parchment/25 hover:bg-white hover:border-ink/15"
            )}
        >
            <div className={cn(
                "w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border",
                selected
                    ? accentIn ? "bg-emerald-500/15 border-emerald-200 text-emerald-700" : "bg-rose-500/15 border-rose-200 text-rose-700"
                    : "bg-white border-ink/8 text-ink/25"
            )}>
                {selected ? <Check className="w-4 h-4" /> : <BookOpen className="w-4 h-4" />}
            </div>
            <div className="min-w-0 flex-1">
                <p className={cn("text-[12px] font-vazirmatn truncate", selected ? "font-black text-ink" : "font-bold text-ink")}>{title}</p>
                {(subtitle || meta) && (
                    <p className="text-[10px] text-ink/40 mt-0.5 truncate">{[subtitle, meta].filter(Boolean).join(" · ")}</p>
                )}
            </div>
            {lowStock && (
                <Badge className="text-[7px] font-black bg-rose-500/10 text-rose-500 border-rose-500/20 shrink-0 self-start">
                    {t("inventory.lowStock")}
                </Badge>
            )}
        </button>
    );
}

function ConfirmRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-[10px] font-bold text-ink/40 shrink-0">{label}</span>
            <span className="text-[12px] font-black font-vazirmatn text-ink text-end">{value}</span>
        </div>
    );
}

function SummaryLine({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-2 border-b border-ink/[0.04] pb-2 last:border-0 last:pb-0">
            <span className="text-ink/35 font-bold">{label}</span>
            <span className="font-black text-ink truncate max-w-[60%] text-end">{value}</span>
        </div>
    );
}
