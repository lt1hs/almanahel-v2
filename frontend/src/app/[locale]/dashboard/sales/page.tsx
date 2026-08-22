"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import {
    Search, Scan, Book, CreditCard, Banknote, TrendingUp, Receipt, X, Plus,
    RefreshCw, AlertTriangle, Store, ShoppingBag, Printer,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { Cart, PaymentDetails } from "@/components/sales/Cart";
import dynamic from "next/dynamic";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { Link } from "@/i18n/routing";
import { printInvoice, buildInvoicePrintLabels } from "@/lib/printInvoice";

const ScannerModal = dynamic(
    () => import("@/components/inventory/ScannerModal").then((m) => m.ScannerModal),
    { ssr: false }
);

const LOW_STOCK = 5;
const EMPTY_PAYMENT: PaymentDetails = {
    customer_id: null,
    customer_name: "",
    customer_phone: "",
    notes: "",
    check_number: "",
    bank_name: "",
    payer_name: "",
    payer_phone: "",
    due_date: "",
};

type PaymentMethod = "cash" | "check" | "credit";
type StockFilter = "all" | "available" | "low" | "out";
type TypeFilter = "all" | "owned" | "consignment";

interface SalesBook {
    id: string;
    inventory_id?: number;
    title: string;
    author: string;
    isbn: string;
    price: number;
    stock: number;
    type: "owned" | "consignment";
}

interface CartBook extends SalesBook {
    quantity: number;
}

function mapInventoryItem(item: any, isDinar: boolean): SalesBook | null {
    if (item.book) {
        return {
            id: String(item.book.id),
            inventory_id: item.id,
            title: item.book.title,
            author: item.book.author || "",
            isbn: item.book.isbn || "",
            price: isDinar ? Number(item.price_dinar || 0) : Number(item.price_toman || 0),
            stock: Number(item.quantity || 0),
            type: item.type === "consignment" ? "consignment" : "owned",
        };
    }
    if (item.id && item.title) {
        const branchInv = item.inventories?.[0];
        return {
            id: String(item.id),
            inventory_id: branchInv?.id,
            title: item.title,
            author: item.author || "",
            isbn: item.isbn || "",
            price: isDinar
                ? Number(branchInv?.price_dinar || 0)
                : Number(branchInv?.price_toman || 0),
            stock: Number(branchInv?.quantity || item.total_qty || 0),
            type: branchInv?.type === "consignment" ? "consignment" : "owned",
        };
    }
    return null;
}

function resolveBranchId(user: { branch?: { id: number } | null; branch_id?: number | null } | null): number | undefined {
    if (!user) return undefined;
    return user.branch?.id ?? user.branch_id ?? undefined;
}

export default function SalesPage() {
    const { t, formatNumber, isArabic, isDinar, preferredCurrency } = useTranslation();
    const notify = useNotify();
    const { user, isLoading: authLoading } = useAuth();

    const [search, setSearch] = useState("");
    const [typeFilter, setTypeFilter] = useState<TypeFilter>("all");
    const [stockFilter, setStockFilter] = useState<StockFilter>("all");
    const [payment, setPayment] = useState<PaymentMethod>("cash");
    const [inventory, setInventory] = useState<SalesBook[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [stats, setStats] = useState({ todaySales: 0, invoiceCount: 0 });
    const [cartItems, setCartItems] = useState<CartBook[]>([]);
    const [paymentDetails, setPaymentDetails] = useState<PaymentDetails>(EMPTY_PAYMENT);
    const [scannerOpen, setScannerOpen] = useState(false);
    const [recentInvoices, setRecentInvoices] = useState<any[]>([]);
    const [salesBranches, setSalesBranches] = useState<any[]>([]);
    const [invoiceBranchFilter, setInvoiceBranchFilter] = useState<string>("all");
    const [printingId, setPrintingId] = useState<number | null>(null);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";
    const branchId = resolveBranchId(user);
    const branchName = user?.branch?.name;

    const getBookStock = useCallback(
        (bookId: string) => inventory.find((b) => b.id === bookId)?.stock ?? 0,
        [inventory]
    );

    const getCartQty = useCallback(
        (bookId: string) => cartItems.find((c) => c.id === bookId)?.quantity ?? 0,
        [cartItems]
    );

    const getRemainingStock = useCallback(
        (bookId: string) => Math.max(0, getBookStock(bookId) - getCartQty(bookId)),
        [getBookStock, getCartQty]
    );

    const addToCart = useCallback((book: SalesBook) => {
        if (book.stock <= 0) {
            notify.error("inventory.outOfStock");
            return;
        }

        setCartItems((prev) => {
            const existing = prev.find((item) => item.id === book.id);
            const currentQty = existing?.quantity ?? 0;
            if (currentQty >= book.stock) {
                notify.error("sales.insufficientStock");
                return prev;
            }
            if (existing) {
                return prev.map((item) =>
                    item.id === book.id ? { ...item, quantity: item.quantity + 1, stock: book.stock } : item
                );
            }
            return [...prev, { ...book, quantity: 1 }];
        });
    }, [notify]);

    const updateCartQty = useCallback((id: string, delta: number) => {
        setCartItems((prev) =>
            prev.map((item) => {
                if (item.id !== id) return item;
                const maxStock = getBookStock(id);
                const next = item.quantity + delta;
                if (next < 1) return item;
                if (next > maxStock) {
                    notify.error("sales.insufficientStock");
                    return item;
                }
                return { ...item, quantity: next, stock: maxStock };
            })
        );
    }, [getBookStock, notify]);

    const removeFromCart = useCallback((id: string) => {
        setCartItems((prev) => prev.filter((item) => item.id !== id));
    }, []);

    const clearCart = useCallback(() => {
        setCartItems([]);
        setPaymentDetails(EMPTY_PAYMENT);
    }, []);

    const fetchData = useCallback(async (refresh = false) => {
        if (authLoading) return;

        if (refresh) setIsRefreshing(true);
        else setIsLoading(true);
        setLoadError(null);

        try {
            const inventoryEndpoint = branchId
                ? `/warehouse/${branchId}/inventory`
                : "/books";

            const invData = await apiRequest(inventoryEndpoint);

            const mapped = (invData || [])
                .map((item: any) => mapInventoryItem(item, isDinar))
                .filter(Boolean) as SalesBook[];

            setInventory(mapped);

            try {
                const dashboard = await apiRequest("/reports/dashboard");
                setStats({
                    todaySales: isDinar ? dashboard.today_sales_dinar : dashboard.today_sales_toman,
                    invoiceCount: dashboard.today_invoice_count || 0,
                });
            } catch (statsError) {
                console.error("Failed to fetch sales stats:", statsError);
            }

            setCartItems((prev) =>
                prev.map((item) => {
                    const fresh = mapped.find((b) => b.id === item.id);
                    if (!fresh) return item;
                    return {
                        ...item,
                        stock: fresh.stock,
                        price: fresh.price,
                        quantity: Math.min(item.quantity, fresh.stock),
                    };
                }).filter((item) => {
                    const fresh = mapped.find((b) => b.id === item.id);
                    return fresh && fresh.stock > 0;
                })
            );
        } catch (error) {
            console.error("Failed to fetch sales data:", error);
            setLoadError(t("sales.loadError"));
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [authLoading, branchId, isDinar, t]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const fetchInvoices = useCallback(async () => {
        try {
            const params = new URLSearchParams();
            if (isAdmin && invoiceBranchFilter !== "all") {
                params.set("branch_id", invoiceBranchFilter);
            }
            const qs = params.toString();
            const data = await apiRequest(`/invoices${qs ? `?${qs}` : ""}`);
            setRecentInvoices(data.data || data || []);
        } catch (error) {
            console.error("Failed to fetch invoices:", error);
        }
    }, [isAdmin, invoiceBranchFilter]);

    useEffect(() => {
        if (isAdmin) {
            apiRequest("/branches").then((b) => setSalesBranches(Array.isArray(b) ? b.filter((x: any) => x.type === "store") : [])).catch(() => {});
        }
    }, [isAdmin]);

    useEffect(() => {
        fetchInvoices();
    }, [fetchInvoices]);

    const handlePrintInvoice = async (e: React.MouseEvent, invoiceId: number) => {
        e.preventDefault();
        e.stopPropagation();
        setPrintingId(invoiceId);
        try {
            const data = await apiRequest(`/invoices/${invoiceId}`);
            printInvoice(data, {
                formatNumber,
                currencySymbol: data.currency === "dinar"
                    ? t("common.currency.dinarSymbol")
                    : t("common.currency.tomanSymbol"),
                labels: buildInvoicePrintLabels(t),
                dir: "rtl",
            });
        } catch (error) {
            console.error("Print invoice failed:", error);
            notify.error("toast.invoicePrintError");
        } finally {
            setPrintingId(null);
        }
    };

    const handleCheckout = async (payload: Record<string, unknown>) => {
        if (!branchId) {
            notify.error("toast.branchNotSet");
            return;
        }
        try {
            await apiRequest("/invoices", {
                method: "POST",
                body: JSON.stringify({
                    ...payload,
                    branch_id: branchId,
                    payment_method: payment,
                }),
            });
            clearCart();
            fetchData(true);
            fetchInvoices();
            notify.success("toast.invoiceSuccess");
        } catch (error) {
            console.error("Checkout failed:", error);
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.invoiceError");
        }
    };

    const lookupBarcode = async (code: string) => {
        const trimmed = code.trim();
        if (!trimmed) return;

        const local = inventory.find((b) => b.isbn === trimmed);
        if (local) {
            addToCart(local);
            notify.success("toast.addedToCart", { title: local.title });
            setSearch("");
            return;
        }

        try {
            const book = await apiRequest(
                `/books/by-barcode/${encodeURIComponent(trimmed)}${branchId ? `?branch_id=${branchId}` : ""}`
            );
            const branchInv = branchId
                ? book.inventories?.find((inv: any) => inv.branch_id === branchId)
                : book.inventories?.[0];
            const mapped: SalesBook = {
                id: String(book.id),
                title: book.title,
                author: book.author || "",
                price: isDinar ? (branchInv?.price_dinar || 0) : (branchInv?.price_toman || 0),
                stock: branchInv?.quantity ?? 0,
                type: branchInv?.type === "consignment" ? "consignment" : "owned",
                isbn: book.isbn || trimmed,
            };
            if (mapped.stock <= 0) {
                notify.error("toast.bookNotInBranch");
                return;
            }
            addToCart(mapped);
            notify.success("toast.addedToCart", { title: mapped.title });
            setSearch("");
        } catch {
            notify.error("toast.barcodeNotFound");
        }
    };

    const handleSearchKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key !== "Enter" || !search.trim()) return;
        const q = search.trim();
        const looksLikeBarcode = /^\d{8,}$/.test(q);
        if (looksLikeBarcode) {
            lookupBarcode(q);
            return;
        }
        const exactIsbn = inventory.find((b) => b.isbn === q);
        if (exactIsbn) {
            addToCart(exactIsbn);
            notify.success("toast.addedToCart", { title: exactIsbn.title });
            setSearch("");
        }
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        let result = inventory.filter((b) => {
            if (!q) return true;
            return (
                b.title.toLowerCase().includes(q) ||
                b.author.toLowerCase().includes(q) ||
                b.isbn.toLowerCase().includes(q)
            );
        });

        if (typeFilter !== "all") {
            result = result.filter((b) => b.type === typeFilter);
        }

        if (stockFilter === "available") result = result.filter((b) => b.stock > 0);
        else if (stockFilter === "low") result = result.filter((b) => b.stock > 0 && b.stock < LOW_STOCK);
        else if (stockFilter === "out") result = result.filter((b) => b.stock <= 0);

        return result.sort((a, b) => {
            if (a.stock <= 0 && b.stock > 0) return 1;
            if (a.stock > 0 && b.stock <= 0) return -1;
            return a.title.localeCompare(b.title, "fa");
        });
    }, [inventory, search, typeFilter, stockFilter]);

    const cartCount = cartItems.reduce((sum, i) => sum + i.quantity, 0);
    const currencySymbol = isDinar ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");
    const hasActiveFilters = typeFilter !== "all" || stockFilter !== "all";

    return (
        <div className="space-y-5">
        <div className="flex flex-col lg:flex-row lg:items-start gap-5">
            {/* Products panel */}
            <div className="flex-1 min-w-0 flex flex-col gap-4">
                <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-black font-vazirmatn text-ink">{t("sales.panelTitle")}</h1>
                        <p className="text-[10px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">
                            {t("sales.subtitle")}
                        </p>
                        {branchName && (
                            <div className="flex items-center gap-1.5 mt-2 text-[10px] font-bold text-ink/45">
                                <Store className="w-3 h-3 text-primary/60" />
                                <span>{t("sales.branchLabel", { name: branchName })}</span>
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0 flex-wrap">
                        <StatBadge
                            icon={<TrendingUp className="w-3 h-3" />}
                            label={t("sales.todaySales")}
                            value={`${formatNumber(stats.todaySales)} ${isDinar ? t("common.dinar") : t("common.toman")}`}
                            className="text-primary"
                        />
                        <StatBadge
                            icon={<Receipt className="w-3 h-3" />}
                            label={t("sales.invoiceCount")}
                            value={formatNumber(stats.invoiceCount)}
                            className="text-accent"
                        />
                        <button
                            type="button"
                            onClick={() => fetchData(true)}
                            disabled={isRefreshing || !branchId}
                            title={t("common.refresh")}
                            aria-label={t("common.refresh")}
                            className="h-10 w-10 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white hover:shadow-md transition-all active:scale-95 shadow-sm disabled:opacity-40"
                        >
                            <RefreshCw className={cn("w-4 h-4 text-ink/40", isRefreshing && "animate-spin")} />
                        </button>
                    </div>
                </div>

                {!authLoading && !branchId && (
                    <div className="flex items-center gap-3 p-4 rounded-2xl bg-amber-50/80 border border-amber-200/60 text-amber-800">
                        <AlertTriangle className="w-5 h-5 shrink-0" />
                        <p className="text-[12px] font-bold font-vazirmatn">{t("toast.branchNotSet")}</p>
                    </div>
                )}

                {loadError && (
                    <div className="flex items-center justify-between gap-3 p-4 rounded-2xl bg-rose-50/80 border border-rose-200/60">
                        <div className="flex items-center gap-2 text-rose-700">
                            <AlertTriangle className="w-4 h-4 shrink-0" />
                            <p className="text-[12px] font-bold font-vazirmatn">{loadError}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => fetchData()}
                            className="text-[11px] font-black text-rose-600 hover:text-rose-800 px-3 py-1.5 rounded-lg bg-white/80 border border-rose-200"
                        >
                            {t("common.refresh")}
                        </button>
                    </div>
                )}

                <div className="flex flex-col sm:flex-row gap-2">
                    <div className="flex-1 relative group">
                        <Search className={cn(
                            "absolute inset-y-0 my-auto w-3.5 h-3.5 text-ink/20 group-focus-within:text-primary transition-colors pointer-events-none",
                            isArabic ? "left-3.5" : "right-3.5"
                        )} />
                        <input
                            type="search"
                            placeholder={t("sales.searchPlaceholder")}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={handleSearchKeyDown}
                            disabled={!branchId || authLoading}
                            className={cn(
                                "w-full h-10 bg-white/70 border border-white focus:border-primary/30 focus:bg-white rounded-xl text-[12px] font-vazirmatn placeholder:text-ink/20 transition-all outline-none shadow-sm disabled:opacity-50",
                                isArabic ? "pl-9 pr-10" : "pr-9 pl-10"
                            )}
                        />
                        {search && (
                            <button
                                type="button"
                                aria-label={t("common.clear")}
                                onClick={() => setSearch("")}
                                className={cn(
                                    "absolute inset-y-0 my-auto w-5 h-5 flex items-center justify-center rounded-full bg-ink/8 hover:bg-ink/15 text-ink/40 transition-all",
                                    isArabic ? "right-3" : "left-3"
                                )}
                            >
                                <X className="w-3 h-3" />
                            </button>
                        )}
                    </div>
                    <ToolBtn
                        icon={<Scan className="w-4 h-4 text-primary" />}
                        label={t("inventory.scan")}
                        onClick={() => setScannerOpen(true)}
                        disabled={!branchId || authLoading}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <FilterSelect
                        value={typeFilter}
                        onChange={(v) => setTypeFilter(v as TypeFilter)}
                        placeholder={t("sales.filterType")}
                        options={[
                            { value: "all", label: t("sales.typeAll") },
                            { value: "owned", label: t("inventory.owned") },
                            { value: "consignment", label: t("inventory.consignment") },
                        ]}
                        className="min-w-[130px]"
                    />
                    <FilterSelect
                        value={stockFilter}
                        onChange={(v) => setStockFilter(v as StockFilter)}
                        placeholder={t("sales.filterStock")}
                        options={[
                            { value: "all", label: t("sales.stockAll") },
                            { value: "available", label: t("sales.stockAvailable") },
                            { value: "low", label: t("sales.stockLow") },
                            { value: "out", label: t("inventory.outOfStock") },
                        ]}
                        className="min-w-[130px]"
                    />
                    {hasActiveFilters && (
                        <button
                            type="button"
                            onClick={() => { setTypeFilter("all"); setStockFilter("all"); }}
                            className="h-11 px-3 rounded-xl text-[11px] font-black font-vazirmatn text-ink/40 hover:text-primary hover:bg-primary/5 border border-transparent hover:border-primary/15 transition-all"
                        >
                            {t("common.clear")}
                        </button>
                    )}
                    {!isLoading && (
                        <span className="self-center text-[10px] font-bold text-ink/30 ms-auto">
                            {t("sales.bookCount", { count: filtered.length })}
                        </span>
                    )}
                </div>

                {isLoading || authLoading ? (
                    <div className="rounded-2xl border border-white/70 bg-white/60 overflow-hidden divide-y divide-ink/5">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <div key={i} className="h-14 bg-parchment/20 animate-pulse" />
                        ))}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 gap-3 text-ink/20">
                        <div className="w-12 h-12 rounded-2xl bg-parchment/80 border border-ink/5 flex items-center justify-center">
                            <Book className="w-5 h-5" />
                        </div>
                        <p className="text-[12px] font-black font-vazirmatn">{t("sales.noBooksFound")}</p>
                        {branchId && (
                            <p className="text-[10px] text-ink/30 font-bold font-vazirmatn text-center max-w-xs">
                                {t("sales.noBranchStockHint")}
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-white/70 bg-white/70 backdrop-blur-xl shadow-sm overflow-hidden divide-y divide-ink/5">
                        {filtered.map((book) => (
                            <BookListRow
                                key={book.id}
                                book={book}
                                inCart={getCartQty(book.id)}
                                remaining={getRemainingStock(book.id)}
                                formatNumber={formatNumber}
                                currency={currencySymbol}
                                onAdd={() => addToCart(book)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {/* Cart panel — fixed viewport height so footer (submit) stays pinned */}
            <div className="w-full lg:w-[320px] xl:w-[360px] shrink-0 lg:sticky lg:top-4">
                <Card className="flex flex-col overflow-hidden border border-white/80 bg-white/65 backdrop-blur-xl shadow-xl rounded-2xl h-[min(720px,calc(100svh-5.5rem))] lg:h-[calc(100svh-5.5rem)]">
                    <CardHeader className="px-4 py-3 border-b border-ink/[0.05] flex flex-row items-center gap-2.5 bg-white/30 shrink-0">
                        <div className="w-8 h-8 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center shrink-0 relative">
                            <Receipt className="w-4 h-4 text-primary" />
                            {cartCount > 0 && (
                                <span className="absolute -top-1.5 -start-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-accent text-white text-[9px] font-black flex items-center justify-center">
                                    {formatNumber(cartCount)}
                                </span>
                            )}
                        </div>
                        <CardTitle className="text-[13px] font-black font-vazirmatn text-ink flex-1">{t("sales.cart")}</CardTitle>
                        {cartItems.length > 0 && (
                            <button
                                type="button"
                                onClick={clearCart}
                                className="text-[10px] font-black text-ink/30 hover:text-rose-500 transition-colors"
                            >
                                {t("sales.clearCart")}
                            </button>
                        )}
                    </CardHeader>

                    <div className="shrink-0 flex gap-1 p-1.5 border-b border-ink/[0.05] bg-white/20">
                        <PayTab active={payment === "cash"} onClick={() => setPayment("cash")}
                            icon={<Banknote className="w-3.5 h-3.5 shrink-0" />} label={t("sales.cash")}
                            activeClass="bg-primary text-white shadow-md shadow-primary/20" />
                        <PayTab active={payment === "check"} onClick={() => setPayment("check")}
                            icon={<CreditCard className="w-3.5 h-3.5 shrink-0" />} label={t("sales.check")}
                            activeClass="bg-accent text-white shadow-md shadow-accent/20" />
                        <PayTab active={payment === "credit"} onClick={() => setPayment("credit")}
                            icon={<ShoppingBag className="w-3.5 h-3.5 shrink-0" />} label={t("sales.credit")}
                            activeClass="bg-indigo-500 text-white shadow-md shadow-indigo-500/20" />
                    </div>

                    <CardContent className="flex-1 flex flex-col p-0 overflow-hidden min-h-0">
                        <Cart
                            items={cartItems}
                            onUpdateQty={updateCartQty}
                            onRemove={removeFromCart}
                            onCheckout={handleCheckout}
                            paymentMethod={payment}
                            currency={preferredCurrency}
                            paymentDetails={paymentDetails}
                            onPaymentDetailsChange={setPaymentDetails}
                            branchId={branchId}
                        />
                    </CardContent>
                </Card>
            </div>
        </div>

            <Card className="border border-white/80 bg-white/65 backdrop-blur-xl rounded-2xl overflow-hidden">
                <CardHeader className="px-5 py-3.5 border-b border-ink/5 flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-[13px] font-black font-vazirmatn">{t("sales.invoiceHistory")}</CardTitle>
                    {isAdmin && salesBranches.length > 0 && (
                        <FilterSelect
                            value={invoiceBranchFilter}
                            onChange={setInvoiceBranchFilter}
                            options={[
                                { value: "all", label: t("sales.allBranches") },
                                ...salesBranches.map((b) => ({ value: String(b.id), label: b.name })),
                            ]}
                            icon={<Store className="w-3.5 h-3.5" />}
                        />
                    )}
                </CardHeader>
                <CardContent className="p-0 divide-y divide-ink/5 max-h-64 overflow-y-auto">
                    {recentInvoices.length === 0 ? (
                        <p className="p-5 text-[11px] text-ink/30 text-center">{t("sales.recentInvoices")} —</p>
                    ) : recentInvoices.slice(0, 20).map((inv) => (
                        <div
                            key={inv.id}
                            className="px-5 py-3 flex items-center justify-between gap-3 hover:bg-primary/[0.03] transition-colors group"
                        >
                            <Link
                                href={`/dashboard/invoices?id=${inv.id}`}
                                className="min-w-0 flex-1"
                            >
                                <p className="text-[11px] font-black font-vazirmatn truncate group-hover:text-primary transition-colors">
                                    {inv.invoice_number}
                                </p>
                                <p className="text-[9px] text-ink/35 truncate">
                                    {inv.branch?.name}
                                    {" · "}
                                    {inv.customer_name || t("sales.cash")}
                                    {inv.customer_phone ? ` · ${inv.customer_phone}` : ""}
                                </p>
                            </Link>
                            <div className="flex items-center gap-2 shrink-0">
                                <button
                                    type="button"
                                    title={t("sales.printReceipt")}
                                    aria-label={t("sales.printReceipt")}
                                    disabled={printingId === inv.id}
                                    onClick={(e) => handlePrintInvoice(e, inv.id)}
                                    className="h-8 w-8 flex items-center justify-center rounded-lg border border-ink/8 bg-white/80 text-ink/35 hover:text-primary hover:border-primary/20 hover:bg-primary/5 transition-all disabled:opacity-40"
                                >
                                    <Printer className={cn("w-3.5 h-3.5", printingId === inv.id && "animate-pulse")} />
                                </button>
                                <Link href={`/dashboard/invoices?id=${inv.id}`} className="text-end">
                                    <p className="text-[12px] font-black text-primary">{formatNumber(inv.total)}</p>
                                    <p className="text-[8px] text-ink/30">
                                        {inv.payment_method === "check"
                                            ? t("sales.check")
                                            : inv.payment_method === "credit"
                                                ? t("sales.credit")
                                                : inv.payment_method === "card"
                                                    ? t("sales.card")
                                                    : t("sales.cash")}
                                    </p>
                                </Link>
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>

            <ScannerModal
                isOpen={scannerOpen}
                onClose={() => setScannerOpen(false)}
                onDetected={(code) => {
                    setScannerOpen(false);
                    lookupBarcode(code);
                }}
            />
        </div>
    );
}

function StatBadge({ icon, label, value, className }: {
    icon: React.ReactNode; label: string; value: string; className?: string;
}) {
    return (
        <div className="flex flex-col items-end gap-1 px-3.5 py-2 rounded-xl bg-white/60 border border-white/80 shadow-sm min-w-[100px]">
            <div className="flex items-center gap-1 text-ink/30">
                {icon}
                <span className="text-[8px] font-black uppercase tracking-widest">{label}</span>
            </div>
            <span className={cn("text-[13px] font-black font-vazirmatn tabular-nums leading-none", className)}>{value}</span>
        </div>
    );
}

function ToolBtn({ icon, label, onClick, disabled }: {
    icon: React.ReactNode; label: string; onClick?: () => void; disabled?: boolean;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            disabled={disabled}
            className="h-10 w-10 shrink-0 flex items-center justify-center rounded-xl border border-white bg-white/70 hover:bg-white hover:shadow-md transition-all active:scale-95 shadow-sm disabled:opacity-40"
        >
            {icon}
        </button>
    );
}

function BookListRow({ book, inCart, remaining, formatNumber, currency, onAdd }: {
    book: SalesBook;
    inCart: number;
    remaining: number;
    formatNumber: (n: number) => string;
    currency: string;
    onAdd: () => void;
}) {
    const { t } = useTranslation();
    const outOfStock = book.stock <= 0;
    const low = book.stock > 0 && book.stock < LOW_STOCK;
    const isConsignment = book.type === "consignment";

    return (
        <div
            role="button"
            tabIndex={outOfStock ? -1 : 0}
            onClick={() => !outOfStock && remaining > 0 && onAdd()}
            onKeyDown={(e) => {
                if ((e.key === "Enter" || e.key === " ") && !outOfStock && remaining > 0) {
                    e.preventDefault();
                    onAdd();
                }
            }}
            className={cn(
                "group flex items-center gap-3 px-3.5 py-2.5 transition-colors text-start",
                outOfStock
                    ? "opacity-45 cursor-not-allowed bg-parchment/20"
                    : "cursor-pointer hover:bg-primary/[0.04] active:bg-primary/[0.07]"
            )}
        >
            <div className={cn(
                "w-1.5 self-stretch rounded-full shrink-0",
                isConsignment ? "bg-amber-400/70" : "bg-primary/50",
                outOfStock && "bg-ink/15"
            )} />

            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <h4 className={cn(
                        "font-vazirmatn font-black text-[13px] leading-snug truncate",
                        outOfStock ? "text-ink/40" : "text-ink group-hover:text-primary"
                    )}>
                        {book.title}
                    </h4>
                    <Badge className={cn(
                        "text-[8px] font-black px-1.5 py-px rounded-md border shrink-0",
                        isConsignment
                            ? "bg-amber-50 text-amber-600 border-amber-100"
                            : "bg-sky-50 text-sky-600 border-sky-100"
                    )}>
                        {isConsignment ? t("inventory.consignment") : t("inventory.owned")}
                    </Badge>
                    {inCart > 0 && (
                        <Badge className="text-[8px] font-black px-1.5 py-px rounded-md border bg-primary/10 text-primary border-primary/20 shrink-0">
                            {t("sales.inCartCount", { count: inCart })}
                        </Badge>
                    )}
                </div>
                <p className="text-[10px] text-ink/35 font-bold mt-0.5 truncate font-vazirmatn">
                    {[book.author, book.isbn].filter(Boolean).join(" · ") || "—"}
                </p>
            </div>

            <div className="hidden sm:flex flex-col items-end shrink-0 min-w-[4.5rem]">
                <span className={cn(
                    "text-[12px] font-black font-vazirmatn tabular-nums",
                    outOfStock ? "text-ink/30" : low ? "text-rose-500" : "text-ink/70"
                )}>
                    {formatNumber(book.stock)}
                </span>
                <span className="text-[8px] font-bold text-ink/30">
                    {outOfStock ? t("inventory.outOfStock") : t("distribution.volumeUnit")}
                </span>
            </div>

            <div className="text-end shrink-0 min-w-[5.5rem]">
                <span className="text-[13px] font-black text-primary font-vazirmatn tabular-nums">
                    {formatNumber(book.price)}
                </span>
                <span className="text-[8px] text-primary/35 ms-0.5 font-bold">{currency}</span>
                <p className="sm:hidden text-[9px] font-bold text-ink/30 mt-0.5 font-vazirmatn tabular-nums">
                    {outOfStock
                        ? t("inventory.outOfStock")
                        : t("sales.stockCount", { count: book.stock })}
                </p>
            </div>

            <button
                type="button"
                title={t("sales.addToCart")}
                aria-label={t("sales.addToCart")}
                disabled={outOfStock || remaining <= 0}
                onClick={(e) => { e.stopPropagation(); onAdd(); }}
                className={cn(
                    "w-8 h-8 rounded-xl flex items-center justify-center transition-all duration-150 active:scale-90 shrink-0",
                    outOfStock || remaining <= 0
                        ? "bg-ink/5 text-ink/20 cursor-not-allowed"
                        : "bg-primary/10 hover:bg-primary text-primary hover:text-white"
                )}
            >
                <Plus className="w-3.5 h-3.5" />
            </button>
        </div>
    );
}

function PayTab({ active, onClick, icon, label, activeClass }: {
    active: boolean; onClick: () => void; icon: React.ReactNode; label: string; activeClass: string;
}) {
    return (
        <button type="button" onClick={onClick}
            className={cn(
                "flex-1 flex items-center justify-center gap-1.5 h-9 rounded-lg text-[10px] font-black font-vazirmatn transition-all duration-200 active:scale-[0.98]",
                active ? activeClass : "text-ink/30 hover:bg-white/70 hover:text-ink/60"
            )}>
            {icon}
            <span>{label}</span>
        </button>
    );
}
