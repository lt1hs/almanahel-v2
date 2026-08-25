"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useState, useEffect, useCallback, useMemo, Suspense } from "react";
import { motion } from "framer-motion";
import {
    ArrowRight, Save, User, Book as BookIcon, Hash, AlertTriangle,
    Package, Building2, Trash2, ShieldAlert,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { BookForm } from "@/components/inventory/BookForm";
import { SupplierSelect, type SupplierAccountSelection } from "@/components/inventory/SupplierSelect";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useAuth } from "@/contexts/AuthContext";
import { useSearchParams } from "next/navigation";
import { useRouter } from "@/i18n/routing";
import { apiRequest, ApiError } from "@/lib/api";
import { buildBookShowUrl } from "@/lib/bookCatalogRequests";
import { bookPayloadFromForm, syncBookBranchInventories } from "@/lib/bookIntake";
import { cn } from "@/lib/utils";
import {
    branchStockFromInventories,
    defaultBranchStock,
    resolveBookCoverUrl,
    resolveBranchId,
    totalBranchStock,
} from "@/lib/bookFormUtils";

function toFormPrice(value: unknown): string {
    if (value === null || value === undefined || value === "") return "";
    const num = Number(value);
    return Number.isFinite(num) ? String(num) : "";
}

function resolveInventory(inventories: any[] | undefined, branchId?: number) {
    if (!inventories?.length) return null;
    if (branchId) {
        return inventories.find((inv) => Number(inv.branch_id) === Number(branchId)) ?? inventories[0];
    }
    return inventories[0];
}

function pickHighestStockBranchId(
    inventories: Array<{ branch_id?: number | null; quantity?: number | null }> | undefined,
    fallbackId: number | null,
    preferredId?: number | null
): number | null {
    if (!inventories?.length) return fallbackId;
    let bestId: number | null = null;
    let bestQty = -1;
    const preferred = preferredId != null ? Number(preferredId) : null;
    for (const inv of inventories) {
        const id = Number(inv.branch_id);
        if (!Number.isFinite(id) || id <= 0) continue;
        const qty = Number(inv.quantity || 0);
        if (qty > bestQty) {
            bestQty = qty;
            bestId = id;
        } else if (qty === bestQty && preferred != null && id === preferred) {
            bestId = id;
        }
    }
    if (bestId == null || bestQty <= 0) return fallbackId ?? bestId;
    return bestId;
}

export const dynamic = "force-static";

function EditBookContent() {
    const { t, formatNumber } = useTranslation();
    const notifyToast = useNotify();
    const router = useRouter();
    const { user } = useAuth();
    const searchParams = useSearchParams();
    const bookId = searchParams.get("id") || "";
    const branchParam = searchParams.get("branch");

    const [book, setBook] = useState<any>(null);
    const [supplier, setSupplier] = useState<SupplierAccountSelection | null>(null);
    const [branches, setBranches] = useState<any[]>([]);
    const [inventories, setInventories] = useState<any[]>([]);
    const [selectedBranchId, setSelectedBranchId] = useState<number | null>(
        branchParam && Number.isFinite(Number(branchParam)) ? Number(branchParam) : null
    );
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const loadedRef = React.useRef(false);
    const userPickedBranchRef = React.useRef(Boolean(branchParam));
    const selectedBranchIdRef = React.useRef<number | null>(
        branchParam && Number.isFinite(Number(branchParam)) ? Number(branchParam) : null
    );
    const [branchesReady, setBranchesReady] = useState(false);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";
    const qomBranchId = useMemo(() => resolveBranchId(branches, "qom"), [branches]);
    const bootstrapBranchId = useMemo(() => {
        if (branchParam && Number.isFinite(Number(branchParam))) return Number(branchParam);
        return qomBranchId ?? (branches[0] ? Number(branches[0].id) : null);
    }, [branchParam, qomBranchId, branches]);
    const operationalBranchId = isAdmin
        ? (selectedBranchId ?? bootstrapBranchId)
        : (user?.branch?.id ?? user?.branch_id ?? null);
    selectedBranchIdRef.current = selectedBranchId;

    const chooseBranch = useCallback((branchId: number) => {
        if (!Number.isFinite(branchId) || branchId <= 0) return;
        userPickedBranchRef.current = true;
        setSelectedBranchId(branchId);
    }, []);

    const applyBookPayload = useCallback((
        data: any,
        branchRows: any[],
        branchForForm: number | null
    ) => {
        const inventory = resolveInventory(data.inventories, branchForForm ?? undefined);

        const qomInv = data.inventories?.find((inv: any) => resolveBranchId(branchRows, "qom") === Number(inv.branch_id));
        const mashhadInv = data.inventories?.find((inv: any) => resolveBranchId(branchRows, "mashhad") === Number(inv.branch_id));
        const najafInv = data.inventories?.find((inv: any) => resolveBranchId(branchRows, "najaf") === Number(inv.branch_id));

        const sellTomanQom = Number(qomInv?.price_toman ?? inventory?.price_toman);
        const sellTomanMashhad = Number(mashhadInv?.price_toman ?? sellTomanQom);
        const sellDinar = Number(najafInv?.price_dinar ?? inventory?.price_dinar);
        const costToman = Number(qomInv?.cost_price_toman ?? inventory?.cost_price_toman);
        const costDinar = Number(najafInv?.cost_price_dinar ?? inventory?.cost_price_dinar);

        setBook({
            title: data.title || "",
            author: data.author || "",
            isbn: data.isbn || "",
            publisher: data.publisher || "",
            size: data.size || "",
            cover: data.cover || "",
            publicationYear: data.publication_year || "",
            coverImage: data.cover_image || "",
            coverImagePreview: resolveBookCoverUrl(data.cover_image) || "",
            weight: data.weight != null ? String(data.weight) : "",
            weightWithPackaging: data.weight_with_packaging != null ? String(data.weight_with_packaging) : "",
            volumeCount: data.volume_count != null ? String(data.volume_count) : "1",
            category: data.category || "",
            notes: data.description || "",
            language: data.language || "fa",
            low_stock_threshold: data.low_stock_threshold?.toString() ?? "",
            type: inventory?.type || "owned",
            priceTomanQom: toFormPrice(sellTomanQom),
            priceTomanMashhad: toFormPrice(sellTomanMashhad),
            priceDinar: toFormPrice(sellDinar),
            costPriceToman: toFormPrice(costToman),
            costPriceDinar: toFormPrice(costDinar),
            branchStock: branchStockFromInventories(data.inventories, branchRows),
            settlementDate: "",
            unpaidSales: "0",
            totalSales: "0",
            iraqOnly: Boolean(data.iraq_only),
        });
    }, []);

    const fetchBook = useCallback(async () => {
        if (!operationalBranchId) {
            if (!isAdmin) {
                setIsLoading(false);
                setError(t("inventory.branchRequired"));
                setBook(null);
            }
            return;
        }
        const firstLoad = !loadedRef.current;
        if (firstLoad) setIsLoading(true);
        else setIsRefreshing(true);
        setError(null);
        try {
            const showUrl = buildBookShowUrl(
                bookId,
                { role: user?.role, branch_id: user?.branch_id ?? user?.branch?.id },
                operationalBranchId
            );
            if (!showUrl) {
                setError(t("inventory.branchRequired"));
                if (!loadedRef.current) setBook(null);
                return;
            }
            const catalogUser = { role: user?.role, branch_id: user?.branch_id ?? user?.branch?.id };
            const requestShow = async (branchId: number) => {
                const url = buildBookShowUrl(bookId, catalogUser, branchId);
                if (!url) return null;
                return apiRequest(url);
            };

            const branchList = await apiRequest("/branches").catch(() => []);
            const branchRows = Array.isArray(branchList) ? branchList : [];
            setBranches(branchRows);

            let data: any;
            let resolvedShowBranch = operationalBranchId;
            try {
                data = await requestShow(operationalBranchId);
            } catch (err) {
                if (err instanceof ApiError && err.status === 404 && loadedRef.current) {
                    return;
                }
                if (!(err instanceof ApiError && err.status === 404 && isAdmin)) {
                    throw err;
                }
                let recovered: any = null;
                let recoveredBranch: number | null = null;
                for (const row of branchRows) {
                    const id = Number(row.id);
                    if (!id || id === Number(operationalBranchId)) continue;
                    try {
                        recovered = await requestShow(id);
                        recoveredBranch = id;
                        break;
                    } catch (inner) {
                        if (!(inner instanceof ApiError) || inner.status !== 404) throw inner;
                    }
                }
                if (!recovered || recoveredBranch == null) throw err;
                data = recovered;
                resolvedShowBranch = recoveredBranch;
                setSelectedBranchId(recoveredBranch);
            }
            if (!data) {
                setError(t("inventory.branchRequired"));
                if (!loadedRef.current) setBook(null);
                return;
            }
            const rows = Array.isArray(data.inventories) ? data.inventories : [];
            setInventories(rows);

            let branchForForm = resolvedShowBranch;
            if (isAdmin && !userPickedBranchRef.current && !branchParam) {
                const preferredQom = resolveBranchId(branchRows, "qom");
                const best = pickHighestStockBranchId(rows, resolvedShowBranch, preferredQom);
                if (best) {
                    branchForForm = best;
                    if (Number(best) !== Number(resolvedShowBranch)) {
                        setSelectedBranchId(best);
                    } else if (selectedBranchIdRef.current == null) {
                        setSelectedBranchId(best);
                    }
                }
            } else if (isAdmin && selectedBranchIdRef.current == null && operationalBranchId) {
                setSelectedBranchId(operationalBranchId);
            }

            applyBookPayload(data, branchRows, branchForForm);
            loadedRef.current = true;
        } catch (err) {
            if (err instanceof ApiError && err.status === 404 && loadedRef.current) {
                return;
            }
            setError(err instanceof ApiError && err.message ? err.message : t("toast.bookNotFound"));
            if (!loadedRef.current) setBook(null);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [
        applyBookPayload,
        bookId,
        branchParam,
        isAdmin,
        operationalBranchId,
        t,
        user?.branch?.id,
        user?.branch_id,
        user?.role,
    ]);

    useEffect(() => {
        apiRequest("/branches")
            .then((branchList) => setBranches(Array.isArray(branchList) ? branchList : []))
            .catch(() => setBranches([]))
            .finally(() => setBranchesReady(true));
    }, []);

    useEffect(() => {
        if (!bookId) {
            setIsLoading(false);
            setError(t("toast.bookNotFound"));
            setBook(null);
            return;
        }
        if (isAdmin && !branchesReady) return;
        if (!operationalBranchId) {
            setIsLoading(false);
            setError(t("inventory.branchRequired"));
            setBook(null);
            return;
        }
        fetchBook();
    }, [bookId, fetchBook, isAdmin, operationalBranchId, branchesReady, t]);

    const coverPreview = book?.coverImagePreview || resolveBookCoverUrl(book?.coverImage);
    const totalStock = useMemo(
        () => totalBranchStock({ ...defaultBranchStock(), ...(book?.branchStock || {}) }),
        [book?.branchStock]
    );

    const stockByBranch = useMemo(() => {
        if (!inventories.length) return [];
        return inventories
            .map((inv) => ({
                id: inv.id,
                branchId: Number(inv.branch_id),
                name: inv.branch?.name || branches.find((b) => Number(b.id) === Number(inv.branch_id))?.name || `#${inv.branch_id}`,
                quantity: Number(inv.quantity || 0),
                type: inv.type || "owned",
            }))
            .filter((row) => row.name);
    }, [inventories, branches]);

    const branchOptions = useMemo(
        () =>
            branches.map((b) => {
                const qty = inventories.find((inv) => Number(inv.branch_id) === Number(b.id))?.quantity ?? 0;
                return {
                    value: String(b.id),
                    label: `${b.name} · ${formatNumber(qty)}`,
                };
            }),
        [branches, inventories, formatNumber]
    );

    const handleSave = async () => {
        if (!book?.title) {
            setError(t("toast.titleRequired"));
            return;
        }
        if (book.type === "consignment" && !supplier?.accountId) {
            setError(t("toast.selectSupplierFirst"));
            return;
        }

        setIsSaving(true);
        setError(null);
        try {
            await apiRequest(`/books/${bookId}`, {
                method: "PUT",
                body: JSON.stringify({
                    ...bookPayloadFromForm(book),
                    category: book.category,
                    language: book.language,
                    low_stock_threshold: book.low_stock_threshold ? Number(book.low_stock_threshold) : null,
                }),
            });

            await syncBookBranchInventories(
                book,
                isAdmin && operationalBranchId
                    ? branches.filter((b) => Number(b.id) === operationalBranchId)
                    : branches,
                supplier,
                Number(bookId),
                {
                    existingInventories: inventories,
                    syncQuantities: false,
                }
            );

            notifyToast.success("messages.savedSuccessfully");
            router.push("/dashboard/inventory");
        } catch (err) {
            if ((err as Error).message) notifyToast.rawError((err as Error).message);
            else notifyToast.error("messages.errorOccurred");
        } finally {
            setIsSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!isAdmin) return;
        if (!confirm(t("inventory.confirmDeleteGuarded"))) return;

        setIsDeleting(true);
        setError(null);
        try {
            await apiRequest(`/books/${bookId}`, { method: "DELETE" });
            notifyToast.success("toast.bookDeleted");
            router.push("/dashboard/inventory");
        } catch (err) {
            const msg = (err as Error).message;
            if (msg) {
                setError(msg);
                notifyToast.rawError(msg);
            } else {
                notifyToast.error("toast.bookDeleteError");
            }
        } finally {
            setIsDeleting(false);
        }
    };

    if (isLoading && !book) {
        return (
            <div className="max-w-5xl mx-auto space-y-4 pb-10">
                <div className="h-28 rounded-2xl bg-parchment/30 animate-pulse" />
                <div className="h-40 rounded-2xl bg-parchment/20 animate-pulse" />
                <div className="h-96 rounded-2xl bg-parchment/20 animate-pulse" />
            </div>
        );
    }

    if (!book) {
        return (
            <div className="text-center py-20">
                <p className="text-ink/40 font-black">{error || t("toast.bookNotFound")}</p>
                <Button className="mt-4" onClick={() => router.push("/dashboard/inventory")}>{t("common.back")}</Button>
            </div>
        );
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            className={cn(
                "max-w-5xl mx-auto space-y-5 pb-12 transition-opacity",
                isRefreshing && "opacity-70 pointer-events-none"
            )}
        >
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-10 w-10 p-0 rounded-xl border border-ink/5 bg-white/70 shrink-0"
                        onClick={() => router.push("/dashboard/inventory")}
                    >
                        <ArrowRight className="w-4 h-4" />
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-xl font-black font-vazirmatn text-ink truncate">
                            {t("inventory.editBook")}
                        </h1>
                        <p className="text-[10px] text-ink/35 mt-0.5 font-bold">
                            {isAdmin ? t("inventory.editBranchHint") : `${t("inventory.referenceId")}: ${bookId}`}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                    {isAdmin && branchOptions.length > 0 && (
                        <FilterSelect
                            className="w-full min-w-[200px] sm:w-[240px]"
                            value={operationalBranchId ? String(operationalBranchId) : ""}
                            onChange={(value) => chooseBranch(Number(value))}
                            options={branchOptions}
                            icon={<Building2 className="h-3.5 w-3.5" />}
                            defaultValue="__none__"
                            placeholder={t("inventory.selectBranch")}
                        />
                    )}
                    <Button
                        variant="primary"
                        onClick={handleSave}
                        disabled={isSaving || isDeleting || isRefreshing}
                        className="h-11 px-5 rounded-xl font-black text-[11px] shrink-0"
                    >
                        <Save className="w-4 h-4 ms-1.5" />
                        {isSaving ? t("common.saving") : t("common.saveChanges")}
                    </Button>
                </div>
            </div>

            {error && (
                <div className="px-4 py-3 rounded-xl bg-rose-50 border border-rose-100 text-rose-600 text-[12px] font-vazirmatn flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                    <span>{error}</span>
                </div>
            )}

            <div className="relative overflow-hidden rounded-2xl border border-white/80 bg-white/70 backdrop-blur-xl shadow-sm">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(180,140,90,0.08),_transparent_55%)] pointer-events-none" />
                <div className="relative p-5 md:p-6 flex flex-col md:flex-row gap-5">
                    <div className="w-28 h-40 md:w-32 md:h-44 rounded-xl bg-parchment/50 border border-ink/5 overflow-hidden shrink-0 flex items-center justify-center shadow-inner">
                        {coverPreview ? (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img src={coverPreview} alt="" className="w-full h-full object-cover" />
                        ) : (
                            <BookIcon className="w-10 h-10 text-ink/15" />
                        )}
                    </div>

                    <div className="flex-1 min-w-0 space-y-3">
                        <div className="flex flex-wrap items-center gap-2">
                            {book.category && (
                                <Badge className="text-[9px] font-black">{book.category}</Badge>
                            )}
                            <span className={cn(
                                "text-[9px] font-black px-2 py-1 rounded-full border",
                                book.type === "consignment"
                                    ? "bg-amber-50 text-amber-600 border-amber-100"
                                    : "bg-sky-50 text-sky-600 border-sky-100"
                            )}>
                                {book.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}
                            </span>
                            {book.iraqOnly && (
                                <span className="text-[9px] font-black px-2 py-1 rounded-full border bg-emerald-50 text-emerald-700 border-emerald-100">
                                    {t("inventory.iraqOnly")}
                                </span>
                            )}
                        </div>

                        <div>
                            <h2 className="text-lg md:text-xl font-black font-vazirmatn text-ink leading-snug">
                                {book.title || t("inventory.bookTitle")}
                            </h2>
                            <p className="text-[13px] font-bold font-vazirmatn text-ink/45 mt-1">
                                {book.author || "—"}
                            </p>
                        </div>

                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 pt-1">
                            <MetaChip
                                icon={<Hash className="w-3.5 h-3.5" />}
                                label={t("inventory.isbn")}
                                value={book.isbn || t("inventory.noIsbn")}
                            />
                            <MetaChip
                                icon={<Building2 className="w-3.5 h-3.5" />}
                                label={t("inventory.publisher")}
                                value={book.publisher || "—"}
                            />
                            <MetaChip
                                icon={<Package className="w-3.5 h-3.5" />}
                                label={t("inventory.kpi.totalStock")}
                                value={formatNumber(totalStock)}
                            />
                        </div>

                        {stockByBranch.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 pt-1">
                                {stockByBranch.map((row) => {
                                    const isActive = Number(row.branchId) === Number(operationalBranchId);
                                    return (
                                        <button
                                            key={row.branchId || row.id}
                                            type="button"
                                            disabled={!isAdmin || !row.branchId}
                                            onClick={() => chooseBranch(row.branchId)}
                                            className={cn(
                                                "inline-flex items-center gap-1.5 text-[10px] font-bold font-vazirmatn px-2.5 py-1 rounded-lg border transition-colors",
                                                isActive
                                                    ? "bg-primary/10 border-primary/25 text-primary"
                                                    : "bg-parchment/40 border-ink/5 text-ink/60",
                                                isAdmin && "hover:border-primary/20 hover:text-ink cursor-pointer",
                                                !isAdmin && "cursor-default"
                                            )}
                                        >
                                            {row.name}
                                            <span className={cn(
                                                "tabular-nums font-black",
                                                isActive ? "text-primary" : "text-ink/80"
                                            )}>
                                                {formatNumber(row.quantity)}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="bg-white/60 backdrop-blur-xl border border-white/80 rounded-2xl p-5 md:p-6 shadow-sm space-y-4">
                <div className="flex items-center gap-3 border-b border-ink/5 pb-4">
                    <div className="p-2 bg-primary/5 rounded-lg">
                        <User className="w-4 h-4 text-primary" />
                    </div>
                    <div>
                        <h3 className="text-sm font-black font-vazirmatn text-ink">{t("inventory.wizard.supplierTitle")}</h3>
                        <p className="text-[10px] text-ink/30 font-bold uppercase tracking-wider mt-0.5">
                            {t("inventory.wizard.supplierDesc")}
                        </p>
                    </div>
                </div>
                <SupplierSelect
                  branchId={operationalBranchId ?? user?.branch_id ?? null}
                  onSelect={setSupplier}
                  selectedAccountId={supplier?.accountId}
                />
            </div>

            <div className="bg-white/60 backdrop-blur-xl border border-white/80 rounded-2xl p-5 md:p-6 shadow-sm">
                <div className="flex items-center gap-3 border-b border-ink/5 pb-4 mb-4">
                    <div className="p-2 bg-primary/5 rounded-lg">
                        <BookIcon className="w-4 h-4 text-primary" />
                    </div>
                    <div>
                        <h3 className="text-sm font-black font-vazirmatn text-ink">{t("inventory.bookDetails")}</h3>
                        <p className="text-[10px] text-ink/30 font-bold mt-0.5">{t("inventory.editCore")}</p>
                    </div>
                </div>
                <BookForm
                    data={book}
                    onChange={setBook}
                    readOnlyStock
                    readOnlyCost
                    readOnlyOwnership
                    catalogBranchId={operationalBranchId}
                />
            </div>

            {isAdmin && (
                <div className="rounded-2xl border border-rose-100 bg-rose-50/40 p-5 md:p-6 space-y-4">
                    <div className="flex items-start gap-3">
                        <div className="p-2 rounded-lg bg-rose-100/80 text-rose-600 shrink-0">
                            <ShieldAlert className="w-4 h-4" />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-sm font-black font-vazirmatn text-rose-700">
                                {t("inventory.dangerZone")}
                            </h3>
                            <p className="text-[11px] font-vazirmatn text-rose-600/80 mt-1 leading-relaxed">
                                {t("inventory.deleteBookHint")}
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        className="w-full sm:w-auto h-11 px-5 rounded-xl text-[11px] font-black border-rose-200 text-rose-600 bg-white/70 hover:bg-rose-50"
                        onClick={handleDelete}
                        disabled={isDeleting || isSaving}
                    >
                        <Trash2 className={cn("w-4 h-4 ms-1.5", isDeleting && "animate-pulse")} />
                        {isDeleting ? t("common.deleting") : t("inventory.deleteBook")}
                    </Button>
                </div>
            )}
        </motion.div>
    );
}

function MetaChip({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-xl bg-parchment/35 border border-ink/5 px-3 py-2.5 min-w-0">
            <div className="flex items-center gap-1.5 text-ink/35 mb-1">
                {icon}
                <span className="text-[9px] font-bold truncate">{label}</span>
            </div>
            <p className="text-[12px] font-black font-vazirmatn text-ink truncate">{value}</p>
        </div>
    );
}

export default function EditBookPage() {
    return (
        <Suspense
            fallback={
                <div className="max-w-5xl mx-auto space-y-4 pb-10">
                    <div className="h-28 rounded-2xl bg-parchment/30 animate-pulse" />
                    <div className="h-40 rounded-2xl bg-parchment/20 animate-pulse" />
                    <div className="h-96 rounded-2xl bg-parchment/20 animate-pulse" />
                </div>
            }
        >
            <EditBookContent />
        </Suspense>
    );
}
