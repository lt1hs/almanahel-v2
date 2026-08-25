"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  PackageCheck,
  Plus,
  Search,
  Building2,
  CheckCircle2,
  Clock,
  AlertCircle,
  X,
  FileText,
  CalendarDays,
} from "lucide-react";
import { Link, useRouter } from "@/i18n/routing";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { buildBooksListUrl } from "@/lib/bookCatalogRequests";
import { useAuth } from "@/contexts/AuthContext";
import { buildConsignmentSettleHref } from "@/lib/consignmentSettlementLink";
import { resolveBranchId } from "@/lib/bookFormUtils";
import {
  persistOperationalBranchId,
  readPersistedOperationalBranchId,
  resolveDefaultOperationalBranchId,
} from "@/lib/operationalBranch";

type ConsignmentStatus = "not_due" | "unsettled" | "partially_settled" | "settled";

interface ConsignmentMoneySummary {
  inventory_value: string;
  payable_generated: string;
  payable_settled: string;
  payable_outstanding: string;
}

interface ConsignmentSummary {
  receipts_count: number;
  not_due_count: number;
  unsettled_count: number;
  partially_settled_count: number;
  settled_count: number;
  currencies: Record<"toman" | "dinar", ConsignmentMoneySummary>;
}

interface ConsignmentReceipt {
  id: number;
  receipt_number: string;
  supplier_account_id?: number;
  supplier: { id: number; name: string; phone?: string };
  branch: { id: number; name: string };
  received_at: string;
  status: "unsettled" | "partially_settled" | "settled";
  payable_status: ConsignmentStatus;
  currency: "toman" | "dinar";
  total_value: string;
  settled_amount: string;
  inventory_value: string;
  payable_generated: string;
  payable_settled: string;
  payable_outstanding: string;
  quantity_received: number;
  quantity_sold: number;
  quantity_returned: number;
  quantity_in_stock: number;
  remaining_unsold_quantity?: number;
  remaining_payable?: string;
  items_count: number;
}

interface ReceiptItem {
  id: number;
  book_id: number;
  quantity_received: number;
  quantity_sold: number;
  quantity_returned: number;
  cost_price: number;
  selling_price: number;
  book?: { id: number; title: string; author?: string };
}

interface ReceiptDetail extends ConsignmentReceipt {
  notes?: string | null;
  items: ReceiptItem[];
}

const STATUS_STYLES: Record<
  ConsignmentStatus,
  { icon: React.ElementType; color: string; bg: string; border: string }
> = {
  not_due: { icon: PackageCheck, color: "text-sky-500", bg: "bg-sky-50", border: "border-sky-100" },
  unsettled: { icon: AlertCircle, color: "text-rose-500", bg: "bg-rose-50", border: "border-rose-100" },
  partially_settled: { icon: Clock, color: "text-amber-500", bg: "bg-amber-50", border: "border-amber-100" },
  settled: { icon: CheckCircle2, color: "text-emerald-500", bg: "bg-emerald-50", border: "border-emerald-100" },
};

function useDebouncedValue<T>(value: T, delay = 300) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

function displayDate(value: string) {
  return value.includes("T") ? value.split("T")[0] : value;
}

export default function ConsignmentPage() {
  const { t, tn, formatNumber, isArabic, isDinar, preferredCurrency } = useTranslation();
  const notify = useNotify();
  const { user } = useAuth();
  const router = useRouter();
  const isAdmin = user?.role === "admin" || user?.role === "super_admin";
  const userBranchId = user?.branch_id ? Number(user.branch_id) : user?.branch?.id ? Number(user.branch.id) : null;

  const statusLabel = (status: ConsignmentStatus, opts?: { remainingUnsold?: number }) => {
    if (status === "not_due") return t("consignment.status.notDue");
    if (status === "unsettled") return t("consignment.status.unsettled");
    if (status === "partially_settled") return t("consignment.status.partial");
    if (status === "settled" && (opts?.remainingUnsold ?? 0) > 0) {
      return "بدهی تسویه شد";
    }
    return t("consignment.status.settled");
  };

  const currencySymbol = isDinar
    ? t("common.currency.dinarSymbol")
    : t("common.currency.tomanSymbol");

  const [receipts, setReceipts] = useState<ConsignmentReceipt[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [totalCount, setTotalCount] = useState(0);
  const [summary, setSummary] = useState<ConsignmentSummary | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<ConsignmentStatus | "all">("all");
  const [supplierFilter, setSupplierFilter] = useState<string>("");
  const [supplierFilterField, setSupplierFilterField] = useState<"supplier_id" | "supplier_account_id">("supplier_id");
  const [isLoading, setIsLoading] = useState(true);
  usePageReady(!isLoading);
  const [isLoadingMore, setIsLoadingMore] = useState(false);

  const [showNewForm, setShowNewForm] = useState(false);
  const [suppliers, setSuppliers] = useState<{ id: number; name: string; status?: string }[]>([]);
  const [branches, setBranches] = useState<{ id: number; name: string }[]>([]);
  const [formReady, setFormReady] = useState(false);
  const [isLoadingForm, setIsLoadingForm] = useState(false);

  const [itemSearch, setItemSearch] = useState("");
  const debouncedItemSearch = useDebouncedValue(itemSearch, 350);
  const [bookResults, setBookResults] = useState<{ id: number; title: string; author?: string }[]>([]);
  const [isSearchingBooks, setIsSearchingBooks] = useState(false);

  const [detail, setDetail] = useState<ReceiptDetail | null>(null);
  const [isLoadingDetail, setIsLoadingDetail] = useState(false);

  const [newReceipt, setNewReceipt] = useState({
    supplier_account_id: "",
    branch_id: user?.branch?.id ? String(user.branch.id) : "",
    received_at: new Date().toISOString().split("T")[0],
    currency: preferredCurrency as "toman" | "dinar",
    notes: "",
    items: [] as {
      book_id: number;
      title: string;
      quantity: number;
      cost_price: number;
      selling_price: number;
    }[],
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isClosingId, setIsClosingId] = useState<number | null>(null);
  const [adminBranchId, setAdminBranchId] = useState("");
  const [adminBranches, setAdminBranches] = useState<{ id: number; name: string; type?: string }[]>([]);
  const [branchReady, setBranchReady] = useState(!isAdmin);
  const formLoadedRef = useRef(false);
  const fetchGenRef = useRef(0);
  // Single source for assigned branch — do not redeclare userBranchId below.
  const operationalBranchId = isAdmin
    ? (adminBranchId ? Number(adminBranchId) : null)
    : userBranchId;

  const closeReceipt = async (id: number) => {
    setIsClosingId(id);
    try {
      await apiRequest(`/consignments/${id}/close`, { method: "POST" });
      notify.success("consignment.receiptClosed");
      await fetchReceipts(1, false);
    } catch (error: unknown) {
      const message = error instanceof Error ? error.message : "";
      notify.rawError(message || t("consignment.closeReceiptError"));
    } finally {
      setIsClosingId(null);
    }
  };

  const debouncedSearch = useDebouncedValue(search, 300);

  // URL deep-link from suppliers page
  useEffect(() => {
    if (typeof window === "undefined") return;
    const params = new URLSearchParams(window.location.search);
    const accountId = params.get("supplier_account_id");
    if (accountId) {
      setSupplierFilter(accountId);
      setSupplierFilterField("supplier_account_id");
      return;
    }
    const sid = params.get("supplier_id");
    if (sid) {
      setSupplierFilter(sid);
      setSupplierFilterField("supplier_id");
    }
  }, []);

  const fetchReceipts = useCallback(
    async (pageNum = 1, append = false) => {
      if (!operationalBranchId) {
        if (!append) {
          setReceipts([]);
          setSummary(null);
          setIsLoading(false);
        }
        return;
      }

      const gen = ++fetchGenRef.current;
      if (append) setIsLoadingMore(true);
      else setIsLoading(true);

      try {
        const params = new URLSearchParams({ page: String(pageNum) });
        params.set("branch_id", String(operationalBranchId));
        if (statusFilter !== "all") params.set("payable_status", statusFilter);
        if (debouncedSearch.trim()) params.set("q", debouncedSearch.trim());
        if (supplierFilter) params.set(supplierFilterField, supplierFilter);

        const data = await apiRequest(`/consignments?${params.toString()}`);
        if (gen !== fetchGenRef.current) return;
        const rows: ConsignmentReceipt[] = data.data || [];
        setReceipts((prev) => (append ? [...prev, ...rows] : rows));
        setPage(data.current_page || pageNum);
        setHasMore(Boolean(data.next_page_url));
        setTotalCount(data.total ?? rows.length);
        setSummary(data.summary ?? null);
      } catch (error) {
        if (gen !== fetchGenRef.current) return;
        console.error("Failed to fetch consignments:", error);
        if (!append) {
          setReceipts([]);
          setSummary(null);
        }
      } finally {
        if (gen === fetchGenRef.current) {
          setIsLoading(false);
          setIsLoadingMore(false);
        }
      }
    },
    [statusFilter, debouncedSearch, supplierFilter, supplierFilterField, operationalBranchId]
  );

  useEffect(() => {
    if (!isAdmin) {
      setBranchReady(true);
      return;
    }
    let cancelled = false;
    apiRequest("/branches?lite=1")
      .then((data) => {
        if (cancelled) return;
        const rows = (Array.isArray(data) ? data : []).filter(
          (b: { type?: string }) => b.type === "store" || b.type === "warehouse"
        );
        setAdminBranches(rows);
        const availableIds = rows.map((b: { id: number }) => Number(b.id));
        const qomId = resolveBranchId(rows, "qom");
        const persisted = readPersistedOperationalBranchId();
        const persistedOk =
          persisted != null && availableIds.includes(persisted) ? persisted : null;
        const defaultId =
          persistedOk ??
          qomId ??
          resolveDefaultOperationalBranchId(
            { role: user?.role, branch_id: userBranchId },
            {
              availableBranchIds: availableIds,
              persistedBranchId: null,
              centralBranchId: qomId,
            }
          ) ??
          availableIds[0] ??
          null;
        if (defaultId) {
          setAdminBranchId(String(defaultId));
          persistOperationalBranchId(defaultId);
        }
        setBranchReady(true);
      })
      .catch(() => {
        if (!cancelled) setBranchReady(true);
      });
    return () => {
      cancelled = true;
    };
  }, [isAdmin, user?.role, userBranchId]);

  const handleAdminBranchChange = (value: string) => {
    if (!value) return;
    setAdminBranchId(value);
    setReceipts([]);
    setSummary(null);
    setDetail(null);
    persistOperationalBranchId(Number(value));
  };

  const branchSelectOptions = useMemo(
    () => adminBranches.map((b) => ({ value: String(b.id), label: b.name })),
    [adminBranches]
  );

  useEffect(() => {
    if (!branchReady) return;
    fetchReceipts(1, false);
  }, [fetchReceipts, branchReady]);

  const loadFormData = useCallback(async () => {
    if (formLoadedRef.current) {
      setFormReady(true);
      return;
    }
    setIsLoadingForm(true);
    try {
      const bData = await apiRequest("/branches?lite=1");
      const branchListRaw = Array.isArray(bData) ? bData : [];
      const branchList = isAdmin
        ? branchListRaw
        : branchListRaw.filter((b: { id: number }) => {
            const ids = new Set<number>();
            if (userBranchId) ids.add(userBranchId);
            return ids.has(Number(b.id));
          });
      setBranches(branchList);
      formLoadedRef.current = true;
      setFormReady(true);

      const qomId = resolveBranchId(branchList, "qom");
      const lockedBranch =
        !isAdmin && userBranchId
          ? String(userBranchId)
          : operationalBranchId
            ? String(operationalBranchId)
            : qomId
              ? String(qomId)
              : branchList[0]
                ? String(branchList[0].id)
                : "";

      setNewReceipt((prev) => ({
        ...prev,
        supplier_account_id: prev.supplier_account_id || (supplierFilter ? String(supplierFilter) : ""),
        branch_id: !isAdmin && userBranchId ? String(userBranchId) : (prev.branch_id || lockedBranch),
        currency: preferredCurrency,
      }));
    } catch (error) {
      console.error("Failed to fetch form data:", error);
      notify.error("toast.consignmentReceiptError");
    } finally {
      setIsLoadingForm(false);
    }
  }, [notify, preferredCurrency, supplierFilter, userBranchId, isAdmin, operationalBranchId]);

  const loadSupplierAccounts = useCallback(async (branchId: string) => {
    if (!branchId) {
      setSuppliers([]);
      return;
    }
    try {
      const data = await apiRequest(`/supplier-accounts?branch_id=${branchId}`);
      const rows = Array.isArray(data) ? data : [];
      setSuppliers(
        rows.map((row: { id: number; display_name?: string; name?: string }) => ({
          id: row.id,
          name: row.display_name || row.name || `#${row.id}`,
        }))
      );
    } catch {
      setSuppliers([]);
    }
  }, []);

  useEffect(() => {
    if (!showNewForm || !newReceipt.branch_id) return;
    loadSupplierAccounts(newReceipt.branch_id);
  }, [showNewForm, newReceipt.branch_id, loadSupplierAccounts]);

  const openNewForm = async () => {
    setShowNewForm(true);
    await loadFormData();
  };

  useEffect(() => {
    if (!showNewForm || !newReceipt.branch_id) return;
    if (debouncedItemSearch.trim().length < 2) {
      setBookResults([]);
      return;
    }

    let cancelled = false;
    setIsSearchingBooks(true);
    const url = buildBooksListUrl(
      { role: user?.role, branch_id: user?.branch_id ?? user?.branch?.id },
      { branchId: Number(newReceipt.branch_id), search: debouncedItemSearch.trim(), lite: true }
    );
    if (!url) {
      setBookResults([]);
      setIsSearchingBooks(false);
      return;
    }
    apiRequest(url)
      .then((data) => {
        if (!cancelled) setBookResults(Array.isArray(data) ? data : []);
      })
      .catch(() => {
        if (!cancelled) setBookResults([]);
      })
      .finally(() => {
        if (!cancelled) setIsSearchingBooks(false);
      });

    return () => {
      cancelled = true;
    };
  }, [debouncedItemSearch, showNewForm, newReceipt.branch_id, user?.branch?.id, user?.branch_id, user?.role]);

  const openDetails = async (id: number) => {
    setIsLoadingDetail(true);
    setDetail(null);
    try {
      const data = await apiRequest(`/consignments/${id}`);
      setDetail(data);
    } catch (error) {
      console.error("Failed to load receipt details:", error);
      notify.error("toast.consignmentReceiptError");
    } finally {
      setIsLoadingDetail(false);
    }
  };

  const handleAddBook = (book: { id: number; title: string }) => {
    setNewReceipt((prev) => {
      if (prev.items.some((i) => i.book_id === book.id)) return prev;
      return {
        ...prev,
        items: [
          ...prev.items,
          {
            book_id: book.id,
            title: book.title,
            quantity: 1,
            cost_price: 0,
            selling_price: 0,
          },
        ],
      };
    });
    setItemSearch("");
    setBookResults([]);
  };

  const handleRemoveBook = (bookId: number) => {
    setNewReceipt((prev) => ({
      ...prev,
      items: prev.items.filter((i) => i.book_id !== bookId),
    }));
  };

  const updateItem = (bookId: number, field: string, value: number) => {
    setNewReceipt((prev) => ({
      ...prev,
      items: prev.items.map((i) => (i.book_id === bookId ? { ...i, [field]: value } : i)),
    }));
  };

  const resetForm = () => {
    setNewReceipt({
      supplier_account_id: supplierFilter || "",
      branch_id: operationalBranchId
        ? String(operationalBranchId)
        : user?.branch?.id
          ? String(user.branch.id)
          : branches[0]
            ? String(branches[0].id)
            : "",
      received_at: new Date().toISOString().split("T")[0],
      currency: preferredCurrency,
      notes: "",
      items: [],
    });
    setItemSearch("");
    setBookResults([]);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newReceipt.supplier_account_id || !newReceipt.branch_id || newReceipt.items.length === 0) {
      notify.error("toast.consignmentFieldsRequired");
      return;
    }

    const invalid = newReceipt.items.some(
      (i) => !i.quantity || i.quantity < 1 || i.cost_price < 0 || i.selling_price < 0
    );
    if (invalid) {
      notify.error("toast.consignmentFieldsRequired");
      return;
    }

    setIsSubmitting(true);
    try {
      await apiRequest("/consignments", {
        method: "POST",
        body: JSON.stringify({
          supplier_account_id: Number(newReceipt.supplier_account_id),
          branch_id: Number(newReceipt.branch_id),
          received_at: newReceipt.received_at,
          currency: newReceipt.currency,
          notes: newReceipt.notes || null,
          items: newReceipt.items.map((i) => ({
            book_id: i.book_id,
            quantity: Number(i.quantity),
            cost_price: Number(i.cost_price),
            selling_price: Number(i.selling_price),
          })),
        }),
      });
      setShowNewForm(false);
      resetForm();
      notify.success("consignment.receiptCreated");
      await fetchReceipts(1, false);
    } catch (error: unknown) {
      console.error("Failed to submit receipt:", error);
      const message = error instanceof Error ? error.message : "";
      notify.rawError(message || t("toast.consignmentReceiptError"));
    } finally {
      setIsSubmitting(false);
    }
  };

  const kpi = useMemo(() => {
    const currencySummary = summary?.currencies?.[preferredCurrency];
    const needsSettlement = summary
      ? summary.unsettled_count + summary.partially_settled_count
      : receipts.filter((r) => r.payable_status === "unsettled" || r.payable_status === "partially_settled").length;

    return [
      { label: t("consignment.kpi.totalReceipts"), value: summary?.receipts_count ?? totalCount ?? receipts.length, color: "text-primary" },
      { label: t("consignment.kpi.notDue"), value: summary?.not_due_count ?? 0, color: "text-sky-500" },
      { label: t("consignment.kpi.needsSettlement"), value: needsSettlement, color: "text-rose-500" },
      {
        label: t("consignment.kpi.totalBalance"),
        value: Number(currencySummary?.payable_outstanding ?? 0),
        color: "text-ink",
        unit: ` ${currencySymbol}`,
      },
    ];
  }, [receipts, summary, totalCount, preferredCurrency, currencySymbol, t]);

  return (
    <div className="space-y-5 pb-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-black font-vazirmatn text-ink">{t("consignment.title")}</h1>
          <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
            {t("consignment.subtitle")}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
          {isAdmin && branchSelectOptions.length > 0 && (
            <FilterSelect
              className="w-full min-w-[200px] sm:w-[240px]"
              value={adminBranchId || (operationalBranchId ? String(operationalBranchId) : "")}
              onChange={handleAdminBranchChange}
              options={branchSelectOptions}
              icon={<Building2 className="h-3.5 w-3.5" />}
              defaultValue="__none__"
              placeholder={t("consignment.form.branch")}
            />
          )}
          <div className="flex gap-2">
            <Link href="/dashboard/consignment/settle">
              <Button
                variant="ghost"
                size="sm"
                className="h-11 rounded-xl border border-ink/5 px-4 text-[11px] font-bold"
              >
                <FileText className="ms-1.5 h-3.5 w-3.5 opacity-50" />
                {t("finance.settlementTab")}
              </Button>
            </Link>
            <Button
              variant="primary"
              size="sm"
              className="h-11 rounded-xl px-4 text-[11px] shadow-lg shadow-primary/10"
              onClick={openNewForm}
            >
              <Plus className="ms-1.5 h-3.5 w-3.5" />
              {t("consignment.addReceipt")}
            </Button>
          </div>
        </div>
      </div>

      {branchReady && !operationalBranchId && (
        <p className="rounded-xl border border-amber-100 bg-amber-50/80 px-4 py-3 text-center text-[12px] font-bold text-amber-700">
          {t("expenses.form.selectBranch")}
        </p>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {kpi.map((item) => (
          <Card key={item.label} className="rounded-[14px] border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
            <CardContent className="p-4">
              <p className="mb-1 text-[9px] font-bold uppercase tracking-widest text-ink/35">{item.label}</p>
              <p className={cn("text-xl font-black font-vazirmatn", item.color)}>
                {isLoading ? "…" : formatNumber(item.value)}
                {"unit" in item ? item.unit : ""}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <div className="relative min-w-[200px] flex-1">
          <Search className="pointer-events-none absolute inset-y-0 end-3 my-auto h-3.5 w-3.5 text-ink/20" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("consignment.searchPlaceholder")}
            className="h-10 w-full rounded-xl border border-white bg-white/70 pe-9 ps-3 text-[12px] font-vazirmatn shadow-sm outline-none placeholder:text-ink/20 focus:border-primary/30"
          />
        </div>
        {(["all", "not_due", "unsettled", "partially_settled", "settled"] as const).map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => setStatusFilter(s)}
            className={cn(
              "h-10 rounded-xl border px-4 text-[10px] font-black uppercase tracking-wide transition-all",
              statusFilter === s
                ? "border-primary bg-primary text-white shadow-lg shadow-primary/20"
                : "border-white bg-white/70 text-ink/40 hover:border-primary/20 hover:text-ink/70"
            )}
          >
            {s === "all" ? t("common.all") : statusLabel(s)}
          </button>
        ))}
        {supplierFilter && (
          <button
            type="button"
            onClick={() => setSupplierFilter("")}
            className="flex h-10 items-center gap-1 rounded-xl border border-primary/20 bg-primary/5 px-3 text-[10px] font-bold text-primary"
          >
            {t("consignment.form.supplier")} #{supplierFilter}
            <X className="h-3 w-3" />
          </button>
        )}
      </div>

      <div className="space-y-3">
        {isLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-32 animate-pulse rounded-2xl bg-parchment/30" />
          ))
        ) : (
          receipts.map((receipt) => {
            const payableStatus = receipt.payable_status || "not_due";
            const cfg = STATUS_STYLES[payableStatus] || STATUS_STYLES.not_due;
            const balance = Number(receipt.remaining_payable ?? receipt.payable_outstanding ?? 0);
            const generated = Number(receipt.payable_generated || 0);
            const settled = Number(receipt.payable_settled || 0);
            const pct =
              generated > 0
                ? Math.min(100, Math.max(0, (settled / generated) * 100))
                : 0;
            const remainingUnsold = Number(
              receipt.remaining_unsold_quantity ?? receipt.quantity_in_stock ?? 0
            );
            const settleHref = buildConsignmentSettleHref(receipt.id, receipt.supplier_account_id);
            const showDataIntegrityWarning = balance > 0 && (receipt.items_count || 0) > 0 && !settleHref;

            return (
              <Card
                key={receipt.id}
                className="overflow-hidden rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl transition-all hover:shadow-lg"
              >
                <CardContent className="p-4">
                  <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div
                      className={cn(
                        "flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border",
                        cfg.bg,
                        cfg.border
                      )}
                    >
                      <cfg.icon className={cn("h-5 w-5", cfg.color)} />
                    </div>

                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[13px] font-black font-vazirmatn text-ink">
                          {receipt.supplier?.name || "—"}
                        </span>
                        <Badge className={cn("border text-[8px] font-black", cfg.bg, cfg.color, cfg.border)}>
                          {statusLabel(payableStatus, { remainingUnsold })}
                        </Badge>
                        <span className="font-mono text-[9px] text-ink/25">{receipt.receipt_number}</span>
                      </div>
                      <div className="mt-1 flex flex-wrap items-center gap-4">
                        <span className="flex items-center gap-1 text-[9px] text-ink/35">
                          <Building2 className="h-3 w-3" />
                          {receipt.branch?.name || "—"}
                        </span>
                        <span className="flex items-center gap-1 text-[9px] text-ink/35">
                          <CalendarDays className="h-3 w-3" />
                          {displayDate(receipt.received_at)}
                        </span>
                        <span className="flex items-center gap-1 text-[9px] text-ink/35">
                          <FileText className="h-3 w-3" />
                          {tn("plurals.book", receipt.items_count || 0)}
                        </span>
                        {remainingUnsold > 0 && (
                          <span className="text-[9px] font-bold text-ink/45">
                            موجودی امانی باقی: {formatNumber(remainingUnsold)}
                          </span>
                        )}
                      </div>
                      <div className="mt-2">
                        <div className="h-1.5 overflow-hidden rounded-full bg-ink/5">
                          <div
                            className={cn(
                              "h-full rounded-full transition-all",
                              payableStatus === "settled" ? "bg-emerald-400" : "bg-primary"
                            )}
                            style={{ width: `${pct}%` }}
                          />
                        </div>
                        <p className="mt-0.5 text-[8px] text-ink/25">
                          {payableStatus === "not_due"
                            ? t("consignment.noPayableHint")
                            : generated > 0
                              ? `${pct.toFixed(0)}${t("consignment.settledPercent")} · بدهی ایجادشده ${formatNumber(generated)}`
                              : t("consignment.noPayableHint")}
                        </p>
                      </div>
                    </div>

                    <div className="flex shrink-0 flex-col items-end gap-1">
                      <div className="text-end">
                        <p className="text-[8px] uppercase tracking-widest text-ink/25">{t("consignment.inventoryValue")}</p>
                        <p className="text-[14px] font-black font-vazirmatn text-ink">
                          {formatNumber(Number(receipt.inventory_value))}
                          <span className="ms-0.5 text-[9px] text-ink/25">
                            {receipt.currency === "toman"
                              ? t("common.currency.tomanSymbol")
                              : t("common.currency.dinarSymbol")}
                          </span>
                        </p>
                        <p className="text-[8px] text-ink/30 mt-0.5">ارزش موجودی — بدهی نیست</p>
                      </div>
                      <div className="text-end">
                        <p className="text-[8px] uppercase tracking-widest text-rose-400">
                          {t("consignment.payableOutstanding")}
                        </p>
                        <p className={cn("text-[12px] font-black font-vazirmatn", balance > 0 ? "text-rose-500" : "text-emerald-600")}>
                          {formatNumber(balance)}
                        </p>
                      </div>
                    </div>

                    <div className="flex shrink-0 gap-1.5">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 rounded-lg border border-ink/5 px-3 text-[10px] font-bold text-ink/40 hover:border-ink/10 hover:text-ink"
                        onClick={() => openDetails(receipt.id)}
                      >
                        {t("common.details")}
                      </Button>
                      {payableStatus !== "settled" && (receipt.items_count || 0) === 0 && (
                        <Button
                          size="sm"
                          variant="outline"
                          isLoading={isClosingId === receipt.id}
                          className="h-8 rounded-lg border-amber-200 px-3 text-[10px] font-bold text-amber-700 hover:bg-amber-50"
                          title={t("consignment.closeReceiptHint")}
                          onClick={() => closeReceipt(receipt.id)}
                        >
                          {t("consignment.closeReceipt")}
                        </Button>
                      )}
                      {showDataIntegrityWarning && (
                        <p className="text-[9px] font-bold text-amber-600 max-w-[140px] text-end leading-snug">
                          {t("consignment.supplierAccountDataIntegrity")}
                        </p>
                      )}
                      {balance > 0 && (receipt.items_count || 0) > 0 && settleHref && (
                        <Button
                          size="sm"
                          className="h-8 rounded-lg bg-emerald-500 px-3 text-[10px] font-bold text-white shadow-sm hover:bg-emerald-600"
                          onClick={() => router.push(settleHref)}
                        >
                          {t("finance.makeSettlement")}
                        </Button>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            );
          })
        )}

        {!isLoading && receipts.length === 0 && (
          <div className="flex flex-col items-center gap-3 py-16 text-ink/20">
            <PackageCheck className="h-10 w-10" />
            <p className="text-[12px] font-black font-vazirmatn">{t("consignment.noReceipts")}</p>
            <Button size="sm" className="mt-2 h-9 rounded-xl text-[11px]" onClick={openNewForm}>
              <Plus className="ms-1.5 h-3.5 w-3.5" />
              {t("consignment.addReceipt")}
            </Button>
          </div>
        )}

        {hasMore && (
          <div className="flex justify-center pt-2">
            <Button
              variant="outline"
              size="sm"
              isLoading={isLoadingMore}
              className="h-9 rounded-xl px-5 text-[11px]"
              onClick={() => fetchReceipts(page + 1, true)}
            >
              {t("consignment.loadMore")}
            </Button>
          </div>
        )}
      </div>

      {/* Details drawer */}
      {(detail || isLoadingDetail) && (
        <div
          className="fixed inset-0 z-[60] flex justify-end bg-ink/30 backdrop-blur-sm"
          onClick={(e) => e.target === e.currentTarget && setDetail(null)}
        >
          <div className="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-ink/5 px-5 py-4">
              <div>
                <h2 className="text-[15px] font-black font-vazirmatn text-ink">
                  {t("consignment.detailsTitle")}
                </h2>
                {detail && (
                  <p className="mt-0.5 font-mono text-[10px] text-ink/35">{detail.receipt_number}</p>
                )}
              </div>
              <button
                type="button"
                onClick={() => setDetail(null)}
                className="rounded-xl p-2 hover:bg-ink/5"
              >
                <X className="h-4 w-4 text-ink/40" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-5">
              {isLoadingDetail || !detail ? (
                <div className="space-y-3">
                  {Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="h-12 animate-pulse rounded-xl bg-parchment/40" />
                  ))}
                </div>
              ) : (
                <div className="space-y-5">
                  <div className="grid grid-cols-2 gap-3 rounded-2xl border border-ink/5 bg-parchment/20 p-4 text-[11px]">
                    <div>
                      <p className="text-ink/35">{t("consignment.form.supplier")}</p>
                      <p className="font-black text-ink">{detail.supplier?.name}</p>
                    </div>
                    <div>
                      <p className="text-ink/35">{t("consignment.form.branch")}</p>
                      <p className="font-black text-ink">{detail.branch?.name}</p>
                    </div>
                    <div>
                      <p className="text-ink/35">{t("consignment.form.receivedDate")}</p>
                      <p className="font-black text-ink">{displayDate(detail.received_at)}</p>
                    </div>
                    <div>
                      <p className="text-ink/35">{t("common.status")}</p>
                      <p className="font-black text-ink">
                        {statusLabel(detail.payable_status, {
                          remainingUnsold: Number(detail.remaining_unsold_quantity ?? detail.quantity_in_stock ?? 0),
                        })}
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-3 rounded-2xl border border-ink/5 bg-white p-4 text-[11px]">
                    {[
                      [t("consignment.inventoryValue"), detail.inventory_value],
                      [t("consignment.payableGenerated"), detail.payable_generated],
                      [t("consignment.payableSettled"), detail.payable_settled],
                      [t("consignment.payableOutstanding"), detail.payable_outstanding],
                    ].map(([label, value]) => (
                      <div key={label}>
                        <p className="text-ink/35">{label}</p>
                        <p className="font-black text-ink">
                          {formatNumber(Number(value))} {detail.currency === "toman" ? t("common.currency.tomanSymbol") : t("common.currency.dinarSymbol")}
                        </p>
                      </div>
                    ))}
                  </div>

                  <div className="grid grid-cols-4 gap-2 rounded-2xl border border-ink/5 bg-parchment/20 p-3 text-center text-[10px]">
                    {[
                      [t("consignment.received"), detail.quantity_received],
                      [t("consignment.sold"), detail.quantity_sold],
                      [t("consignment.returned"), detail.quantity_returned],
                      [t("consignment.inStock"), detail.quantity_in_stock],
                    ].map(([label, value]) => (
                      <div key={label}>
                        <p className="text-ink/35">{label}</p>
                        <p className="mt-1 text-sm font-black text-ink">{formatNumber(Number(value))}</p>
                      </div>
                    ))}
                  </div>

                  <div className="overflow-hidden rounded-xl border border-ink/5">
                    <div className="grid grid-cols-12 gap-2 border-b border-ink/5 bg-parchment/40 px-3 py-2 text-[9px] font-black uppercase tracking-widest text-ink/40">
                      <span className="col-span-5">{t("consignment.table.bookTitle")}</span>
                      <span className="col-span-2 text-center">{t("consignment.received")}</span>
                      <span className="col-span-2 text-center">{t("consignment.sold")}</span>
                      <span className="col-span-3 text-center">{t("consignment.table.purchasePrice")}</span>
                    </div>
                    <div className="divide-y divide-ink/5">
                      {(detail.items || []).map((item) => (
                        <div key={item.id} className="grid grid-cols-12 gap-2 px-3 py-3 text-[11px]">
                          <span className="col-span-5 truncate font-bold text-ink">
                            {item.book?.title || `#${item.book_id}`}
                          </span>
                          <span className="col-span-2 text-center font-mono">
                            {formatNumber(item.quantity_received)}
                          </span>
                          <span className="col-span-2 text-center font-mono">
                            {formatNumber(item.quantity_sold)}
                          </span>
                          <span className="col-span-3 text-center font-mono">
                            {formatNumber(Number(item.cost_price))}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>

                  {detail.notes && (
                    <p className="rounded-xl bg-parchment/30 p-3 text-[11px] text-ink/55">{detail.notes}</p>
                  )}
                </div>
              )}
            </div>

            <div className="border-t border-ink/5 p-4">
              <Button variant="ghost" className="h-10 w-full rounded-xl" onClick={() => setDetail(null)}>
                {t("consignment.closeDetails")}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* New receipt modal */}
      {showNewForm && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-ink/30 p-4 backdrop-blur-sm"
          onClick={(e) => e.target === e.currentTarget && !isSubmitting && setShowNewForm(false)}
        >
          <div className="w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <form onSubmit={handleSubmit}>
              <div className="flex items-center justify-between border-b border-ink/5 bg-gradient-to-r from-primary/5 to-transparent px-6 py-5">
                <div>
                  <h2 className="text-[15px] font-black font-vazirmatn text-ink">
                    {t("consignment.addReceipt")}
                  </h2>
                  <p className="mt-0.5 text-[10px] text-ink/35">{t("consignment.form.entrySubtitle")}</p>
                </div>
                <button
                  type="button"
                  disabled={isSubmitting}
                  onClick={() => setShowNewForm(false)}
                  className="rounded-xl p-2 transition-colors hover:bg-ink/5"
                >
                  <X className="h-4 w-4 text-ink/40" />
                </button>
              </div>

              <div className="max-h-[75vh] space-y-4 overflow-y-auto p-6">
                {isLoadingForm && !formReady ? (
                  <div className="space-y-3 py-8">
                    {Array.from({ length: 3 }).map((_, i) => (
                      <div key={i} className="h-10 animate-pulse rounded-xl bg-parchment/40" />
                    ))}
                  </div>
                ) : (
                  <>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                      <div className="space-y-1.5">
                        <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                          {t("consignment.form.supplier")}
                        </label>
                        <select
                          required
                          value={newReceipt.supplier_account_id}
                          onChange={(e) =>
                            setNewReceipt((prev) => ({ ...prev, supplier_account_id: e.target.value }))
                          }
                          className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                        >
                          <option value="">{t("finance.settlement.selectSupplier")}</option>
                          {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                              {s.name}
                            </option>
                          ))}
                        </select>
                      </div>
                      <div className="space-y-1.5">
                        <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                          {t("consignment.form.branch")}
                        </label>
                        <select
                          required
                          value={newReceipt.branch_id}
                          disabled={!isAdmin}
                          onChange={(e) =>
                            setNewReceipt((prev) => ({
                              ...prev,
                              branch_id: e.target.value,
                              supplier_account_id: "",
                            }))
                          }
                          className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary disabled:opacity-60"
                        >
                          {branches.map((b) => (
                            <option key={b.id} value={b.id}>
                              {b.name}
                            </option>
                          ))}
                        </select>
                      </div>
                      <div className="space-y-1.5">
                        <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                          {t("consignment.form.receivedDate")}
                        </label>
                        <input
                          required
                          type="date"
                          value={newReceipt.received_at}
                          onChange={(e) =>
                            setNewReceipt((prev) => ({ ...prev, received_at: e.target.value }))
                          }
                          className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] outline-none focus:ring-1 focus:ring-primary"
                        />
                      </div>
                      <div className="space-y-1.5">
                        <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                          {t("consignment.form.currency")}
                        </label>
                        <select
                          value={newReceipt.currency}
                          onChange={(e) =>
                            setNewReceipt((prev) => ({
                              ...prev,
                              currency: e.target.value as "toman" | "dinar",
                            }))
                          }
                          className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                        >
                          <option value="toman">{t("finance.branchProfit.currencyToman")}</option>
                          <option value="dinar">{t("finance.branchProfit.currencyDinar")}</option>
                        </select>
                      </div>
                    </div>

                    <div className="relative space-y-1.5">
                      <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                        {t("consignment.form.bookSearch")}
                      </label>
                      <div className="relative">
                        <Search className="absolute inset-y-0 end-3 my-auto h-3.5 w-3.5 text-ink/20" />
                        <input
                          type="text"
                          value={itemSearch}
                          onChange={(e) => setItemSearch(e.target.value)}
                          placeholder={t("sales.searchPlaceholder")}
                          className="h-10 w-full rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                        />
                      </div>
                      {itemSearch.trim().length > 0 && itemSearch.trim().length < 2 && (
                        <p className="text-[10px] text-ink/30">{t("consignment.bookSearchHint")}</p>
                      )}
                      {itemSearch.trim().length >= 2 && (
                        <div className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ink/10 bg-white shadow-xl">
                          {isSearchingBooks ? (
                            <p className="px-4 py-3 text-[11px] text-ink/40">{t("common.loading")}</p>
                          ) : bookResults.length === 0 ? (
                            <p className="px-4 py-3 text-[11px] text-ink/40">{t("consignment.noBookResults")}</p>
                          ) : (
                            bookResults.map((book) => (
                              <button
                                key={book.id}
                                type="button"
                                onClick={() => handleAddBook(book)}
                                className="flex w-full items-center justify-between px-4 py-2 text-right text-[12px] font-vazirmatn hover:bg-parchment/30"
                              >
                                <span>{book.title}</span>
                                <span className="text-[10px] text-ink/30">{book.author}</span>
                              </button>
                            ))
                          )}
                        </div>
                      )}
                    </div>

                    <div className="overflow-hidden rounded-xl border border-ink/5">
                      <div className="grid grid-cols-12 gap-0 border-b border-ink/5 bg-parchment/40 px-4 py-2 text-[9px] font-black uppercase tracking-widest text-ink/40">
                        <span className="col-span-5">{t("consignment.table.bookTitle")}</span>
                        <span className="col-span-2 text-center">{t("consignment.table.quantity")}</span>
                        <span className="col-span-2 text-center">{t("consignment.table.purchasePrice")}</span>
                        <span className="col-span-2 text-center">{t("consignment.table.salePrice")}</span>
                        <span className="col-span-1" />
                      </div>
                      <div className="min-h-[100px] divide-y divide-ink/5">
                        {newReceipt.items.map((item) => (
                          <div key={item.book_id} className="grid grid-cols-12 items-center gap-2 px-4 py-3">
                            <span className="col-span-5 truncate text-[12px] font-black font-vazirmatn">
                              {item.title}
                            </span>
                            <div className="col-span-2">
                              <input
                                type="number"
                                min={1}
                                value={item.quantity}
                                onChange={(e) =>
                                  updateItem(item.book_id, "quantity", parseInt(e.target.value || "1", 10))
                                }
                                className="h-8 w-full rounded-lg border border-ink/5 bg-parchment/10 text-center text-[12px] font-black outline-none focus:border-primary/30"
                              />
                            </div>
                            <div className="col-span-2">
                              <input
                                type="number"
                                min={0}
                                value={item.cost_price}
                                onChange={(e) =>
                                  updateItem(item.book_id, "cost_price", parseFloat(e.target.value || "0"))
                                }
                                className="h-8 w-full rounded-lg border border-ink/5 bg-parchment/10 text-center text-[12px] font-black outline-none focus:border-primary/30"
                              />
                            </div>
                            <div className="col-span-2">
                              <input
                                type="number"
                                min={0}
                                value={item.selling_price}
                                onChange={(e) =>
                                  updateItem(
                                    item.book_id,
                                    "selling_price",
                                    parseFloat(e.target.value || "0")
                                  )
                                }
                                className="h-8 w-full rounded-lg border border-ink/5 bg-parchment/10 text-center text-[12px] font-black outline-none focus:border-primary/30"
                              />
                            </div>
                            <div className="col-span-1 flex justify-end">
                              <button
                                type="button"
                                onClick={() => handleRemoveBook(item.book_id)}
                                className="rounded-lg p-1.5 text-rose-400 transition-colors hover:bg-rose-50"
                              >
                                <X className="h-3.5 w-3.5" />
                              </button>
                            </div>
                          </div>
                        ))}
                        {newReceipt.items.length === 0 && (
                          <div className="p-10 text-center text-[10px] font-black uppercase tracking-widest text-ink/20">
                            {t("consignment.form.noBooks")}
                          </div>
                        )}
                      </div>
                    </div>

                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                        {t("consignment.form.notes")}
                      </label>
                      <textarea
                        rows={2}
                        value={newReceipt.notes}
                        onChange={(e) => setNewReceipt((prev) => ({ ...prev, notes: e.target.value }))}
                        className="w-full resize-none rounded-xl border border-ink/10 bg-parchment/20 px-3 py-2.5 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                      />
                    </div>
                  </>
                )}
              </div>

              <div className="flex gap-2 border-t border-ink/5 bg-parchment/20 px-6 py-4">
                <Button
                  type="button"
                  variant="ghost"
                  className="h-10 flex-1 rounded-xl"
                  disabled={isSubmitting}
                  onClick={() => setShowNewForm(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  isLoading={isSubmitting}
                  disabled={!formReady}
                  className="h-10 flex-1 rounded-xl font-black shadow-lg shadow-primary/15"
                >
                  {t("consignment.addReceipt")}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
