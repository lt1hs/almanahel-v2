"use client";

import React, { useState, useEffect, useMemo, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
    Search, BookOpen, X, ArrowRight, ArrowLeft, ArrowLeftRight, Warehouse, Store,
    CheckCircle2, RefreshCw, AlertTriangle, Package, Building2,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import {
    buildBookShowUrl,
    buildBooksListUrl,
    buildInventoryBookBranchesUrl,
} from "@/lib/bookCatalogRequests";
import { useTranslation } from "@/hooks/useTranslation";

interface Branch {
    id: number;
    name: string;
    city: string;
    type?: string;
    total_stock?: number;
}

interface TransferWizardProps {
    branches: Branch[];
    userName?: string;
    userRole?: string | null;
    userBranchId?: number | null;
    onSuccess: () => void;
    prefillFrom?: string;
    prefillTo?: string;
    recentTransfers?: any[];
}

function isHqRole(role?: string | null) {
    return role === "admin" || role === "super_admin";
}

function isQomStore(branch: Branch) {
    const city = (branch.city || "").trim();
    const name = (branch.name || "").trim();
    return branch.type === "store" && (city === "قم" || name.includes("قم"));
}

function stockMapFromInventories(inventories: any[] | undefined): Record<string, number> {
    const map: Record<string, number> = {};
    for (const row of inventories || []) {
        const branchId = String(row.branch_id ?? row.branch?.id ?? "");
        const qty = Number(row.quantity) || 0;
        if (branchId && qty > 0) map[branchId] = qty;
    }
    return map;
}

export function TransferWizard({
    branches,
    userName,
    userRole,
    userBranchId,
    onSuccess,
    prefillFrom,
    prefillTo,
    recentTransfers = [],
}: TransferWizardProps) {
    const { t, formatNumber } = useTranslation();
    const initialPosUser = !(isHqRole(userRole) || userRole === "warehouse_staff");
    const [step, setStep] = useState(0);
    const [searchQuery, setSearchQuery] = useState("");
    const [searchResults, setSearchResults] = useState<any[]>([]);
    const [catalogBooks, setCatalogBooks] = useState<any[]>([]);
    const [isLoadingBooks, setIsLoadingBooks] = useState(true);
    const [selectedBook, setSelectedBook] = useState<any>(null);
    const [catalogBranchId, setCatalogBranchId] = useState<string>(
        initialPosUser && userBranchId ? String(userBranchId) : (prefillFrom || "")
    );
    const [fromBranch, setFromBranch] = useState(
        prefillFrom || (initialPosUser && userBranchId ? String(userBranchId) : "")
    );
    const [toBranch, setToBranch] = useState(prefillTo || "");
    const [transferQty, setTransferQty] = useState("1");
    const [isTransferring, setIsTransferring] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sourceStock, setSourceStock] = useState<number | null>(null);
    const [isLoadingStock, setIsLoadingStock] = useState(false);
    /** branchId → qty for the selected book (only branches with qty > 0) */
    const [stockByBranch, setStockByBranch] = useState<Record<string, number>>({});
    const [isLoadingAvailability, setIsLoadingAvailability] = useState(false);
    const didAutoRouteRef = useRef(false);

    const steps = [
        t("distribution.wizard.stepBook"),
        t("distribution.wizard.stepRoute"),
        t("distribution.wizard.stepConfirm"),
    ];

    const canControlWarehouse = isHqRole(userRole) || userRole === "warehouse_staff";
    const isPosUser = !canControlWarehouse;

    const storeBranches = useMemo(
        () => branches.filter((b) => b.type === "store" || b.type === "warehouse"),
        [branches]
    );

    useEffect(() => {
        if (isPosUser) return;
        if (catalogBranchId) return;
        const fromPrefill = prefillFrom && storeBranches.some((b) => String(b.id) === String(prefillFrom))
            ? String(prefillFrom)
            : "";
        const qom = storeBranches.find(isQomStore);
        const warehouse = storeBranches.find((b) => b.type === "warehouse");
        const next = fromPrefill || (qom ? String(qom.id) : "") || (warehouse ? String(warehouse.id) : "") || (storeBranches[0] ? String(storeBranches[0].id) : "");
        if (!next) return;
        setCatalogBranchId(next);
        setFromBranch((prev) => prev || next);
    }, [isPosUser, catalogBranchId, prefillFrom, storeBranches]);

    /** مبدأ: POS = own store only; admin/warehouse = all with stock (warehouse ok) */
    const fromBranches = useMemo(() => {
        let list = storeBranches;
        if (isPosUser && userBranchId) {
            list = list.filter((b) => Number(b.id) === Number(userBranchId) && b.type !== "warehouse");
        } else if (!canControlWarehouse) {
            list = list.filter((b) => b.type !== "warehouse");
        }
        if (!selectedBook) return list;
        const ids = new Set(Object.keys(stockByBranch));
        return list.filter((b) => ids.has(String(b.id)));
    }, [storeBranches, selectedBook, stockByBranch, isPosUser, userBranchId, canControlWarehouse]);

    /** مقصد: POS = Qom store + warehouse only; admin = all */
    const toBranches = useMemo(() => {
        if (!isPosUser) return storeBranches;
        return storeBranches.filter((b) => b.type === "warehouse" || isQomStore(b));
    }, [storeBranches, isPosUser]);

    useEffect(() => {
        if (prefillFrom) {
            const allowed = !isPosUser || String(prefillFrom) === String(userBranchId || "");
            const fromMeta = storeBranches.find((b) => String(b.id) === String(prefillFrom));
            if (allowed && !(isPosUser && fromMeta?.type === "warehouse")) {
                setFromBranch(prefillFrom);
                if (!isPosUser) setCatalogBranchId((prev) => prev || prefillFrom);
            }
        }
        if (prefillTo) {
            const dest = storeBranches.find((b) => String(b.id) === String(prefillTo));
            const allowedDest = !isPosUser || dest?.type === "warehouse" || (dest ? isQomStore(dest) : false);
            if (allowedDest) setToBranch(prefillTo);
        }
    }, [prefillFrom, prefillTo, isPosUser, userBranchId, storeBranches]);

    const recentBooks = useMemo(() => {
        const seen = new Set<string>();
        const list: any[] = [];
        for (const transfer of recentTransfers) {
            const items = Array.isArray(transfer?.items) ? transfer.items : [];
            for (const item of items) {
                const id = String(item?.book?.id ?? item?.book_id ?? "");
                if (!id || seen.has(id)) continue;
                seen.add(id);
                list.push({
                    id: item?.book?.id ?? item?.book_id,
                    title: item?.book?.title || t("distribution.bookFallback"),
                    author: item?.book?.author || "",
                    isbn: item?.book?.isbn || "",
                });
            }
        }
        return list;
    }, [recentTransfers, t]);

    const browseBooks = useMemo(() => {
        const seen = new Set(recentBooks.map((b) => String(b.id)));
        const extra = catalogBooks.filter((b) => !seen.has(String(b.id)));
        return [...recentBooks, ...extra].slice(0, 48);
    }, [recentBooks, catalogBooks]);

    const visibleBooks = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        if (q.length >= 2 && searchResults.length > 0) return searchResults;
        if (!q) return browseBooks;
        return browseBooks.filter((b) => {
            const hay = `${b.title || ""} ${b.author || ""} ${b.isbn || ""}`.toLowerCase();
            return hay.includes(q);
        });
    }, [searchQuery, searchResults, browseBooks]);

    useEffect(() => {
        let cancelled = false;
        if (!catalogBranchId) {
            setCatalogBooks([]);
            setIsLoadingBooks(false);
            return;
        }
        setIsLoadingBooks(true);
        const url = buildBooksListUrl(
            { role: userRole ?? undefined, branch_id: userBranchId ?? undefined },
            { branchId: Number(catalogBranchId), lite: true }
        );
        if (!url) {
            setCatalogBooks([]);
            setIsLoadingBooks(false);
            return;
        }
        apiRequest(url)
            .then((data) => {
                if (cancelled) return;
                setCatalogBooks(Array.isArray(data) ? data : []);
            })
            .catch(() => {
                if (!cancelled) setCatalogBooks([]);
            })
            .finally(() => {
                if (!cancelled) setIsLoadingBooks(false);
            });
        return () => { cancelled = true; };
    }, [catalogBranchId, userBranchId, userRole]);

    useEffect(() => {
        const bookId = new URLSearchParams(window.location.search).get("book");
        if (!bookId || !catalogBranchId) return;
        let cancelled = false;
        const url = buildBookShowUrl(
            bookId,
            { role: userRole ?? undefined, branch_id: userBranchId ?? undefined },
            Number(catalogBranchId)
        );
        if (!url) return;
        apiRequest(url)
            .then((book) => {
                if (cancelled || !book?.id) return;
                setSelectedBook(book);
                setSearchQuery(book.title || "");
                const map = stockMapFromInventories(book.inventories);
                if (Object.keys(map).length) setStockByBranch(map);
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, [catalogBranchId, userBranchId, userRole]);

    // Load which branches have the selected book in stock
    useEffect(() => {
        if (!selectedBook?.id || !catalogBranchId) {
            setStockByBranch({});
            setSourceStock(null);
            return;
        }

        let cancelled = false;
        setIsLoadingAvailability(true);

        const url = buildInventoryBookBranchesUrl(
            selectedBook.id,
            { role: userRole ?? undefined, branch_id: userBranchId ?? undefined },
            Number(catalogBranchId)
        );
        if (!url) {
            setStockByBranch({});
            setIsLoadingAvailability(false);
            return;
        }

        apiRequest(url)
            .then((data) => {
                if (cancelled) return;
                const incoming = stockMapFromInventories(data?.inventories);
                let merged: Record<string, number> = incoming;
                setStockByBranch((prev) => {
                    merged = Object.keys(incoming).length === 0 && Object.keys(prev).length > 0
                        ? prev
                        : { ...prev, ...incoming };
                    return merged;
                });
                setFromBranch((prev) => {
                    if (prev && merged[prev]) return prev;
                    const warehouse = storeBranches.find((b) => b.type === "warehouse");
                    if (warehouse && merged[String(warehouse.id)]) return String(warehouse.id);
                    const first = Object.keys(merged)[0];
                    return first || "";
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setStockByBranch((prev) => (Object.keys(prev).length ? prev : {}));
                }
            })
            .finally(() => {
                if (!cancelled) setIsLoadingAvailability(false);
            });

        return () => { cancelled = true; };
    }, [selectedBook?.id, catalogBranchId, userBranchId, userRole, storeBranches]);

    // POS: lock source to own branch when it has stock
    useEffect(() => {
        if (!isPosUser || !userBranchId) return;
        const own = String(userBranchId);
        if (stockByBranch[own] != null) {
            setFromBranch(own);
        }
    }, [isPosUser, userBranchId, stockByBranch]);

    // Keep sourceStock in sync with selected from-branch
    useEffect(() => {
        if (!fromBranch || !selectedBook) {
            setSourceStock(null);
            setIsLoadingStock(false);
            return;
        }
        if (isLoadingAvailability) {
            setIsLoadingStock(true);
            return;
        }
        setIsLoadingStock(false);
        setSourceStock(stockByBranch[fromBranch] ?? 0);
    }, [fromBranch, selectedBook, stockByBranch, isLoadingAvailability]);

    useEffect(() => {
        const timer = setTimeout(async () => {
            if (searchQuery.length > 2 && !selectedBook && catalogBranchId) {
                try {
                    const url = buildBooksListUrl(
                        { role: userRole ?? undefined, branch_id: userBranchId ?? undefined },
                        { branchId: Number(catalogBranchId), search: searchQuery }
                    );
                    if (!url) {
                        setSearchResults([]);
                        return;
                    }
                    const data = await apiRequest(url);
                    setSearchResults(Array.isArray(data) ? data : (data.data || []));
                } catch {
                    setSearchResults([]);
                }
            } else if (searchQuery.length === 0) {
                setSearchResults([]);
            }
        }, 280);
        return () => clearTimeout(timer);
    }, [searchQuery, selectedBook, catalogBranchId, userBranchId, userRole]);

    useEffect(() => {
        if (didAutoRouteRef.current) return;
        if (typeof window === "undefined") return;
        if (!new URLSearchParams(window.location.search).get("book")) return;
        if (!selectedBook) return;
        if (isLoadingAvailability) return;
        didAutoRouteRef.current = true;
        setStep(1);
    }, [selectedBook, isLoadingAvailability]);

    const fromName = storeBranches.find((b) => String(b.id) === fromBranch)?.name;
    const toName = storeBranches.find((b) => String(b.id) === toBranch)?.name;

    const canNext = () => {
        if (step === 0) return !!selectedBook;
        if (step === 1) {
            if (!fromBranch || !toBranch || fromBranch === toBranch) return false;
            if (selectedBook && sourceStock !== null && sourceStock <= 0) return false;
            return true;
        }
        const qty = parseInt(transferQty, 10);
        if (!qty || qty <= 0) return false;
        if (sourceStock !== null && qty > sourceStock) return false;
        return true;
    };

    const handleSubmit = async () => {
        if (!selectedBook || !fromBranch || !toBranch || !transferQty) return;

        const qty = parseInt(transferQty, 10);
        if (sourceStock !== null && qty > sourceStock) {
            setError(t("distribution.wizard.insufficientSourceStock"));
            return;
        }
        if (sourceStock !== null && sourceStock <= 0) {
            setError(t("distribution.wizard.noSourceStock"));
            return;
        }

        setIsTransferring(true);
        setError(null);
        try {
            await apiRequest("/transfers", {
                method: "POST",
                body: JSON.stringify({
                    from_branch_id: parseInt(fromBranch),
                    to_branch_id: parseInt(toBranch),
                    items: [{ book_id: selectedBook.id, quantity: qty }],
                    notes: t("distribution.wizard.transferNote", {
                        name: userName || t("common.system"),
                    }),
                }),
            });
            setStep(0);
            setSelectedBook(null);
            setSearchQuery("");
            setFromBranch("");
            setToBranch("");
            setTransferQty("1");
            setSourceStock(null);
            onSuccess();
        } catch (err) {
            const message = (err as Error).message;
            setError(message || t("distribution.wizard.transferFailed"));
        } finally {
            setIsTransferring(false);
        }
    };

    return (
        <div className="rounded-2xl border border-white/70 bg-white/70 overflow-hidden">
            <div className="px-5 md:px-6 pt-5 pb-4 border-b border-ink/5 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center shrink-0">
                        <ArrowLeftRight className="w-4 h-4 text-primary" />
                    </div>
                    <div>
                        <h3 className="text-base font-black font-vazirmatn text-ink">
                            {t("distribution.wizard.title")}
                        </h3>
                        <p className="text-[10px] text-ink/35 font-bold mt-0.5">
                            {t("distribution.quickTransfer")}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 flex-wrap lg:justify-end">
                    {!isPosUser && !selectedBook && storeBranches.length > 0 && (
                        <FilterSelect
                            className="w-full min-w-[180px] sm:w-[220px]"
                            value={catalogBranchId}
                            onChange={(value) => {
                                setCatalogBranchId(value);
                                setFromBranch(value);
                            }}
                            options={storeBranches.map((b) => ({ value: String(b.id), label: b.name }))}
                            icon={<Building2 className="h-3.5 w-3.5" />}
                            defaultValue="__none__"
                            placeholder={t("inventory.selectBranch")}
                        />
                    )}
                    <div className="flex items-center gap-1 flex-wrap">
                    {steps.map((label, i) => (
                        <React.Fragment key={label}>
                            <button
                                type="button"
                                onClick={() => i < step && setStep(i)}
                                className={cn(
                                    "flex items-center gap-1.5 px-2 py-1 rounded-lg transition-colors min-w-0",
                                    i === step ? "bg-primary/8" : "hover:bg-ink/[0.03]"
                                )}
                            >
                                <span className={cn(
                                    "w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-black shrink-0",
                                    i < step ? "bg-primary text-white" :
                                    i === step ? "bg-primary text-white" :
                                    "bg-ink/5 text-ink/30"
                                )}>
                                    {i < step ? <CheckCircle2 className="w-3.5 h-3.5" /> : i + 1}
                                </span>
                                <span className={cn(
                                    "text-[10px] font-black font-vazirmatn truncate",
                                    i === step ? "text-ink" : "text-ink/35"
                                )}>{label}</span>
                            </button>
                            {i < steps.length - 1 && (
                                <div className={cn(
                                    "flex-1 h-px max-w-6",
                                    i < step ? "bg-primary/40" : "bg-ink/8"
                                )} />
                            )}
                        </React.Fragment>
                    ))}
                    </div>
                </div>
            </div>

            <div className="p-5 md:p-6">
                <AnimatePresence mode="wait">
                    {step === 0 && (
                        <motion.div key="s0" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="space-y-4">
                            {selectedBook ? (
                                <div className="flex items-center gap-3 p-4 rounded-xl bg-parchment/40 border border-ink/8 max-w-2xl">
                                    <div className="w-12 h-16 rounded-lg bg-white border border-ink/8 flex items-center justify-center shrink-0">
                                        <BookOpen className="w-5 h-5 text-primary" />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-black font-vazirmatn text-ink truncate">{selectedBook.title}</p>
                                        <p className="text-[11px] text-ink/40 mt-0.5 truncate">
                                            {selectedBook.author || "—"}
                                            {selectedBook.isbn ? ` · ${selectedBook.isbn}` : ""}
                                        </p>
                                    </div>
                                    <button type="button" onClick={() => {
                                        setSelectedBook(null);
                                        setSearchQuery("");
                                        setSearchResults([]);
                                        setStockByBranch({});
                                        setFromBranch("");
                                        setSourceStock(null);
                                    }}
                                        className="p-2 rounded-lg hover:bg-rose-50 text-ink/30 hover:text-rose-500 transition-colors">
                                        <X className="w-4 h-4" />
                                    </button>
                                </div>
                            ) : (
                                <>
                                    <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                                        <div className="relative flex-1">
                                            <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/25 pointer-events-none" />
                                            <input
                                                placeholder={t("distribution.wizard.searchPlaceholder")}
                                                value={searchQuery}
                                                onChange={(e) => setSearchQuery(e.target.value)}
                                                disabled={!catalogBranchId}
                                                className="w-full h-11 bg-white border border-ink/8 focus:border-primary/30 rounded-xl ps-9 pe-3 text-[13px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                                            />
                                        </div>
                                        <p className="text-[10px] font-black text-ink/35 shrink-0">
                                            {searchQuery.trim()
                                                ? t("distribution.wizard.searchHint")
                                                : recentBooks.length > 0
                                                    ? t("distribution.wizard.recentBooks")
                                                    : t("distribution.wizard.availableBooks")}
                                        </p>
                                    </div>
                                    <div className="rounded-xl border border-ink/8 bg-white p-2 max-h-[420px] overflow-y-auto">
                                        {isLoadingBooks && visibleBooks.length === 0 ? (
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                                {Array.from({ length: 6 }).map((_, i) => (
                                                    <div key={i} className="h-16 bg-parchment/30 rounded-xl animate-pulse" />
                                                ))}
                                            </div>
                                        ) : visibleBooks.length === 0 ? (
                                            <p className="px-3.5 py-12 text-center text-[11px] font-bold text-ink/30 font-vazirmatn">
                                                {t("common.noResults")}
                                            </p>
                                        ) : (
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                                {visibleBooks.map((b) => (
                                                    <button
                                                        key={b.id}
                                                        type="button"
                                                        onClick={() => {
                                                            setSelectedBook(b);
                                                            setSearchQuery(b.title || "");
                                                            setSearchResults([]);
                                                        }}
                                                        className="w-full px-3 py-2.5 text-start hover:bg-primary/5 rounded-xl border border-transparent hover:border-primary/15 flex items-center gap-3"
                                                    >
                                                        <div className="w-9 h-11 rounded-md bg-parchment/50 border border-ink/5 flex items-center justify-center shrink-0">
                                                            <BookOpen className="w-3.5 h-3.5 text-ink/20" />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-[12px] font-black font-vazirmatn text-ink truncate">{b.title}</p>
                                                            <p className="text-[10px] text-ink/35 mt-0.5 truncate">
                                                                {b.author || "—"}
                                                                {b.isbn ? ` · ${b.isbn}` : ""}
                                                            </p>
                                                        </div>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}
                        </motion.div>
                    )}

                    {step === 1 && (
                        <motion.div key="s1" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="space-y-4">
                            <p className="text-[11px] text-ink/45 font-vazirmatn">{t("distribution.wizard.routeHint")}</p>
                            {isPosUser && (
                                <p className="text-[10px] font-bold text-primary/80 bg-primary/5 border border-primary/10 rounded-xl px-3 py-2">
                                    {t("distribution.wizard.posRouteHint")}
                                </p>
                            )}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <span className="text-[9px] font-black text-ink/30 uppercase tracking-widest px-1">{t("distribution.wizard.from")}</span>
                                    <div className="space-y-2 max-h-[280px] overflow-y-auto pe-1">
                                        {isLoadingAvailability ? (
                                            <p className="text-[10px] text-ink/35 px-1 py-3">{t("common.loading")}</p>
                                        ) : fromBranches.length === 0 ? (
                                            <p className="text-[10px] font-bold text-rose-500 px-1 py-3 flex items-start gap-1.5">
                                                <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                                                {t("distribution.wizard.noBranchHasBook")}
                                            </p>
                                        ) : (
                                            fromBranches.map((b) => (
                                                <BranchChip
                                                    key={`from-${b.id}`}
                                                    branch={b}
                                                    selected={fromBranch === String(b.id)}
                                                    onClick={() => setFromBranch(String(b.id))}
                                                    variant="from"
                                                    stockQty={stockByBranch[String(b.id)]}
                                                    formatNumber={formatNumber}
                                                    volumeUnit={t("distribution.volumeUnit")}
                                                />
                                            ))
                                        )}
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <span className="text-[9px] font-black text-ink/30 uppercase tracking-widest px-1">{t("distribution.wizard.to")}</span>
                                    <div className="space-y-2 max-h-[280px] overflow-y-auto pe-1">
                                        {toBranches.map((b) => (
                                            <BranchChip
                                                key={`to-${b.id}`}
                                                branch={b}
                                                selected={toBranch === String(b.id)}
                                                onClick={() => setToBranch(String(b.id))}
                                                variant="to"
                                                disabled={String(b.id) === fromBranch}
                                            />
                                        ))}
                                    </div>
                                </div>
                            </div>
                            {fromBranch && toBranch && fromBranch === toBranch && (
                                <p className="text-[10px] font-bold text-rose-500 flex items-center gap-1.5">
                                    <AlertTriangle className="w-3.5 h-3.5" /> {t("distribution.wizard.sameBranchError")}
                                </p>
                            )}
                            {fromName && toName && fromBranch !== toBranch && (
                                <div className="flex items-center justify-center gap-3 py-3 px-4 rounded-2xl bg-ink/[0.02] border border-ink/5">
                                    <span className="text-[11px] font-black font-vazirmatn text-ink/60">{fromName}</span>
                                    <ArrowLeft className="w-4 h-4 text-primary" />
                                    <span className="text-[11px] font-black font-vazirmatn text-primary">{toName}</span>
                                </div>
                            )}
                            {fromBranch && selectedBook && (
                                <div className={cn(
                                    "px-4 py-3 rounded-xl border text-[11px] font-vazirmatn",
                                    isLoadingStock && "bg-ink/[0.02] border-ink/8 text-ink/40",
                                    !isLoadingStock && sourceStock !== null && sourceStock <= 0 && "bg-rose-50 border-rose-100 text-rose-600",
                                    !isLoadingStock && sourceStock !== null && sourceStock > 0 && "bg-primary/5 border-primary/15 text-ink/65"
                                )}>
                                    {isLoadingStock
                                        ? t("common.loading")
                                        : sourceStock !== null && sourceStock <= 0
                                            ? t("distribution.wizard.noSourceStock")
                                            : t("distribution.wizard.sourceStock", {
                                                branch: fromName || "",
                                                count: formatNumber(sourceStock ?? 0),
                                            })}
                                </div>
                            )}
                        </motion.div>
                    )}

                    {step === 2 && (
                        <motion.div key="s2" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="space-y-4">
                            <div className="rounded-xl border border-ink/8 bg-parchment/30 p-4 md:p-5 space-y-4 max-w-2xl">
                                <div className="flex items-start gap-3">
                                    <div className="w-10 h-12 rounded-lg bg-white border border-ink/8 flex items-center justify-center shrink-0">
                                        <Package className="w-4 h-4 text-primary" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-[13px] font-black font-vazirmatn text-ink leading-snug">{selectedBook?.title}</p>
                                        <div className="flex items-center flex-wrap gap-1.5 mt-2">
                                            <span className="text-[10px] font-bold text-ink/55 px-2 py-1 rounded-lg bg-white border border-ink/8">{fromName}</span>
                                            <ArrowLeft className="w-3.5 h-3.5 text-primary/50" />
                                            <span className="text-[10px] font-bold text-primary px-2 py-1 rounded-lg bg-primary/5 border border-primary/10">{toName}</span>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label className="text-[10px] font-black text-ink/40 block mb-2">{t("distribution.wizard.quantity")}</label>
                                    <div className="flex items-center gap-2">
                                        <button type="button" onClick={() => setTransferQty(String(Math.max(1, parseInt(transferQty || "1") - 1)))}
                                            className="w-10 h-10 rounded-xl border border-ink/8 bg-white font-black text-ink/50 hover:border-primary/30">−</button>
                                        <input type="number" min={1} max={sourceStock ?? undefined} value={transferQty} onChange={(e) => setTransferQty(e.target.value)}
                                            className="flex-1 h-10 text-center text-lg font-black font-vazirmatn bg-white border border-ink/8 rounded-xl outline-none focus:ring-2 focus:ring-primary/15 tabular-nums" />
                                        <button type="button" onClick={() => {
                                            const next = parseInt(transferQty || "1") + 1;
                                            const max = sourceStock ?? next;
                                            setTransferQty(String(Math.min(next, max)));
                                        }}
                                            disabled={sourceStock !== null && parseInt(transferQty || "1") >= sourceStock}
                                            className="w-10 h-10 rounded-xl border border-ink/8 bg-white font-black text-ink/50 hover:border-primary/30 disabled:opacity-30">+</button>
                                        <span className="text-[10px] font-bold text-ink/30 shrink-0">{t("distribution.volumeUnit")}</span>
                                    </div>
                                </div>
                                {sourceStock !== null && (
                                    <p className="text-[10px] font-vazirmatn text-ink/45">
                                        {t("distribution.wizard.sourceStock", {
                                            branch: fromName || "",
                                            count: formatNumber(sourceStock),
                                        })}
                                    </p>
                                )}
                                <p className="text-[10px] font-vazirmatn text-ink/40 leading-relaxed">
                                    {t("distribution.wizard.confirmHint")}
                                </p>
                            </div>
                            {error && (
                                <p className="text-[11px] font-bold text-rose-500 flex items-center gap-2">
                                    <AlertTriangle className="w-4 h-4" /> {error}
                                </p>
                            )}
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

            <div className="px-5 md:px-6 py-3.5 border-t border-ink/5 bg-parchment/20 flex items-center gap-2">
                {step > 0 && (
                    <Button variant="ghost" className="h-10 px-3 rounded-xl text-[11px] font-black" onClick={() => setStep(step - 1)}>
                        <ArrowRight className="w-4 h-4 ms-1" />
                        {t("distribution.wizard.back")}
                    </Button>
                )}
                <div className="flex-1" />
                {step < 2 ? (
                    <Button variant="primary" className="h-10 px-5 rounded-xl font-black text-[11px]" disabled={!canNext()} onClick={() => setStep(step + 1)}>
                        {t("distribution.wizard.nextStep")}
                        <ArrowLeft className="w-4 h-4 me-1.5" />
                    </Button>
                ) : (
                    <Button variant="primary" className="h-10 px-5 rounded-xl font-black text-[11px]" disabled={isTransferring || !canNext()} onClick={handleSubmit}>
                        {isTransferring ? (
                            <span className="flex items-center gap-2"><RefreshCw className="w-4 h-4 animate-spin" /> {t("distribution.wizard.submitting")}</span>
                        ) : (
                            <span className="flex items-center gap-2"><CheckCircle2 className="w-4 h-4" /> {t("distribution.wizard.confirmTransfer")}</span>
                        )}
                    </Button>
                )}
            </div>
        </div>
    );
}

function BranchChip({ branch, selected, onClick, variant, disabled, stockQty, formatNumber, volumeUnit }: {
    branch: Branch;
    selected: boolean;
    onClick: () => void;
    variant: "from" | "to";
    disabled?: boolean;
    stockQty?: number;
    formatNumber?: (n: number) => string;
    volumeUnit?: string;
}) {
    const Icon = branch.type === "warehouse" ? Warehouse : Store;
    return (
        <button type="button" disabled={disabled} onClick={onClick}
            className={cn(
                "w-full flex items-center gap-2.5 p-2.5 rounded-xl border text-start transition-colors",
                disabled && "opacity-30 cursor-not-allowed",
                selected
                    ? variant === "from"
                        ? "bg-amber-50 border-amber-200"
                        : "bg-primary/8 border-primary/20"
                    : "bg-white border-ink/8 hover:border-ink/15"
            )}>
            <div className={cn(
                "w-9 h-9 rounded-lg flex items-center justify-center border shrink-0",
                selected ? "bg-white border-primary/20 text-primary" : "bg-parchment/50 border-ink/5 text-ink/25"
            )}>
                <Icon className="w-4 h-4" />
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-[11px] font-black font-vazirmatn text-ink truncate">{branch.name}</p>
                <p className="text-[9px] text-ink/35">
                    {branch.city}
                    {stockQty != null && formatNumber && (
                        <span className="text-amber-700/80 font-bold ms-1.5 tabular-nums">
                            · {formatNumber(stockQty)} {volumeUnit}
                        </span>
                    )}
                </p>
            </div>
            {selected && <CheckCircle2 className="w-4 h-4 text-primary shrink-0" />}
        </button>
    );
}
