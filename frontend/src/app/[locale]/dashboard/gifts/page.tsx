"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Gift,
  Plus,
  User,
  CalendarDays,
  AlertTriangle,
  X,
  Search,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";

interface GiftRow {
  id: number;
  quantity: number;
  recipient_name: string;
  cost_value: number;
  currency: "toman" | "dinar";
  is_consignment: boolean;
  accounting_status: "pending" | "settled";
  settlement_status?: "not_applicable" | "unsettled" | "partially_settled" | "settled";
  remaining_payable?: string | number;
  gifted_at: string;
  book?: { id: number; title: string };
  branch?: { id: number; name: string };
  supplier?: { id: number; name: string };
}

interface InventoryOption {
  id: number;
  book_id: number;
  quantity: number;
  type?: string;
  price_toman?: number;
  price_dinar?: number;
  cost_price_toman?: number;
  cost_price_dinar?: number;
  book?: { id: number; title: string; author?: string };
  supplier?: { id: number; name: string };
}

function useDebouncedValue<T>(value: T, delay = 300) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

function unitCostFor(inv: InventoryOption | null, currency: "toman" | "dinar") {
  if (!inv) return 0;
  // Gift expense uses purchase cost when set; otherwise the selling price the user entered.
  if (currency === "dinar") {
    const cost = Number(inv.cost_price_dinar || 0);
    const sell = Number(inv.price_dinar || 0);
    return cost > 0 ? cost : sell;
  }
  const cost = Number(inv.cost_price_toman || 0);
  const sell = Number(inv.price_toman || 0);
  // Guard against legacy silent 70% cost (intake bug): prefer selling price when cost ≈ 70% of sell
  if (cost > 0 && sell > 0 && Math.abs(cost / sell - 0.7) < 0.001) {
    return sell;
  }
  return cost > 0 ? cost : sell;
}

const EMPTY_FORM = {
  book_id: "",
  branch_id: "",
  quantity: "1",
  recipient_name: "",
  cost_value: "",
  currency: "toman" as "toman" | "dinar",
  is_consignment: false,
  supplier_account_id: "",
  gifted_at: new Date().toISOString().split("T")[0],
  reason: "",
};

export default function GiftsPage() {
  const { t, formatNumber, isArabic, preferredCurrency } = useTranslation();
  const notify = useNotify();
  const { user } = useAuth();
  const isAdmin = user?.role === "admin" || user?.role === "super_admin";
  const userBranchId = user?.branch_id
    ? Number(user.branch_id)
    : user?.branch?.id
      ? Number(user.branch.id)
      : null;
  const canPickBranch = isAdmin;

  const currencySymbol =
    preferredCurrency === "dinar"
      ? t("common.currency.dinarSymbol")
      : t("common.currency.tomanSymbol");

  const [gifts, setGifts] = useState<GiftRow[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [totalCount, setTotalCount] = useState(0);
  const [search, setSearch] = useState("");
  const debouncedSearch = useDebouncedValue(search, 300);
  const [isLoading, setIsLoading] = useState(true);
  usePageReady(!isLoading);
  const [isLoadingMore, setIsLoadingMore] = useState(false);

  const [showForm, setShowForm] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [form, setForm] = useState({ ...EMPTY_FORM, currency: preferredCurrency as "toman" | "dinar" });
  const [costManualOverride, setCostManualOverride] = useState(false);

  const [branches, setBranches] = useState<{ id: number; name: string; type?: string }[]>([]);
  const [suppliers, setSuppliers] = useState<{ id: number; name: string }[]>([]);
  const [formReady, setFormReady] = useState(false);
  const [isLoadingForm, setIsLoadingForm] = useState(false);
  const formLoadedRef = useRef(false);

  const [bookSearch, setBookSearch] = useState("");
  const debouncedBookSearch = useDebouncedValue(bookSearch, 350);
  const [inventoryOptions, setInventoryOptions] = useState<InventoryOption[]>([]);
  const [isSearchingBooks, setIsSearchingBooks] = useState(false);
  const [selectedInventory, setSelectedInventory] = useState<InventoryOption | null>(null);

  const fetchGifts = useCallback(
    async (pageNum = 1, append = false) => {
      if (append) setIsLoadingMore(true);
      else setIsLoading(true);
      try {
        const params = new URLSearchParams({ page: String(pageNum) });
        if (debouncedSearch.trim()) params.set("q", debouncedSearch.trim());
        const data = await apiRequest(`/gifts?${params.toString()}`);
        const rows: GiftRow[] = data.data || [];
        setGifts((prev) => (append ? [...prev, ...rows] : rows));
        setPage(data.current_page || pageNum);
        setHasMore(Boolean(data.next_page_url));
        setTotalCount(data.total ?? rows.length);
      } catch (error) {
        console.error("Failed to fetch gifts:", error);
        if (!append) setGifts([]);
      } finally {
        setIsLoading(false);
        setIsLoadingMore(false);
      }
    },
    [debouncedSearch]
  );

  useEffect(() => {
    fetchGifts(1, false);
  }, [fetchGifts]);

  const loadFormData = useCallback(async () => {
    if (formLoadedRef.current) {
      setFormReady(true);
      return;
    }
    setIsLoadingForm(true);
    try {
      const [brData] = await Promise.all([
        apiRequest("/branches?lite=1"),
      ]);
      const branchListRaw = Array.isArray(brData) ? brData : [];
      const branchList = canPickBranch
        ? branchListRaw
        : branchListRaw.filter((b: { id: number }) => Number(b.id) === Number(userBranchId));
      const supplierList: { id: number; name: string; supplier_id?: number }[] = [];
      setBranches(branchList);
      setSuppliers(supplierList);
      formLoadedRef.current = true;
      setFormReady(true);

      const defaultBranch =
        !canPickBranch && userBranchId
          ? String(userBranchId)
          : user?.branch?.id && branchList.some((b: { id: number }) => b.id === user.branch?.id)
            ? String(user.branch.id)
            : "";
      setForm((f) => ({
        ...f,
        branch_id: f.branch_id || defaultBranch,
        currency: preferredCurrency,
      }));
    } catch (error) {
      console.error("Failed to load gift form data:", error);
      notify.error("toast.giftError");
    } finally {
      setIsLoadingForm(false);
    }
  }, [notify, preferredCurrency, user?.branch?.id, userBranchId, canPickBranch]);

  useEffect(() => {
    if (!form.branch_id) {
      setSuppliers([]);
      return;
    }
    apiRequest(`/supplier-accounts?branch_id=${form.branch_id}`)
      .then((data) => {
        const rows = Array.isArray(data) ? data : [];
        setSuppliers(rows.map((row: { id: number; display_name?: string; name?: string }) => ({
          id: row.id,
          name: row.display_name || row.name || `#${row.id}`,
        })));
      })
      .catch(() => setSuppliers([]));
  }, [form.branch_id]);

  const openForm = async () => {
    setCostManualOverride(false);
    setSelectedInventory(null);
    setBookSearch("");
    setInventoryOptions([]);
    setForm({
      ...EMPTY_FORM,
      currency: preferredCurrency,
      branch_id: !canPickBranch && userBranchId ? String(userBranchId) : user?.branch?.id ? String(user.branch.id) : "",
    });
    setShowForm(true);
    await loadFormData();
  };

  // Search books in selected branch inventory
  useEffect(() => {
    if (!showForm || !form.branch_id) {
      setInventoryOptions([]);
      return;
    }
    if (debouncedBookSearch.trim().length < 1) {
      // Prefetch a small stock list when branch is chosen
      let cancelled = false;
      setIsSearchingBooks(true);
      apiRequest(`/warehouse/${form.branch_id}/inventory?lite=1`)
        .then((data) => {
          if (!cancelled) setInventoryOptions(Array.isArray(data) ? data.slice(0, 15) : []);
        })
        .catch(() => {
          if (!cancelled) setInventoryOptions([]);
        })
        .finally(() => {
          if (!cancelled) setIsSearchingBooks(false);
        });
      return () => {
        cancelled = true;
      };
    }

    let cancelled = false;
    setIsSearchingBooks(true);
    apiRequest(
      `/warehouse/${form.branch_id}/inventory?lite=1&search=${encodeURIComponent(debouncedBookSearch.trim())}`
    )
      .then((data) => {
        if (!cancelled) setInventoryOptions(Array.isArray(data) ? data : []);
      })
      .catch(() => {
        if (!cancelled) setInventoryOptions([]);
      })
      .finally(() => {
        if (!cancelled) setIsSearchingBooks(false);
      });

    return () => {
      cancelled = true;
    };
  }, [showForm, form.branch_id, debouncedBookSearch]);

  const recalcCost = useCallback(
    (inv: InventoryOption | null, quantity: string, currency: "toman" | "dinar", manual: boolean) => {
      if (manual) return;
      const qty = Math.max(1, parseInt(quantity || "1", 10) || 1);
      const unit = unitCostFor(inv, currency);
      setForm((f) => ({ ...f, cost_value: String(unit * qty) }));
    },
    []
  );

  const selectBook = (inv: InventoryOption) => {
    setSelectedInventory(inv);
    setBookSearch(inv.book?.title || "");
    setInventoryOptions([]);
    const isConsignment = inv.type === "consignment";
    setForm((f) => {
      const next = {
        ...f,
        book_id: String(inv.book_id),
        is_consignment: isConsignment,
        supplier_account_id: isConsignment && inv.supplier?.id ? "" : f.supplier_account_id,
      };
      return next;
    });
    setCostManualOverride(false);
    recalcCost(inv, form.quantity, form.currency, false);
  };

  const handleQuantityChange = (value: string) => {
    setForm((f) => ({ ...f, quantity: value }));
    recalcCost(selectedInventory, value, form.currency, costManualOverride);
  };

  const handleCurrencyChange = (currency: "toman" | "dinar") => {
    setForm((f) => ({ ...f, currency }));
    recalcCost(selectedInventory, form.quantity, currency, costManualOverride);
  };

  const handleSubmit = async () => {
    const lockedBranchId = !canPickBranch && userBranchId
      ? String(userBranchId)
      : form.branch_id;
    if (!form.book_id || !lockedBranchId || !form.recipient_name || form.cost_value === "") {
      notify.error("toast.requiredFields");
      return;
    }
    if (form.is_consignment && !form.supplier_account_id) {
      notify.error("toast.supplierBranchRequired");
      return;
    }

    const qty = parseInt(form.quantity, 10);
    if (!qty || qty < 1) {
      notify.error("toast.requiredFields");
      return;
    }
    if (selectedInventory && qty > selectedInventory.quantity) {
      notify.rawError(t("warehouse.errors.insufficientStock"));
      return;
    }

    setIsSubmitting(true);
    try {
      await apiRequest("/gifts", {
        method: "POST",
        body: JSON.stringify({
          book_id: parseInt(form.book_id, 10),
          branch_id: parseInt(lockedBranchId, 10),
          quantity: qty,
          recipient_name: form.recipient_name.trim(),
          cost_value: parseFloat(form.cost_value),
          currency: form.currency,
          is_consignment: form.is_consignment,
          supplier_account_id: form.is_consignment ? parseInt(form.supplier_account_id, 10) : null,
          gifted_at: form.gifted_at,
          reason: form.reason || null,
        }),
      });
      setShowForm(false);
      notify.success("toast.giftSuccess");
      await fetchGifts(1, false);
    } catch (error: unknown) {
      console.error("Gift submit failed:", error);
      const message = error instanceof Error ? error.message : "";
      notify.rawError(message || t("toast.giftError"));
    } finally {
      setIsSubmitting(false);
    }
  };

  const settleGift = async (id: number) => {
    try {
      await apiRequest(`/gifts/${id}/status`, {
        method: "PUT",
        body: JSON.stringify({ accounting_status: "settled" }),
      });
      notify.success("toast.settlementSuccess");
      await fetchGifts(1, false);
    } catch (error) {
      console.error("Settle failed:", error);
      notify.error("toast.giftSettleError");
    }
  };

  const kpi = useMemo(() => {
    const totalCost = gifts
      .filter((g) => g.currency === preferredCurrency)
      .reduce((a, g) => a + Number(g.cost_value || 0), 0);
    const pendingCount = gifts.filter(
      (g) =>
        g.is_consignment &&
        (g.settlement_status === "unsettled" ||
          g.settlement_status === "partially_settled" ||
          (!g.settlement_status && g.accounting_status === "pending"))
    ).length;
    return [
      { label: t("gifts.kpi.total"), value: formatNumber(totalCount || gifts.length), color: "text-primary" },
      {
        label: t("gifts.kpi.totalCost"),
        value: `${formatNumber(totalCost)} ${currencySymbol}`,
        color: "text-rose-500",
      },
      { label: t("gifts.kpi.pendingSettlement"), value: formatNumber(pendingCount), color: "text-amber-500" },
    ];
  }, [gifts, totalCount, preferredCurrency, currencySymbol, formatNumber, t]);

  const unitCost = unitCostFor(selectedInventory, form.currency);
  const storeBranches = useMemo(() => {
    const list = branches.filter((b) => b.type === "store" || b.type === "warehouse" || !b.type);
    if (canPickBranch) {
      const seen = new Set<string>();
      return list.filter((b) => {
        const key = (b.name || "").trim().toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
      });
    }
    return list.filter((b) => Number(b.id) === Number(userBranchId));
  }, [branches, canPickBranch, userBranchId]);

  return (
    <div className="space-y-5 pb-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-black font-vazirmatn text-ink">{t("gifts.title")}</h1>
          <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
            {t("gifts.subtitle")}
          </p>
        </div>
        <Button
          variant="primary"
          size="sm"
          className="h-9 rounded-xl px-4 text-[11px] shadow-lg shadow-primary/10"
          onClick={openForm}
        >
          <Plus className="ms-1.5 h-3.5 w-3.5" />
          {t("gifts.submit")}
        </Button>
      </div>

      <div className="grid grid-cols-3 gap-3">
        {kpi.map((item) => (
          <Card key={item.label} className="rounded-[14px] border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
            <CardContent className="p-4">
              <p className="mb-1 text-[9px] font-bold uppercase tracking-widest text-ink/35">{item.label}</p>
              <p className={cn("text-xl font-black font-vazirmatn tabular-nums", item.color)}>
                {isLoading ? "…" : item.value}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex items-start gap-3 rounded-xl border border-amber-100 bg-amber-50/60 px-4 py-3">
        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
        <p className="text-[11px] leading-relaxed font-vazirmatn text-amber-700">
          {t("gifts.consignmentNote")}
        </p>
      </div>

      <div className="relative">
        <Search className="pointer-events-none absolute inset-y-0 end-3 my-auto h-3.5 w-3.5 text-ink/20" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("gifts.searchPlaceholder")}
          className="h-10 w-full rounded-xl border border-white bg-white/70 pe-9 ps-3 text-[12px] font-vazirmatn shadow-sm outline-none placeholder:text-ink/20 focus:border-primary/30"
        />
      </div>

      <div className="space-y-3">
        {isLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-20 animate-pulse rounded-2xl bg-parchment/20" />
          ))
        ) : gifts.length === 0 ? (
          <div className="flex flex-col items-center gap-3 py-16 text-ink/20">
            <Gift className="h-10 w-10" />
            <p className="text-[12px] font-black font-vazirmatn">{t("gifts.empty")}</p>
            <Button size="sm" className="mt-1 h-9 rounded-xl text-[11px]" onClick={openForm}>
              <Plus className="ms-1.5 h-3.5 w-3.5" />
              {t("gifts.submit")}
            </Button>
          </div>
        ) : (
          gifts.map((gift) => (
            <Card
              key={gift.id}
              className="overflow-hidden rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl transition-all hover:shadow-lg"
            >
              <CardContent className="p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                  <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rose-100 bg-gradient-to-br from-rose-100 to-pink-50">
                    <Gift className="h-5 w-5 text-rose-400" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[13px] font-black font-vazirmatn text-ink">
                        {gift.book?.title || "—"}
                      </span>
                      <Badge
                        className={cn(
                          "border text-[8px] font-black",
                          gift.settlement_status === "settled" ||
                            (!gift.settlement_status && gift.accounting_status === "settled")
                            ? "border-emerald-100 bg-emerald-50 text-emerald-600"
                            : gift.settlement_status === "partially_settled"
                              ? "border-sky-100 bg-sky-50 text-sky-700"
                              : "border-amber-100 bg-amber-50 text-amber-600"
                        )}
                      >
                        {gift.settlement_status === "partially_settled"
                          ? "تسویه جزئی"
                          : gift.settlement_status === "settled" || gift.accounting_status === "settled"
                            ? t("gifts.status.settled")
                            : t("gifts.status.pending")}
                      </Badge>
                      {gift.is_consignment && (
                        <Badge className="border border-amber-200 bg-amber-50 text-[8px] font-black text-amber-700">
                          {t("gifts.consignmentFrom", { supplier: gift.supplier?.name || "—" })}
                        </Badge>
                      )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-4 text-[9px] text-ink/35">
                      <span className="flex items-center gap-1">
                        <User className="h-3 w-3" />
                        {gift.recipient_name}
                      </span>
                      <span className="flex items-center gap-1">
                        <CalendarDays className="h-3 w-3" />
                        {gift.gifted_at}
                      </span>
                      <span>{t("sales.stockCount", { count: gift.quantity })}</span>
                      <span>{gift.branch?.name}</span>
                    </div>
                  </div>
                  <div className="shrink-0 text-end">
                    <p className="text-[15px] font-black font-vazirmatn tabular-nums text-rose-500">
                      {formatNumber(Number(gift.cost_value))}{" "}
                      {gift.currency === "dinar"
                        ? t("common.currency.dinarSymbol")
                        : t("common.currency.tomanSymbol")}
                    </p>
                  </div>
                  {gift.is_consignment &&
                    gift.settlement_status !== "settled" &&
                    gift.settlement_status !== "not_applicable" &&
                    gift.accounting_status === "pending" && (
                    <Button
                      size="sm"
                      className="h-8 shrink-0 rounded-lg bg-emerald-500 px-3 text-[10px] font-bold text-white hover:bg-emerald-600"
                      onClick={() => settleGift(gift.id)}
                    >
                      {t("finance.makeSettlement")}
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          ))
        )}

        {hasMore && (
          <div className="flex justify-center pt-2">
            <Button
              variant="outline"
              size="sm"
              isLoading={isLoadingMore}
              className="h-9 rounded-xl px-5 text-[11px]"
              onClick={() => fetchGifts(page + 1, true)}
            >
              {t("gifts.loadMore")}
            </Button>
          </div>
        )}
      </div>

      {showForm && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-ink/30 p-4 backdrop-blur-sm"
          onClick={(e) => e.target === e.currentTarget && !isSubmitting && setShowForm(false)}
        >
          <div className="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-ink/5 px-6 py-5">
              <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("gifts.submit")}</h2>
              <button
                type="button"
                disabled={isSubmitting}
                onClick={() => setShowForm(false)}
                className="rounded-xl p-2 hover:bg-ink/5"
              >
                <X className="h-4 w-4 text-ink/40" />
              </button>
            </div>

            <div className="max-h-[70vh] space-y-4 overflow-y-auto p-6">
              {isLoadingForm && !formReady ? (
                <div className="space-y-3 py-6">
                  {Array.from({ length: 3 }).map((_, i) => (
                    <div key={i} className="h-10 animate-pulse rounded-xl bg-parchment/40" />
                  ))}
                </div>
              ) : (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("gifts.form.branch")}
                      </label>
                      <select
                        value={form.branch_id}
                        disabled={!canPickBranch}
                        onChange={(e) => {
                          setSelectedInventory(null);
                          setBookSearch("");
                          setForm((f) => ({ ...f, branch_id: e.target.value, book_id: "", cost_value: "" }));
                          setCostManualOverride(false);
                        }}
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none disabled:opacity-60 disabled:bg-parchment/30"
                      >
                        {canPickBranch && (
                          <option value="">{t("expenses.form.selectBranch")}</option>
                        )}
                        {storeBranches.map((b) => (
                          <option key={b.id} value={b.id}>
                            {b.name}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("gifts.form.date")}
                      </label>
                      <input
                        type="date"
                        value={form.gifted_at}
                        onChange={(e) => setForm((f) => ({ ...f, gifted_at: e.target.value }))}
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] outline-none"
                      />
                    </div>
                  </div>

                  <div className="relative space-y-1.5">
                    <label className="text-[10px] font-black uppercase text-ink/40">
                      {t("gifts.form.book")}
                    </label>
                    <div className="relative">
                      <Search className="absolute inset-y-0 end-3 my-auto h-3.5 w-3.5 text-ink/20" />
                      <input
                        type="text"
                        disabled={!form.branch_id}
                        value={bookSearch}
                        onChange={(e) => {
                          setBookSearch(e.target.value);
                          if (selectedInventory) {
                            setSelectedInventory(null);
                            setForm((f) => ({ ...f, book_id: "", cost_value: "" }));
                          }
                        }}
                        placeholder={
                          form.branch_id
                            ? t("gifts.form.searchBook")
                            : t("gifts.form.selectBranchFirst")
                        }
                        className="h-10 w-full rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-vazirmatn outline-none disabled:opacity-50"
                      />
                    </div>
                    {form.branch_id && (bookSearch.length > 0 || inventoryOptions.length > 0) && !selectedInventory && (
                      <div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ink/10 bg-white shadow-xl">
                        {isSearchingBooks ? (
                          <p className="px-4 py-3 text-[11px] text-ink/40">{t("common.loading")}</p>
                        ) : inventoryOptions.length === 0 ? (
                          <p className="px-4 py-3 text-[11px] text-ink/40">{t("consignment.noBookResults")}</p>
                        ) : (
                          inventoryOptions.map((inv) => (
                            <button
                              key={inv.id}
                              type="button"
                              onClick={() => selectBook(inv)}
                              className="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-right text-[12px] font-vazirmatn hover:bg-parchment/30"
                            >
                              <span className="truncate font-bold">{inv.book?.title}</span>
                              <span className="shrink-0 text-[10px] text-ink/35">
                                {t("gifts.form.stockAvailable", {
                                  count: formatNumber(inv.quantity),
                                })}
                              </span>
                            </button>
                          ))
                        )}
                      </div>
                    )}
                    {selectedInventory && (
                      <p className="text-[10px] text-primary">
                        {t("gifts.form.stockAvailable", {
                          count: formatNumber(selectedInventory.quantity),
                        })}
                        {" · "}
                        {t("gifts.form.unitCost")}: {formatNumber(unitCost)} {currencySymbol}
                      </p>
                    )}
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("gifts.form.recipient")}
                      </label>
                      <input
                        type="text"
                        value={form.recipient_name}
                        onChange={(e) => setForm((f) => ({ ...f, recipient_name: e.target.value }))}
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] outline-none"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("gifts.form.quantity")}
                      </label>
                      <input
                        type="number"
                        min={1}
                        max={selectedInventory?.quantity || undefined}
                        value={form.quantity}
                        onChange={(e) => handleQuantityChange(e.target.value)}
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] outline-none"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("consignment.form.currency")}
                      </label>
                      <select
                        value={form.currency}
                        onChange={(e) =>
                          handleCurrencyChange(e.target.value as "toman" | "dinar")
                        }
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none"
                      >
                        <option value="toman">{t("finance.branchProfit.currencyToman")}</option>
                        <option value="dinar">{t("finance.branchProfit.currencyDinar")}</option>
                      </select>
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-black uppercase text-ink/40">
                        {t("gifts.form.costValue")}
                      </label>
                      <input
                        type="number"
                        min={0}
                        value={form.cost_value}
                        onChange={(e) => {
                          setCostManualOverride(true);
                          setForm((f) => ({ ...f, cost_value: e.target.value }));
                        }}
                        className="h-10 w-full rounded-xl border border-ink/10 px-3 text-[12px] outline-none"
                      />
                      {!costManualOverride && selectedInventory && (
                        <p className="text-[9px] text-ink/35">{t("gifts.form.autoCostHint")}</p>
                      )}
                    </div>
                  </div>

                  <label className="flex cursor-pointer items-center gap-2 text-[12px] font-vazirmatn">
                    <input
                      type="checkbox"
                      checked={form.is_consignment}
                      onChange={(e) =>
                        setForm((f) => ({ ...f, is_consignment: e.target.checked }))
                      }
                    />
                    {t("gifts.form.isConsignment")}
                  </label>
                  {form.is_consignment && (
                    <select
                      value={form.supplier_account_id}
                      onChange={(e) => setForm((f) => ({ ...f, supplier_account_id: e.target.value }))}
                      className="h-10 w-full rounded-xl border border-amber-200 bg-amber-50/30 px-3 text-[12px] outline-none"
                    >
                      <option value="">{t("gifts.form.selectPublisher")}</option>
                      {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.name}
                        </option>
                      ))}
                    </select>
                  )}
                </>
              )}
            </div>

            <div className="flex gap-2 border-t border-ink/5 bg-parchment/20 px-6 py-4">
              <Button
                variant="ghost"
                className="h-10 flex-1 rounded-xl"
                disabled={isSubmitting}
                onClick={() => setShowForm(false)}
              >
                {t("common.cancel")}
              </Button>
              <Button
                className="h-10 flex-1 rounded-xl font-black"
                isLoading={isSubmitting}
                disabled={!formReady}
                onClick={handleSubmit}
              >
                {t("gifts.submit")}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
