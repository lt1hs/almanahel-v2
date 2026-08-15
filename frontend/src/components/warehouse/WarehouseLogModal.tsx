"use client";

import React, { useState, useEffect, useMemo, useCallback } from "react";
import {
    ArrowDown, ArrowUp, BookOpen, Calendar, Check, Hash, Loader2,
    Minus, Phone, Plus, Search, User, FileText,
} from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Input } from "@/components/ui/Input";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";

const REASON_KEYS: Record<string, string> = {
    received_from_supplier: "warehouse.logTypes.received",
    transferred_to_branch: "warehouse.logTypes.transferred",
    returned_from_branch: "warehouse.logTypes.returned",
    adjustment: "warehouse.logTypes.adjustment",
    other: "warehouse.logTypes.other",
};

const IN_REASONS = ["received_from_supplier", "returned_from_branch", "adjustment", "other"] as const;
const OUT_REASONS = ["transferred_to_branch", "adjustment", "other"] as const;

export interface WarehouseLogFormData {
    book_id: string;
    quantity: string;
    handler_name: string;
    handler_phone: string;
    reason: string;
    notes: string;
    log_date: string;
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
    book?: { id: number; title: string; author?: string };
}

interface WarehouseLogModalProps {
    isOpen: boolean;
    onClose: () => void;
    direction: "in" | "out";
    inventory: any[];
    isSubmitting: boolean;
    editLog?: WarehouseLogRecord | null;
    onSubmit: (data: WarehouseLogFormData) => void;
}

function formatLogDate(value: string | undefined): string {
    if (!value) return new Date().toISOString().split("T")[0];
    return value.split("T")[0];
}

function SectionLabel({ icon: Icon, label }: { icon: React.ElementType; label: string }) {
    return (
        <div className="flex items-center gap-2 mb-3">
            <div className="w-7 h-7 rounded-lg bg-ink/[0.04] flex items-center justify-center">
                <Icon className="w-3.5 h-3.5 text-ink/40" />
            </div>
            <span className="text-[10px] font-black text-ink/45 uppercase tracking-widest font-vazirmatn">{label}</span>
        </div>
    );
}

export function WarehouseLogModal({
    isOpen,
    onClose,
    direction,
    inventory,
    isSubmitting,
    editLog,
    onSubmit,
}: WarehouseLogModalProps) {
    const { t, formatNumber } = useTranslation();
    const { user } = useAuth();
    const isEditMode = Boolean(editLog);
    const isIn = direction === "in";

    const [bookSearch, setBookSearch] = useState("");
    const [searchBooks, setSearchBooks] = useState<any[]>([]);
    const [isSearchingBooks, setIsSearchingBooks] = useState(false);
    const [form, setForm] = useState<WarehouseLogFormData>({
        book_id: "",
        quantity: "1",
        handler_name: "",
        handler_phone: "",
        reason: "received_from_supplier",
        notes: "",
        log_date: new Date().toISOString().split("T")[0],
    });

    const resetForm = useCallback(() => {
        setBookSearch("");
        setSearchBooks([]);
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
        if (!isOpen) return;

        if (editLog) {
            setBookSearch(editLog.book?.title || "");
            setSearchBooks([]);
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
    }, [isOpen, editLog, resetForm]);

    const outBookOptions = useMemo(
        () => inventory.filter((item) => item.quantity > 0 && item.book?.id),
        [inventory]
    );

    const inQuickPicks = useMemo(() => inventory.filter((item) => item.book?.id).slice(0, 6), [inventory]);

    const filteredOutBooks = useMemo(() => {
        const q = bookSearch.trim().toLowerCase();
        if (!q) return outBookOptions;
        return outBookOptions.filter(
            (item) =>
                item.book.title?.toLowerCase().includes(q) ||
                item.book.author?.toLowerCase().includes(q)
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

    const selectedBook = isEditMode ? editLog?.book : (selectedInventoryItem?.book || selectedSearchBook);
    const editMaxOutQty = isEditMode && !isIn && editLog
        ? (selectedInventoryItem?.quantity ?? 0) + editLog.quantity
        : undefined;
    const maxQty = isEditMode
        ? (isIn ? undefined : editMaxOutQty)
        : (!isIn && selectedInventoryItem ? selectedInventoryItem.quantity : undefined);
    const reasonOptions = isIn ? IN_REASONS : OUT_REASONS;

    useEffect(() => {
        if (!isOpen || !isIn || isEditMode) return;
        const query = bookSearch.trim();
        if (query.length < 2) {
            setSearchBooks([]);
            return;
        }

        const timer = setTimeout(async () => {
            setIsSearchingBooks(true);
            try {
                const data = await apiRequest(`/books?search=${encodeURIComponent(query)}`);
                setSearchBooks(Array.isArray(data) ? data.slice(0, 20) : []);
            } catch {
                setSearchBooks([]);
            } finally {
                setIsSearchingBooks(false);
            }
        }, 350);

        return () => clearTimeout(timer);
    }, [isOpen, isIn, isEditMode, bookSearch]);

    const selectBook = (bookId: string, title: string) => {
        setForm((f) => ({ ...f, book_id: bookId }));
        setBookSearch(title);
    };

    const adjustQty = (delta: number) => {
        setForm((f) => {
            const current = parseInt(f.quantity, 10) || 1;
            const next = Math.max(1, maxQty ? Math.min(maxQty, current + delta) : current + delta);
            return { ...f, quantity: String(next) };
        });
    };

    const handleSubmit = () => onSubmit(form);

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={t(isEditMode ? "warehouse.modal.titleEdit" : isIn ? "warehouse.modal.titleIn" : "warehouse.modal.titleOut")}
        >
            <div className="space-y-6 -mt-2">
                {/* Direction banner */}
                <div className={cn(
                    "flex items-center gap-4 p-4 rounded-2xl border",
                    isIn ? "bg-emerald-500/[0.06] border-emerald-500/15" : "bg-rose-500/[0.06] border-rose-500/15"
                )}>
                    <div className={cn(
                        "w-12 h-12 rounded-xl flex items-center justify-center shrink-0",
                        isIn ? "bg-emerald-500/15 text-emerald-600" : "bg-rose-500/15 text-rose-600"
                    )}>
                        {isIn ? <ArrowDown className="w-6 h-6" /> : <ArrowUp className="w-6 h-6" />}
                    </div>
                    <div>
                        <p className="text-[13px] font-black font-vazirmatn text-ink">
                            {t(isEditMode ? "warehouse.modal.subtitleEdit" : isIn ? "warehouse.modal.subtitleIn" : "warehouse.modal.subtitleOut")}
                        </p>
                        <p className="text-[10px] text-ink/40 font-vazirmatn mt-0.5">
                            {t(isEditMode ? "warehouse.modal.hintEdit" : "warehouse.modal.hint")}
                        </p>
                    </div>
                </div>

                {/* Book selection — create only */}
                {!isEditMode ? (
                <div>
                    <SectionLabel icon={BookOpen} label={t("gifts.form.book")} />
                    <div className="relative mb-2">
                        <Search className="absolute inset-y-0 start-3 my-auto w-4 h-4 text-ink/25 pointer-events-none" />
                        <input
                            value={bookSearch}
                            onChange={(e) => {
                                setBookSearch(e.target.value);
                                setForm((f) => ({ ...f, book_id: "" }));
                            }}
                            placeholder={t(isIn ? "warehouse.form.searchBookHint" : "warehouse.form.searchOutHint")}
                            className="w-full h-11 bg-white/60 border border-ink/8 focus:border-primary/30 rounded-xl ps-10 pe-10 text-[13px] font-vazirmatn outline-none transition-all"
                        />
                        {isSearchingBooks && (
                            <Loader2 className="absolute inset-y-0 end-3 my-auto w-4 h-4 animate-spin text-primary" />
                        )}
                    </div>

                    {isIn && bookSearch.trim().length < 2 && inQuickPicks.length > 0 && (
                        <div className="mb-3">
                            <p className="text-[9px] font-black text-ink/30 uppercase tracking-wider mb-2 px-1">
                                {t("warehouse.form.quickPicks")}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {inQuickPicks.map((item) => (
                                    <button
                                        key={item.book.id}
                                        type="button"
                                        onClick={() => selectBook(String(item.book.id), item.book.title)}
                                        className={cn(
                                            "px-3 py-1.5 rounded-lg text-[11px] font-bold font-vazirmatn border transition-all",
                                            form.book_id === String(item.book.id)
                                                ? "bg-primary/10 text-primary border-primary/25"
                                                : "bg-white/50 text-ink/60 border-ink/8 hover:border-primary/20"
                                        )}
                                    >
                                        {item.book.title}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {(isIn ? bookSearch.trim().length >= 2 : true) && (
                        <div className="max-h-44 overflow-y-auto rounded-xl border border-ink/8 bg-white/40 divide-y divide-ink/5">
                            {(isIn ? searchBooks : filteredOutBooks).length === 0 ? (
                                <p className="p-4 text-center text-[11px] text-ink/35 font-vazirmatn">
                                    {isIn && bookSearch.trim().length >= 2 && !isSearchingBooks
                                        ? t("common.noResults")
                                        : !isIn && outBookOptions.length === 0
                                            ? t("warehouse.empty.inventory")
                                            : isIn
                                                ? t("warehouse.form.searchBookHint")
                                                : t("common.noResults")}
                                </p>
                            ) : isIn ? (
                                searchBooks.map((book) => (
                                    <BookRow
                                        key={book.id}
                                        title={book.title}
                                        subtitle={book.author}
                                        meta={book.isbn}
                                        selected={form.book_id === String(book.id)}
                                        onClick={() => selectBook(String(book.id), book.title)}
                                    />
                                ))
                            ) : (
                                filteredOutBooks.map((item) => (
                                    <BookRow
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

                    {selectedBook && (
                        <div className="mt-3 p-4 rounded-xl bg-primary/[0.04] border border-primary/10 flex items-start gap-3">
                            <div className="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center shrink-0">
                                <Check className="w-5 h-5 text-primary" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-black font-vazirmatn text-ink truncate">{selectedBook.title}</p>
                                {selectedBook.author && (
                                    <p className="text-[10px] text-ink/45 font-vazirmatn mt-0.5">{selectedBook.author}</p>
                                )}
                                {!isIn && selectedInventoryItem && (
                                    <p className="text-[10px] text-primary font-black mt-1">
                                        {t("warehouse.form.availableStock")}: {formatNumber(selectedInventoryItem.quantity)}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </div>
                ) : selectedBook && (
                    <div>
                        <SectionLabel icon={BookOpen} label={t("gifts.form.book")} />
                        <div className="p-4 rounded-xl bg-ink/[0.03] border border-ink/8 flex items-start gap-3">
                            <div className="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center shrink-0">
                                <BookOpen className="w-5 h-5 text-primary" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-black font-vazirmatn text-ink">{selectedBook.title}</p>
                                {selectedBook.author && (
                                    <p className="text-[10px] text-ink/45 font-vazirmatn mt-0.5">{selectedBook.author}</p>
                                )}
                                <Badge className={cn(
                                    "mt-2 text-[8px] font-black border",
                                    isIn ? "bg-emerald-500/10 text-emerald-600 border-emerald-500/20" : "bg-rose-500/10 text-rose-600 border-rose-500/20"
                                )}>
                                    {isIn ? t("warehouse.logIn") : t("warehouse.logOut")}
                                </Badge>
                            </div>
                        </div>
                    </div>
                )}

                {/* Quantity & date */}
                <div>
                    <SectionLabel icon={Hash} label={t("warehouse.form.detailsSection")} />
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">
                                {t("warehouse.form.quantity")}
                            </label>
                            <div className="flex items-center h-11 bg-white/60 border border-ink/8 rounded-xl overflow-hidden">
                                <button
                                    type="button"
                                    onClick={() => adjustQty(-1)}
                                    className="w-11 h-full flex items-center justify-center text-ink/40 hover:bg-ink/5 hover:text-ink transition-colors"
                                >
                                    <Minus className="w-4 h-4" />
                                </button>
                                <input
                                    type="number"
                                    min={1}
                                    max={maxQty}
                                    value={form.quantity}
                                    onChange={(e) => setForm((f) => ({ ...f, quantity: e.target.value }))}
                                    className="flex-1 h-full text-center text-lg font-black font-vazirmatn text-ink bg-transparent outline-none"
                                />
                                <button
                                    type="button"
                                    onClick={() => adjustQty(1)}
                                    disabled={maxQty !== undefined && parseInt(form.quantity, 10) >= maxQty}
                                    className="w-11 h-full flex items-center justify-center text-ink/40 hover:bg-ink/5 hover:text-ink transition-colors disabled:opacity-30"
                                >
                                    <Plus className="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">
                                {t("warehouse.form.date")}
                            </label>
                            <div className="relative">
                                <Calendar className="absolute inset-y-0 start-3 my-auto w-4 h-4 text-ink/25 pointer-events-none" />
                                <input
                                    type="date"
                                    value={form.log_date}
                                    onChange={(e) => setForm((f) => ({ ...f, log_date: e.target.value }))}
                                    className="w-full h-11 bg-white/60 border border-ink/8 focus:border-primary/30 rounded-xl ps-10 pe-3 text-[13px] font-vazirmatn outline-none"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {/* Handler */}
                <div>
                    <SectionLabel icon={User} label={t("warehouse.form.handlerSection")} />
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Input
                            label={isIn ? t("warehouse.form.deliverer") : t("warehouse.form.receiver")}
                            value={form.handler_name}
                            onChange={(e) => setForm((f) => ({ ...f, handler_name: e.target.value }))}
                            icon={<User className="w-4 h-4" />}
                            className="h-11 bg-white/60 border-ink/8 rounded-xl"
                        />
                        <Input
                            label={t("warehouse.form.phone")}
                            value={form.handler_phone}
                            onChange={(e) => setForm((f) => ({ ...f, handler_phone: e.target.value }))}
                            icon={<Phone className="w-4 h-4" />}
                            className="h-11 bg-white/60 border-ink/8 rounded-xl"
                        />
                    </div>
                </div>

                {/* Reason pills */}
                <div>
                    <SectionLabel icon={FileText} label={t("warehouse.form.reasonSection")} />
                    <div className="flex flex-wrap gap-2">
                        {reasonOptions.map((key) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setForm((f) => ({ ...f, reason: key }))}
                                className={cn(
                                    "px-4 py-2 rounded-xl text-[11px] font-black font-vazirmatn border transition-all",
                                    form.reason === key
                                        ? isIn
                                            ? "bg-emerald-500 text-white border-emerald-500 shadow-md shadow-emerald-500/20"
                                            : "bg-rose-500 text-white border-rose-500 shadow-md shadow-rose-500/20"
                                        : "bg-white/50 text-ink/50 border-ink/8 hover:border-primary/20"
                                )}
                            >
                                {t(REASON_KEYS[key])}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Notes */}
                <div className="space-y-1.5">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-wider px-1">
                        {t("warehouse.form.notes")}
                    </label>
                    <textarea
                        rows={2}
                        value={form.notes}
                        onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
                        className="w-full rounded-xl border border-ink/8 bg-white/60 px-4 py-3 text-[13px] font-vazirmatn outline-none resize-none focus:border-primary/30 transition-all"
                        placeholder={t("warehouse.form.notesPlaceholder")}
                    />
                </div>

                {/* Actions */}
                <div className="flex gap-3 pt-2 border-t border-ink/5">
                    <Button variant="ghost" className="flex-1 h-12 rounded-xl font-black text-[11px]" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button
                        className={cn(
                            "flex-1 h-12 rounded-xl font-black text-[11px] text-white shadow-lg",
                            isIn ? "bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/25" : "bg-rose-500 hover:bg-rose-600 shadow-rose-500/25"
                        )}
                        disabled={isSubmitting || !form.book_id || !form.handler_name}
                        onClick={handleSubmit}
                    >
                        {isSubmitting
                            ? t("common.submitting")
                            : isEditMode
                                ? t("common.saveChanges")
                                : t(isIn ? "warehouse.logIn" : "warehouse.logOut")}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}

function BookRow({
    title,
    subtitle,
    meta,
    selected,
    onClick,
    lowStock,
}: {
    title: string;
    subtitle?: string;
    meta?: string;
    selected: boolean;
    onClick: () => void;
    lowStock?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                "w-full flex items-center gap-3 px-4 py-3 text-start transition-colors",
                selected ? "bg-primary/[0.08]" : "hover:bg-white/60"
            )}
        >
            <div className={cn(
                "w-9 h-9 rounded-lg flex items-center justify-center shrink-0 border",
                selected ? "bg-primary/10 border-primary/20 text-primary" : "bg-ink/[0.03] border-ink/5 text-ink/25"
            )}>
                {selected ? <Check className="w-4 h-4" /> : <BookOpen className="w-4 h-4" />}
            </div>
            <div className="min-w-0 flex-1">
                <p className={cn("text-[12px] font-vazirmatn truncate", selected ? "font-black text-primary" : "font-bold text-ink")}>
                    {title}
                </p>
                {(subtitle || meta) && (
                    <p className="text-[10px] text-ink/40 font-vazirmatn mt-0.5 truncate">
                        {[subtitle, meta].filter(Boolean).join(" · ")}
                    </p>
                )}
            </div>
            {lowStock && (
                <Badge className="text-[8px] font-black bg-rose-500/10 text-rose-500 border-rose-500/20 shrink-0">
                    {t("inventory.lowStock")}
                </Badge>
            )}
        </button>
    );
}
