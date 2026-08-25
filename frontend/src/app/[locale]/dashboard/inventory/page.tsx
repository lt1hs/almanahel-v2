"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Plus, Search, Book, ChevronLeft, ChevronRight,
    X, Hash, UserCircle, Edit3, Eye, Layers, LayoutGrid, Building2, RefreshCw,
    AlertTriangle, Package, Warehouse, Truck, PackagePlus,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { useRouter } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useNotify } from "@/hooks/useNotify";
import {
    assetValueByCurrency,
    collectPriceBands,
    emptyPriceBands,
    priceBandForBranch,
    PriceBandValues,
    resolveBookCoverUrl,
    resolveBranchId,
} from "@/lib/bookFormUtils";

const PAGE_SIZE = 25;

interface BranchStock {
    branch_id: number;
    branch_name: string;
    branch_type?: string;
    branch_city?: string;
    quantity: number;
    type?: string;
    supplier?: string;
    price_toman?: number | null;
    price_dinar?: number | null;
}

interface BookData {
    id: string;
    title: string;
    author: string;
    isbn: string;
    qty: number;
    type: string;
    priceBands: PriceBandValues;
    supplier: string;
    category: string;
    inventory_id?: number;
    by_branch?: BranchStock[];
    iraq_only?: boolean;
    low_stock_threshold?: number;
    publisher?: string;
    size?: string;
    cover?: string;
    publication_year?: string;
    cover_image?: string;
    weight?: number | string | null;
    weight_with_packaging?: number | string | null;
    volume_count?: number | null;
    description?: string;
    language?: string;
}

interface BranchOption {
    id: number;
    name: string;
    type: string;
    city?: string;
    is_central_warehouse?: boolean | null;
    is_intake_hub?: boolean | null;
    is_iraq_store?: boolean | null;
    supports_dinar?: boolean | null;
    supports_toman?: boolean | null;
}

function bandsFromApi(book: any, byBranch: BranchStock[]): PriceBandValues {
    const fromRows = collectPriceBands(byBranch);
    return {
        qom: Number(book.price_qom ?? fromRows.qom) || fromRows.qom,
        mashhad: Number(book.price_mashhad ?? fromRows.mashhad) || fromRows.mashhad,
        najaf: Number(book.price_dinar ?? fromRows.najaf) || fromRows.najaf,
    };
}

function mapOverviewBook(book: any): BookData {
    const byBranch: BranchStock[] = book.by_branch || [];
    return {
        id: String(book.id),
        title: book.title,
        author: book.author,
        isbn: book.isbn || "",
        qty: Number(book.total_qty || 0),
        type: byBranch[0]?.type || "owned",
        priceBands: bandsFromApi(book, byBranch),
        supplier: byBranch[0]?.supplier || "",
        category: book.category || "",
        by_branch: byBranch,
        iraq_only: book.iraq_only,
        low_stock_threshold: book.low_stock_threshold,
        publisher: book.publisher || "",
        size: book.size || "",
        cover: book.cover || "",
        publication_year: book.publication_year || "",
        cover_image: book.cover_image || "",
        weight: book.weight,
        weight_with_packaging: book.weight_with_packaging,
        volume_count: book.volume_count,
        description: book.description || "",
        language: book.language || "",
    };
}

function addStockPath(bookId: string, branchId: number | "overview", fallbackBranchId?: number | null) {
    const q = new URLSearchParams({ id: bookId });
    const resolved = typeof branchId === "number" ? branchId : fallbackBranchId;
    if (typeof resolved === "number" && resolved > 0) q.set("branch", String(resolved));
    return `/dashboard/inventory/add-stock?${q.toString()}`;
}

function transferPath(bookId: string, branchId: number | "overview", fallbackBranchId?: number | null) {
    const q = new URLSearchParams({ book: bookId });
    const resolved = typeof branchId === "number" ? branchId : fallbackBranchId;
    if (typeof resolved === "number" && resolved > 0) q.set("from", String(resolved));
    return `/dashboard/distribution?${q.toString()}`;
}

function mapInventoryRow(item: any, branch?: { name?: string; type?: string; city?: string } | null): BookData {
    if (item.book) {
        const toman = Number(item.price_toman || 0);
        const dinar = Number(item.price_dinar || 0);
        const bands = emptyPriceBands();
        const band = priceBandForBranch(branch || {});
        // Prefer currency for this POS — never treat toman as dinar (or vice versa)
        if (band === "najaf") {
            if (dinar > 0) bands.najaf = dinar;
            else if (toman > 0) bands.qom = toman;
        } else if (toman > 0) {
            bands[band] = toman;
        } else if (dinar > 0) {
            bands.najaf = dinar;
        }
        return {
            id: String(item.book.id),
            inventory_id: item.id,
            title: item.book.title,
            author: item.book.author,
            isbn: item.book.isbn || "",
            qty: Number(item.quantity || 0),
            type: item.type || "owned",
            priceBands: bands,
            supplier: item.supplier?.name || "",
            category: item.book.category || "",
            iraq_only: item.book.iraq_only,
            low_stock_threshold: item.book.low_stock_threshold,
            publisher: item.book.publisher || "",
            size: item.book.size || "",
            cover: item.book.cover || "",
            publication_year: item.book.publication_year || "",
            cover_image: item.book.cover_image || "",
            weight: item.book.weight,
            weight_with_packaging: item.book.weight_with_packaging,
            volume_count: item.book.volume_count,
            description: item.book.description || "",
            language: item.book.language || "",
        };
    }
    const inventories = item.inventories || [];
    const inv = inventories[0];
    return {
        id: String(item.id),
        title: item.title,
        author: item.author,
        isbn: item.isbn || "",
        qty: inventories.reduce((acc: number, row: any) => acc + Number(row.quantity || 0), 0),
        type: "owned",
        priceBands: collectPriceBands(
            inventories.map((row: any) => ({
                branch_type: row.branch?.type,
                branch_city: row.branch?.city,
                branch_name: row.branch?.name,
                price_toman: row.price_toman,
                price_dinar: row.price_dinar,
            }))
        ),
        supplier: inv?.supplier?.name || "",
        category: item.category || "",
        publisher: item.publisher || "",
        size: item.size || "",
        cover: item.cover || "",
        publication_year: item.publication_year || "",
        cover_image: item.cover_image || "",
        weight: item.weight,
        weight_with_packaging: item.weight_with_packaging,
        volume_count: item.volume_count,
        description: item.description || "",
        language: item.language || "",
    };
}

export default function InventoryPage() {
    const router = useRouter();
    const { t, formatNumber, language } = useTranslation();
    const { user } = useAuth();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;

    const [books, setBooks] = useState<BookData[]>([]);
    const [overviewCache, setOverviewCache] = useState<BookData[] | null>(null);
    const [selectedBook, setSelectedBook] = useState<BookData | null>(null);
    const [searchQuery, setSearchQuery] = useState("");
    const [typeFilter, setTypeFilter] = useState("all");
    const [categoryFilter, setCategoryFilter] = useState("all");
    const [stockFilter, setStockFilter] = useState<"all" | "low" | "out">("all");
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [branches, setBranches] = useState<BranchOption[]>([]);
    const [selectedBranchId, setSelectedBranchId] = useState<number | "overview">("overview");
    const [lowStockThreshold, setLowStockThreshold] = useState(5);
    const [canIntake, setCanIntake] = useState(false);
    const [page, setPage] = useState(1);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";
    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");
    const showPriceBands = isAdmin && selectedBranchId === "overview";

    const uniqueBranches = useMemo(() => {
        const seen = new Set<string>();
        return branches.filter((b) => {
            const key = b.name.trim().toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }, [branches]);

    const fallbackBranchId = useMemo(
        () => resolveBranchId(uniqueBranches, "qom")
            ?? resolveBranchId(uniqueBranches, "warehouse")
            ?? (uniqueBranches[0] ? Number(uniqueBranches[0].id) : null),
        [uniqueBranches]
    );

    const thresholdFor = useCallback(
        (book: BookData) => book.low_stock_threshold ?? lowStockThreshold,
        [lowStockThreshold]
    );

    const isLowStock = useCallback(
        (book: BookData) => book.qty > 0 && book.qty <= thresholdFor(book),
        [thresholdFor]
    );

    const categoryLabel = useCallback(
        (raw: string) => {
            if (!raw) return "—";
            const key = `inventory.categories.${raw}`;
            const translated = t(key);
            return translated === key ? raw : translated;
        },
        [t]
    );

    const fetchMeta = useCallback(async () => {
        const [settings, intakeInfo] = await Promise.all([
            apiRequest("/settings").catch(() => ({ low_stock_threshold: 5 })),
            apiRequest("/inventory/intake-info").catch(() => ({ can_intake: false })),
        ]);
        setLowStockThreshold(Number(settings.low_stock_threshold ?? 5));
        setCanIntake(Boolean(intakeInfo.can_intake || intakeInfo.can_intake_iraq_only));
    }, []);

    const fetchOverview = useCallback(async (soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            await fetchMeta();
            if (!isAdmin) {
                const branchId = user?.branch?.id ?? user?.branch_id;
                if (!branchId) {
                    setBooks([]);
                    notifyRef.current.error("inventory.branchRequired");
                    return;
                }
                const data = await apiRequest(`/warehouse/${branchId}/inventory`);
                const list = Array.isArray(data)
                    ? data.map((row: any) => mapInventoryRow(row, user?.branch))
                    : [];
                setBooks(list);
                setOverviewCache(null);
                return;
            }
            const overview = await apiRequest("/inventory/overview?include_zero=1");
            if (overview.low_stock_threshold != null) {
                setLowStockThreshold(Number(overview.low_stock_threshold));
            }
            setBranches(overview.branches || []);
            const mapped = (overview.books || []).map(mapOverviewBook);
            setOverviewCache(mapped);
            setBooks(mapped);
        } catch (error) {
            console.error("Failed to fetch inventory:", error);
            notifyRef.current.error("inventory.loadError");
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [fetchMeta, isAdmin, user?.branch]);

    const fetchBranchInventory = useCallback(async (branchId: number, soft = false) => {
        if (soft) setIsRefreshing(true);
        else setIsLoading(true);
        try {
            const data = await apiRequest(`/warehouse/${branchId}/inventory`);
            const branch = branches.find((b) => b.id === branchId) || user?.branch;
            setBooks((Array.isArray(data) ? data : []).map((row: any) => mapInventoryRow(row, branch)));
        } catch (error) {
            console.error("Failed to fetch branch inventory:", error);
            notifyRef.current.error("inventory.loadError");
            setBooks([]);
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [branches, user?.branch]);

    useEffect(() => {
        if (isAdmin && typeof selectedBranchId === "number") {
            fetchBranchInventory(selectedBranchId, books.length > 0);
        } else if (isAdmin && selectedBranchId === "overview" && overviewCache) {
            setBooks(overviewCache);
            setIsLoading(false);
        } else {
            fetchOverview();
        }
        // intentionally only re-run on branch/mode changes
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAdmin, selectedBranchId]);

    useEffect(() => {
        setPage(1);
    }, [searchQuery, typeFilter, categoryFilter, stockFilter, selectedBranchId]);

    const categoryOptions = useMemo(() => {
        const cats = Array.from(new Set(books.map((b) => b.category).filter(Boolean))).sort();
        return [
            { value: "all", label: t("inventory.allCategories") },
            ...cats.map((c) => ({ value: c, label: categoryLabel(c) })),
        ];
    }, [books, t, categoryLabel]);

    const typeOptions = useMemo(
        () => [
            { value: "all", label: t("inventory.allTypes") },
            { value: "owned", label: t("inventory.owned") },
            { value: "consignment", label: t("inventory.consignment") },
        ],
        [t]
    );

    const stockOptions = useMemo(
        () => [
            { value: "all", label: t("inventory.stockFilter.all") },
            { value: "low", label: t("inventory.stockFilter.low") },
            { value: "out", label: t("inventory.stockFilter.out") },
        ],
        [t]
    );

    const filteredBooks = useMemo(() => {
        let result = books;
        const q = searchQuery.trim().toLowerCase();
        if (q) {
            result = result.filter(
                (book) =>
                    book.title.toLowerCase().includes(q) ||
                    (book.author || "").toLowerCase().includes(q) ||
                    (book.isbn || "").toLowerCase().includes(q) ||
                    (book.supplier || "").toLowerCase().includes(q)
            );
        }
        if (typeFilter !== "all") result = result.filter((b) => b.type === typeFilter);
        if (categoryFilter !== "all") result = result.filter((b) => b.category === categoryFilter);
        if (stockFilter === "low") result = result.filter(isLowStock);
        if (stockFilter === "out") result = result.filter((b) => b.qty <= 0);
        return result;
    }, [books, searchQuery, typeFilter, categoryFilter, stockFilter, isLowStock]);

    const totalPages = Math.max(1, Math.ceil(filteredBooks.length / PAGE_SIZE));
    const safePage = Math.min(page, totalPages);
    const pageStart = (safePage - 1) * PAGE_SIZE;
    const pageBooks = filteredBooks.slice(pageStart, pageStart + PAGE_SIZE);

    const stats = useMemo(() => {
        const totalQty = books.reduce((a, b) => a + b.qty, 0);
        const low = books.filter(isLowStock).length;
        const out = books.filter((b) => b.qty <= 0).length;
        return {
            books: books.length,
            totalQty,
            low,
            out,
        };
    }, [books, isLowStock]);

    const refresh = () => {
        if (isAdmin && typeof selectedBranchId === "number") {
            fetchBranchInventory(selectedBranchId, true);
        } else {
            setOverviewCache(null);
            fetchOverview(true);
        }
    };

    const clearFilters = () => {
        setTypeFilter("all");
        setCategoryFilter("all");
        setStockFilter("all");
        setSearchQuery("");
    };

    const activeFilterCount =
        (typeFilter !== "all" ? 1 : 0) +
        (categoryFilter !== "all" ? 1 : 0) +
        (stockFilter !== "all" ? 1 : 0) +
        (searchQuery.trim() ? 1 : 0);

    const kpis = [
        {
            label: t("inventory.kpi.books"),
            value: stats.books,
            icon: Book,
            color: "text-primary",
            border: "border-primary/15",
            bg: "bg-primary/[0.04]",
        },
        {
            label: t("inventory.kpi.totalStock"),
            value: stats.totalQty,
            icon: Package,
            color: "text-emerald-600",
            border: "border-emerald-100",
            bg: "bg-emerald-50/40",
        },
        {
            label: t("inventory.kpi.lowStock"),
            value: stats.low,
            icon: AlertTriangle,
            color: "text-rose-500",
            border: "border-rose-100",
            bg: "bg-rose-50/40",
        },
        {
            label: t("inventory.kpi.outOfStock"),
            value: stats.out,
            icon: Warehouse,
            color: "text-amber-600",
            border: "border-amber-100",
            bg: "bg-amber-50/40",
        },
    ];

    const panelSide = language === "ar" ? "right-0 border-l" : "left-0 border-r";
    const selectedAssets = selectedBook
        ? (selectedBook.by_branch?.length
            ? assetValueByCurrency(selectedBook.by_branch)
            : {
                toman: (selectedBook.priceBands.qom + selectedBook.priceBands.mashhad) * selectedBook.qty,
                dinar: selectedBook.priceBands.najaf * selectedBook.qty,
            })
        : { toman: 0, dinar: 0 };

    return (
        <div className="relative min-h-[70vh]">
            <div className={cn("space-y-4 pb-8 transition-opacity", selectedBook && "opacity-40 pointer-events-none")}>
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-black font-vazirmatn text-ink flex items-center gap-2.5">
                            <span className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center">
                                <Book className="w-4 h-4 text-primary" />
                            </span>
                            {t("nav.inventory")}
                        </h1>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <button
                            type="button"
                            title={t("common.refresh")}
                            aria-label={t("common.refresh")}
                            disabled={isLoading || isRefreshing}
                            onClick={refresh}
                            className="h-9 w-9 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white shadow-sm disabled:opacity-40"
                        >
                            <RefreshCw className={cn("w-3.5 h-3.5 text-ink/40", (isLoading || isRefreshing) && "animate-spin")} />
                        </button>
                        <Button
                            variant="primary"
                            size="sm"
                            className="h-9 px-4 rounded-xl text-[11px] disabled:opacity-40"
                            disabled={!canIntake}
                            onClick={() => router.push("/dashboard/inventory/new")}
                        >
                            <Plus className="w-3.5 h-3.5 ms-1.5" />
                            {t("inventory.addBook")}
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
                                    {isLoading && !books.length ? "…" : formatNumber(kpi.value)}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-visible">
                    <CardContent className="p-3 flex flex-col md:flex-row gap-2.5">
                        <div className="flex-1 relative">
                            <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink/25" />
                            <input
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder={t("inventory.searchPlaceholder")}
                                className="w-full h-10 ps-9 pe-3 rounded-xl border border-ink/8 bg-white text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15"
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {isAdmin && uniqueBranches.length > 0 && (
                                <FilterSelect
                                    value={selectedBranchId === "overview" ? "overview" : String(selectedBranchId)}
                                    onChange={(v) =>
                                        setSelectedBranchId(v === "overview" ? "overview" : parseInt(v, 10))
                                    }
                                    options={[
                                        { value: "overview", label: t("inventory.allBranchesOverview") },
                                        ...uniqueBranches.map((b) => ({ value: String(b.id), label: b.name })),
                                    ]}
                                    icon={<Building2 className="w-3.5 h-3.5" />}
                                />
                            )}
                            <FilterSelect
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={typeOptions}
                                icon={<Layers className="w-3.5 h-3.5" />}
                            />
                            <FilterSelect
                                value={categoryFilter}
                                onChange={setCategoryFilter}
                                options={categoryOptions}
                                icon={<LayoutGrid className="w-3.5 h-3.5" />}
                            />
                            <FilterSelect
                                value={stockFilter}
                                onChange={(v) => setStockFilter(v as "all" | "low" | "out")}
                                options={stockOptions}
                                icon={<AlertTriangle className="w-3.5 h-3.5" />}
                            />
                            {activeFilterCount > 0 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-10 px-3 rounded-xl text-[10px] border-primary/20 text-primary"
                                    onClick={clearFilters}
                                >
                                    {t("common.clear")} ({formatNumber(activeFilterCount)})
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card className="border border-white/70 bg-white/70 rounded-2xl overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-end border-collapse">
                            <thead>
                                <tr className="bg-parchment/20 text-ink/40 border-b border-ink/5">
                                    <th className="p-3 font-black text-[10px]">{t("inventory.bookTitle")}</th>
                                    <th className="p-3 font-black text-[10px] hidden md:table-cell">{t("inventory.author")}</th>
                                    <th className="p-3 font-black text-[10px]">{t("inventory.type")}</th>
                                    <th className="p-3 font-black text-[10px] hidden lg:table-cell">{t("inventory.supplier")}</th>
                                    <th className="p-3 font-black text-[10px] text-center">{t("inventory.stock")}</th>
                                    <th className="p-3 font-black text-[10px] text-start hidden sm:table-cell">{t("inventory.price")}</th>
                                    <th className="p-3 font-black text-[10px] text-center">{t("common.actions")}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink/5">
                                {isLoading && !books.length ? (
                                    Array.from({ length: 6 }).map((_, i) => (
                                        <tr key={i}>
                                            <td colSpan={7} className="p-4">
                                                <div className="h-12 bg-parchment/20 rounded-xl animate-pulse" />
                                            </td>
                                        </tr>
                                    ))
                                ) : pageBooks.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="p-16 text-center text-ink/25">
                                            <Search className="w-8 h-8 mx-auto mb-2 opacity-40" />
                                            <p className="text-[12px] font-black font-vazirmatn">{t("common.noResults")}</p>
                                        </td>
                                    </tr>
                                ) : (
                                    pageBooks.map((book) => (
                                        <tr
                                            key={book.id}
                                            className="hover:bg-white/60 transition-colors cursor-pointer"
                                            onClick={() => setSelectedBook(book)}
                                        >
                                            <td className="p-3">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <div className="w-9 h-12 rounded-lg bg-parchment/40 border border-ink/5 flex items-center justify-center shrink-0 overflow-hidden">
                                                        {resolveBookCoverUrl(book.cover_image) ? (
                                                            // eslint-disable-next-line @next/next/no-img-element
                                                            <img
                                                                src={resolveBookCoverUrl(book.cover_image) || ""}
                                                                alt={book.title}
                                                                className="w-full h-full object-cover"
                                                            />
                                                        ) : (
                                                            <Book className="w-4 h-4 text-ink/15" />
                                                        )}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-vazirmatn font-black text-[13px] text-ink truncate">
                                                            {book.title}
                                                        </p>
                                                        <p className="text-[9px] text-ink/30 mt-0.5 font-vazirmatn truncate">
                                                            {book.isbn || t("inventory.noIsbn")}
                                                            {book.iraq_only ? ` · ${t("inventory.iraqOnly")}` : ""}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="p-3 hidden md:table-cell">
                                                <span className="text-[12px] font-vazirmatn font-bold text-ink/55">
                                                    {book.author || "—"}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                <span className={cn(
                                                    "text-[9px] font-black px-2 py-1 rounded-full border",
                                                    book.type === "consignment"
                                                        ? "bg-amber-50 text-amber-600 border-amber-100"
                                                        : "bg-sky-50 text-sky-600 border-sky-100"
                                                )}>
                                                    {book.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}
                                                </span>
                                            </td>
                                            <td className="p-3 hidden lg:table-cell">
                                                <span className="text-[11px] font-vazirmatn font-bold text-ink/50 truncate block max-w-[140px]">
                                                    {book.supplier || "—"}
                                                </span>
                                            </td>
                                            <td className="p-3 text-center">
                                                <div className="inline-flex flex-col items-center">
                                                    <span className={cn(
                                                        "text-[14px] font-black font-vazirmatn tabular-nums",
                                                        isLowStock(book) || book.qty <= 0 ? "text-rose-500" : "text-ink/80"
                                                    )}>
                                                        {formatNumber(book.qty)}
                                                    </span>
                                                    {isLowStock(book) && (
                                                        <span className="text-[8px] font-black text-rose-500 mt-0.5 px-1.5 py-0.5 bg-rose-50 rounded">
                                                            {t("inventory.lowStock")}
                                                        </span>
                                                    )}
                                                    {book.qty <= 0 && (
                                                        <span className="text-[8px] font-black text-ink/30 mt-0.5">
                                                            {t("inventory.outOfStock")}
                                                        </span>
                                                    )}
                                                    {isAdmin && selectedBranchId === "overview" && book.by_branch && book.by_branch.length > 0 && (
                                                        <span className="text-[8px] font-bold text-ink/30 mt-1 max-w-[130px] truncate">
                                                            {book.by_branch
                                                                .map((b) => `${(b.branch_name || "").split(" ").pop()}:${formatNumber(b.quantity)}`)
                                                                .join(" · ")}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="p-3 text-start hidden sm:table-cell">
                                                <PriceBandList
                                                    bands={book.priceBands}
                                                    formatNumber={formatNumber}
                                                    t={t}
                                                    tomanSymbol={tomanSymbol}
                                                    dinarSymbol={dinarSymbol}
                                                    labeled={showPriceBands}
                                                />
                                            </td>
                                            <td className="p-3">
                                                <div className="flex items-center justify-center gap-0.5" onClick={(e) => e.stopPropagation()}>
                                                    <button
                                                        type="button"
                                                        className="p-2 rounded-lg text-ink/30 hover:text-primary hover:bg-primary/5"
                                                        title={t("common.details")}
                                                        onClick={() => setSelectedBook(book)}
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="p-2 rounded-lg text-ink/30 hover:text-primary hover:bg-primary/5"
                                                        title={t("inventory.editBook")}
                                                        onClick={() => router.push(`/dashboard/inventory/edit?id=${book.id}`)}
                                                    >
                                                        <Edit3 className="w-4 h-4" />
                                                    </button>
                                                    {isAdmin && (
                                                        <button
                                                            type="button"
                                                            className="p-2 rounded-lg text-ink/30 hover:text-sky-700 hover:bg-sky-50"
                                                            title={t("inventory.addStock")}
                                                            onClick={() => router.push(addStockPath(book.id, selectedBranchId, fallbackBranchId))}
                                                        >
                                                            <PackagePlus className="w-4 h-4" />
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        className="p-2 rounded-lg text-ink/30 hover:text-emerald-600 hover:bg-emerald-50"
                                                        title={t("inventory.transfer")}
                                                        onClick={() => router.push(transferPath(book.id, selectedBranchId, fallbackBranchId))}
                                                    >
                                                        <Truck className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <div className="flex items-center justify-between gap-3 px-1">
                    <span className="text-[10px] font-bold text-ink/30 font-vazirmatn">
                        {t("inventory.showingEntries", {
                            start: filteredBooks.length ? formatNumber(pageStart + 1) : formatNumber(0),
                            end: formatNumber(Math.min(pageStart + PAGE_SIZE, filteredBooks.length)),
                            total: formatNumber(filteredBooks.length),
                        })}
                    </span>
                    <div className="flex items-center gap-1.5">
                        <button
                            type="button"
                            disabled={safePage <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            className="h-9 px-3 rounded-xl border border-ink/8 bg-white/70 text-[10px] font-black disabled:opacity-30 flex items-center gap-1"
                        >
                            <ChevronRight className="w-3.5 h-3.5" />
                            {t("common.previous")}
                        </button>
                        <span className="min-w-[2.5rem] h-9 px-2 rounded-xl bg-primary text-white text-[11px] font-black font-vazirmatn tabular-nums flex items-center justify-center">
                            {formatNumber(safePage)}
                        </span>
                        <span className="text-[10px] text-ink/30 font-vazirmatn">/ {formatNumber(totalPages)}</span>
                        <button
                            type="button"
                            disabled={safePage >= totalPages}
                            onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                            className="h-9 px-3 rounded-xl border border-ink/8 bg-white/70 text-[10px] font-black disabled:opacity-30 flex items-center gap-1"
                        >
                            {t("common.next")}
                            <ChevronLeft className="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            {selectedBook && (
                <>
                    <div
                        className="fixed inset-0 bg-ink/20 backdrop-blur-[2px] z-[100]"
                        onClick={() => setSelectedBook(null)}
                    />
                    <div
                        className={cn(
                            "fixed top-0 bottom-0 w-full max-w-md bg-white/95 backdrop-blur-xl shadow-2xl z-[101] overflow-hidden flex flex-col border-ink/5",
                            panelSide
                        )}
                    >
                        <div className="p-5 border-b border-ink/5 flex items-center justify-between bg-parchment/20">
                            <div>
                                <h2 className="text-lg font-black font-vazirmatn text-ink">{t("inventory.bookDetails")}</h2>
                                <p className="text-[10px] text-ink/30 font-vazirmatn mt-0.5">
                                    {t("inventory.referenceId")}: {selectedBook.id}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedBook(null)}
                                className="p-2.5 hover:bg-rose-50 rounded-xl text-ink/30 hover:text-rose-500"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-5 space-y-5">
                            <div className="flex items-start gap-4">
                                <div className="w-20 h-28 bg-parchment border border-ink/5 rounded-xl flex items-center justify-center shrink-0 overflow-hidden">
                                    {resolveBookCoverUrl(selectedBook.cover_image) ? (
                                        // eslint-disable-next-line @next/next/no-img-element
                                        <img
                                            src={resolveBookCoverUrl(selectedBook.cover_image) || ""}
                                            alt={selectedBook.title}
                                            className="w-full h-full object-cover"
                                        />
                                    ) : (
                                        <Book className="w-8 h-8 text-ink/10" />
                                    )}
                                </div>
                                <div className="min-w-0 pt-1">
                                    {selectedBook.category && (
                                        <Badge className="mb-2 text-[9px]">{categoryLabel(selectedBook.category)}</Badge>
                                    )}
                                    <h3 className="text-base font-black font-vazirmatn text-ink leading-tight">
                                        {selectedBook.title}
                                    </h3>
                                    <div className="flex items-center gap-2 mt-2 text-ink/45">
                                        <UserCircle className="w-4 h-4" />
                                        <span className="text-[12px] font-vazirmatn font-bold">{selectedBook.author || "—"}</span>
                                    </div>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <DetailValue label={t("inventory.form.publisher")} value={selectedBook.publisher} />
                                <DetailValue label={t("inventory.form.publicationYear")} value={selectedBook.publication_year} />
                                <DetailValue label={t("inventory.form.size")} value={selectedBook.size} />
                                <DetailValue label={t("inventory.form.cover")} value={selectedBook.cover} />
                                <DetailValue
                                    label={t("inventory.form.volumeCount")}
                                    value={selectedBook.volume_count != null ? formatNumber(selectedBook.volume_count) : ""}
                                />
                                <DetailValue
                                    label={t("inventory.form.weight")}
                                    value={selectedBook.weight != null ? formatNumber(Number(selectedBook.weight)) : ""}
                                />
                                <DetailValue
                                    label={t("inventory.form.weightWithPackaging")}
                                    value={selectedBook.weight_with_packaging != null ? formatNumber(Number(selectedBook.weight_with_packaging)) : ""}
                                />
                                <DetailValue
                                    label={t("inventory.form.lowStockThreshold")}
                                    value={selectedBook.low_stock_threshold != null ? formatNumber(selectedBook.low_stock_threshold) : ""}
                                />
                            </div>

                            {selectedBook.description && (
                                <div className="p-4 rounded-xl bg-white border border-ink/5">
                                    <h4 className="text-[10px] font-black text-ink/30 mb-2">{t("inventory.form.notes")}</h4>
                                    <p className="text-[12px] leading-6 font-vazirmatn text-ink/65 whitespace-pre-wrap">
                                        {selectedBook.description}
                                    </p>
                                </div>
                            )}

                            <div className="p-4 rounded-xl bg-parchment/30 border border-ink/5 space-y-3">
                                <h4 className="text-[10px] font-black text-ink/30">{t("inventory.stockManagement")}</h4>
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className={cn(
                                            "text-2xl font-black font-vazirmatn tabular-nums",
                                            isLowStock(selectedBook) || selectedBook.qty <= 0 ? "text-rose-500" : "text-ink"
                                        )}>
                                            {formatNumber(selectedBook.qty)}
                                            <span className="text-[11px] font-bold text-ink/30 ms-1.5">
                                                {t("common.units.volume")}
                                            </span>
                                        </p>
                                        {isLowStock(selectedBook) && (
                                            <p className="text-[9px] font-black text-rose-500 mt-1">{t("inventory.lowStock")}</p>
                                        )}
                                        {selectedBook.qty <= 0 && (
                                            <p className="text-[9px] font-black text-ink/35 mt-1">{t("inventory.outOfStock")}</p>
                                        )}
                                    </div>
                                    <Badge className={cn(
                                        "text-[9px] font-black",
                                        selectedBook.type === "consignment"
                                            ? "bg-amber-50 text-amber-600 border-amber-100"
                                            : "bg-sky-50 text-sky-600 border-sky-100"
                                    )}>
                                        {selectedBook.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}
                                    </Badge>
                                </div>
                            </div>

                            {selectedBook.by_branch && selectedBook.by_branch.length > 0 && (
                                <div className="p-4 rounded-xl bg-white border border-ink/5 space-y-1">
                                    <h4 className="text-[10px] font-black text-ink/30 mb-2">{t("inventory.stockByBranch")}</h4>
                                    {selectedBook.by_branch
                                        .slice()
                                        .sort((a, b) => Number(b.quantity || 0) - Number(a.quantity || 0))
                                        .map((b) => {
                                            const qty = Number(b.quantity || 0);
                                            const rowDinar = Number(b.price_dinar || 0);
                                            const rowToman = Number(b.price_toman || 0);
                                            const unitPrice = rowDinar > 0 ? rowDinar : rowToman;
                                            const unitSymbol = rowDinar > 0 ? dinarSymbol : tomanSymbol;
                                            return (
                                                <div
                                                    key={b.branch_id}
                                                    className="flex items-center justify-between gap-3 py-2.5 border-b border-ink/5 last:border-0"
                                                >
                                                    <div className="min-w-0">
                                                        <p className="text-[12px] font-bold font-vazirmatn text-ink/80 truncate">
                                                            {b.branch_name}
                                                        </p>
                                                        {unitPrice > 0 && (
                                                            <p className="text-[9px] font-vazirmatn text-ink/30 mt-0.5 tabular-nums">
                                                                {t("inventory.sellingPrice")}: {formatNumber(unitPrice)} {unitSymbol}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="text-end shrink-0">
                                                        <p className={cn(
                                                            "text-[15px] font-black font-vazirmatn tabular-nums leading-none",
                                                            qty <= 0 ? "text-ink/25" : "text-ink"
                                                        )}>
                                                            {formatNumber(qty)}
                                                        </p>
                                                        <p className="text-[8px] font-bold text-ink/30 mt-1">
                                                            {t("common.units.volume")}
                                                        </p>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                </div>
                            )}

                            <div className="space-y-1">
                                <MetaRow icon={<Hash className="w-3.5 h-3.5" />} label={t("inventory.isbn")} value={selectedBook.isbn || t("inventory.noIsbn")} />
                                <MetaRow icon={<Building2 className="w-3.5 h-3.5" />} label={t("inventory.supplier")} value={selectedBook.supplier || "—"} />
                            </div>

                            <div className="p-4 rounded-xl bg-white border border-ink/5 space-y-2">
                                <h4 className="text-[10px] font-black text-ink/30">{t("inventory.priceByPos")}</h4>
                                <PriceBandList
                                    bands={selectedBook.priceBands}
                                    formatNumber={formatNumber}
                                    t={t}
                                    tomanSymbol={tomanSymbol}
                                    dinarSymbol={dinarSymbol}
                                    labeled
                                    stacked
                                />
                            </div>

                            {(selectedAssets.toman > 0 || selectedAssets.dinar > 0) && (
                                <div className="p-4 rounded-xl bg-ink text-white space-y-2">
                                    <p className="text-[9px] font-bold text-white/35">{t("inventory.totalAssetValue")}</p>
                                    {selectedAssets.toman > 0 && (
                                        <p className="text-xl font-black font-vazirmatn tabular-nums">
                                            {formatNumber(selectedAssets.toman)}
                                            <span className="text-[10px] text-white/40 ms-1">{tomanSymbol}</span>
                                        </p>
                                    )}
                                    {selectedAssets.dinar > 0 && (
                                        <p className="text-xl font-black font-vazirmatn tabular-nums">
                                            {formatNumber(selectedAssets.dinar)}
                                            <span className="text-[10px] text-white/40 ms-1">{dinarSymbol}</span>
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="p-4 bg-parchment/30 border-t border-ink/5 space-y-2">
                            <Button
                                variant="primary"
                                className="w-full h-11 rounded-xl text-[11px] font-black"
                                onClick={() => router.push(`/dashboard/inventory/edit?id=${selectedBook.id}`)}
                            >
                                <Edit3 className="w-4 h-4 ms-1.5" />
                                {t("inventory.editCore")}
                            </Button>
                            <Button
                                variant="outline"
                                className="w-full h-11 rounded-xl text-[11px] font-black"
                                onClick={() => router.push(transferPath(selectedBook.id, selectedBranchId, fallbackBranchId))}
                            >
                                <Truck className="w-4 h-4 ms-1.5" />
                                {t("inventory.transfer")}
                            </Button>
                            {isAdmin && (
                                <Button
                                    variant="outline"
                                    className="w-full h-11 rounded-xl text-[11px] font-black"
                                    onClick={() => router.push(addStockPath(selectedBook.id, selectedBranchId, fallbackBranchId))}
                                >
                                    <PackagePlus className="w-4 h-4 ms-1.5" />
                                    {t("inventory.addStock")}
                                </Button>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

function PriceBandList({
    bands,
    formatNumber,
    t,
    tomanSymbol,
    dinarSymbol,
    labeled = false,
    stacked = false,
}: {
    bands: PriceBandValues;
    formatNumber: (n: number) => string;
    t: (key: string) => string;
    tomanSymbol: string;
    dinarSymbol: string;
    labeled?: boolean;
    stacked?: boolean;
}) {
    const rows = (
        [
            { key: "qom", value: bands.qom, symbol: tomanSymbol },
            { key: "mashhad", value: bands.mashhad, symbol: tomanSymbol },
            { key: "najaf", value: bands.najaf, symbol: dinarSymbol },
        ] as const
    ).filter((row) => row.value > 0);

    if (!rows.length) {
        return <span className="text-[13px] font-black text-ink/25">—</span>;
    }

    if (!labeled && rows.length === 1) {
        return (
            <span className="text-[13px] font-black font-vazirmatn tabular-nums text-primary/80">
                {formatNumber(rows[0].value)}
            </span>
        );
    }

    return (
        <div className={cn("flex items-start gap-1", stacked ? "flex-col gap-1.5" : "flex-col")}>
            {rows.map((row) => (
                <span
                    key={row.key}
                    className="text-[12px] font-black font-vazirmatn tabular-nums text-primary/80 leading-tight"
                >
                    {formatNumber(row.value)}
                    <span className="text-[8px] font-bold text-ink/35 ms-1">
                        {row.symbol} · {t(`inventory.priceBands.${row.key}`)}
                    </span>
                </span>
            ))}
        </div>
    );
}

function DetailValue({ label, value }: { label: string; value?: string | null }) {
    return (
        <div className="rounded-xl bg-parchment/25 border border-ink/5 px-3 py-2.5 min-w-0">
            <p className="text-[9px] font-bold text-ink/30 truncate">{label}</p>
            <p className="text-[11px] font-black font-vazirmatn text-ink/75 mt-1 truncate">
                {value || "—"}
            </p>
        </div>
    );
}

function MetaRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="flex items-center justify-between py-3 border-b border-ink/5">
            <div className="flex items-center gap-2 text-ink/40">
                {icon}
                <span className="text-[11px] font-bold">{label}</span>
            </div>
            <span className="text-[12px] font-vazirmatn font-black text-ink">{value}</span>
        </div>
    );
}
