"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import { ArrowRight, Calculator, History } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Link } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { SettlementWizard } from "@/components/finance/SettlementWizard";
import { SettlementHistoryPanel } from "@/components/finance/SettlementHistoryPanel";
import {
  ALL_BRANCHES_VALUE,
  supplierAccountsAggregateUrl,
  supplierAccountsUrl,
  uniqueCanonicalSuppliers,
} from "@/lib/supplierAccountSelection";
import { cn } from "@/lib/utils";
import {
  persistOperationalBranchId,
  resolveDefaultOperationalBranchId,
} from "@/lib/operationalBranch";

export default function ConsignmentSettlePage() {
  const { t, isArabic, preferredCurrency } = useTranslation();
  const notify = useNotify();
  const { user } = useAuth();
  const isAdmin = user?.role === "admin" || user?.role === "super_admin";
  const isAccountant = user?.role === "accountant";
  const userBranchId = user?.branch_id ?? user?.branch?.id ?? null;
  const requiresBranchPicker = isAdmin;

  const [branches, setBranches] = useState<{ id: number; name: string }[]>([]);
  const [selectedBranchId, setSelectedBranchId] = useState("");
  const [suppliers, setSuppliers] = useState<{ id: number; name: string }[]>([]);
  const [settlementData, setSettlementData] = useState<any[]>([]);
  const [breakdown, setBreakdown] = useState<any>(null);
  const [sessionKey, setSessionKey] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const [isConfirming, setIsConfirming] = useState(false);
  const [historyReloadKey, setHistoryReloadKey] = useState(0);
  const [historyBranchId, setHistoryBranchId] = useState("");
  const [tab, setTab] = useState<"new" | "history">(() => {
    if (typeof window === "undefined") return "new";
    return new URLSearchParams(window.location.search).get("tab") === "history" ? "history" : "new";
  });

  const selectTab = (next: "new" | "history") => {
    setTab(next);
    if (typeof window === "undefined") return;
    const url = new URL(window.location.href);
    if (next === "history") url.searchParams.set("tab", "history");
    else url.searchParams.delete("tab");
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
  };

  const allBranchesMode = requiresBranchPicker && selectedBranchId === ALL_BRANCHES_VALUE;

  const effectiveBranchId = useMemo(() => {
    if (requiresBranchPicker) {
      if (!selectedBranchId || selectedBranchId === ALL_BRANCHES_VALUE) return null;
      return Number(selectedBranchId);
    }
    return userBranchId ? Number(userBranchId) : null;
  }, [requiresBranchPicker, selectedBranchId, userBranchId]);

  const initialSupplierAccountId = useMemo(() => {
    if (typeof window === "undefined") return null;
    const sid = new URLSearchParams(window.location.search).get("supplier_account_id");
    return sid ? Number(sid) : null;
  }, []);

  useEffect(() => {
    if (!requiresBranchPicker) return;
    apiRequest("/branches?lite=1")
      .then((data) => {
        const rows = (Array.isArray(data) ? data : []).filter(
          (b: { type?: string }) => b.type === "store" || b.type === "warehouse"
        );
        setBranches(rows);
        const defaultId = resolveDefaultOperationalBranchId(
          { role: user?.role, branch_id: userBranchId },
          { availableBranchIds: rows.map((b: { id: number }) => Number(b.id)) }
        );
        if (defaultId) {
          setSelectedBranchId(String(defaultId));
          setHistoryBranchId((current) => current || String(defaultId));
          persistOperationalBranchId(defaultId);
        } else {
          setHistoryBranchId((current) => current || ALL_BRANCHES_VALUE);
        }
      })
      .catch(console.error);
  }, [requiresBranchPicker, user?.role, userBranchId]);

  useEffect(() => {
    if (!allBranchesMode && !effectiveBranchId) {
      setSuppliers([]);
      return;
    }
    let cancelled = false;
    const url = allBranchesMode
      ? supplierAccountsAggregateUrl(true)
      : supplierAccountsUrl(effectiveBranchId as number, true);
    apiRequest(url)
      .then((data) => {
        if (cancelled) return;
        const rows = Array.isArray(data) ? data : [];
        setSuppliers(
          allBranchesMode
            ? uniqueCanonicalSuppliers(rows)
            : rows.map((row: { id: number; display_name?: string; name?: string }) => ({
                id: row.id,
                name: row.display_name || row.name || `#${row.id}`,
              }))
        );
      })
      .catch(() => {
        if (!cancelled) setSuppliers([]);
      });
    return () => {
      cancelled = true;
    };
  }, [allBranchesMode, effectiveBranchId]);

  const handleBranchChange = (value: string) => {
    setSelectedBranchId(value);
    setSettlementData([]);
    setBreakdown(null);
    setSessionKey((key) => key + 1);
    persistOperationalBranchId(value === ALL_BRANCHES_VALUE ? null : value ? Number(value) : null);
  };

  const handleCalculate = useCallback(
    async (supplierIdOrAccountId: number, fromDate: string, toDate: string) => {
      if (!allBranchesMode && !effectiveBranchId) return;
      setIsLoading(true);
      try {
        const query = allBranchesMode
          ? `/consignments/settlement-preview?aggregate=1&supplier_id=${supplierIdOrAccountId}&period_start=${fromDate}&period_end=${toDate}&currency=${preferredCurrency}`
          : `/consignments/settlement-preview?supplier_account_id=${supplierIdOrAccountId}&period_start=${fromDate}&period_end=${toDate}&currency=${preferredCurrency}&branch_id=${effectiveBranchId}`;
        const data = await apiRequest(query);
        setBreakdown(
          data.breakdown
            ? { ...data.breakdown, by_branch: data.by_branch || [] }
            : data.by_branch
              ? { by_branch: data.by_branch }
              : null
        );
        setSettlementData(
          (data.items || []).map((item: any) => {
            const bookId = item.book_id != null ? Number(item.book_id) : null;
            return {
              title: item.title || (bookId ? `#${bookId}` : "—"),
              qty: Number(item.open_qty ?? item.qty_sold ?? 0),
              remainingQty: Number(item.remaining_qty ?? 0),
              price: Number(item.unit_cost ?? item.cost_price ?? 0),
              total: Number(item.open_amount ?? item.total ?? 0),
              commission: 0,
              publisherShare: Number(item.open_amount ?? item.total ?? 0),
              kind: item.kind === "gift" ? "gift" : "sale",
              branchId: item.branch_id != null ? Number(item.branch_id) : null,
              branchName: item.branch_name ? String(item.branch_name) : null,
            };
          })
        );
      } catch (error) {
        console.error("Calculation failed:", error);
        setSettlementData([]);
        setBreakdown(null);
        notify.error("toast.settlementError");
      } finally {
        setIsLoading(false);
      }
    },
    [preferredCurrency, notify, effectiveBranchId, allBranchesMode]
  );

  const handleConfirm = async (
    supplierAccountId: number,
    fromDate: string,
    toDate: string,
    amount: number
  ) => {
    if (!allBranchesMode && !effectiveBranchId) return;
    setIsConfirming(true);
    try {
      const result = allBranchesMode
        ? await apiRequest("/consignments/settle?aggregate=1", {
            method: "POST",
            body: JSON.stringify({
              supplier_id: supplierAccountId,
              period_type: "custom",
              period_start: fromDate,
              period_end: toDate,
              amount,
              expected_total: breakdown?.remaining_payable,
              currency: preferredCurrency,
              payment_method: "bank_transfer",
            }),
          })
        : await apiRequest("/consignments/settle", {
            method: "POST",
            body: JSON.stringify({
              supplier_account_id: supplierAccountId,
              branch_id: effectiveBranchId,
              period_type: "custom",
              period_start: fromDate,
              period_end: toDate,
              amount,
              currency: preferredCurrency,
              payment_method: "bank_transfer",
            }),
          });
      setSettlementData([]);
      setBreakdown(null);
      setSessionKey((key) => key + 1);
      setHistoryReloadKey((key) => key + 1);
      notify.success("toast.settlementSuccess");
      return result;
    } catch (error) {
      console.error("Settlement failed:", error);
      notify.error("toast.settlementError");
      return false;
    } finally {
      setIsConfirming(false);
    }
  };

  return (
    <div className="space-y-5 pb-10">
      <div className="flex items-center gap-3">
        <Link href="/dashboard/consignment">
          <Button
            variant="ghost"
            size="sm"
            className="h-9 w-9 rounded-xl border border-ink/5 p-0"
          >
            <ArrowRight className={cn("h-4 w-4", isArabic && "rotate-180")} />
          </Button>
        </Link>
        <div>
          <h1 className="text-xl font-black font-vazirmatn text-ink">
            {t("consignment.settle.title")}
          </h1>
          <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
            {t("consignment.settle.subtitle")}
          </p>
        </div>
      </div>

      <div className="flex gap-1.5 rounded-2xl border border-white/80 bg-white/70 p-1">
        <button
          type="button"
          onClick={() => selectTab("new")}
          className={cn(
            "flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl text-[11px] font-black",
            tab === "new" ? "bg-white text-primary shadow-sm" : "text-ink/40"
          )}
        >
          <Calculator className="h-3.5 w-3.5" />
          {t("consignment.settle.newTab")}
        </button>
        <button
          type="button"
          onClick={() => selectTab("history")}
          className={cn(
            "flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl text-[11px] font-black",
            tab === "history" ? "bg-white text-primary shadow-sm" : "text-ink/40"
          )}
        >
          <History className="h-3.5 w-3.5" />
          {t("consignment.settle.historyTab")}
        </button>
      </div>

      {tab === "history" ? (
        <SettlementHistoryPanel
          isAdmin={isAdmin}
          branches={branches}
          selectedBranchId={
            requiresBranchPicker
              ? historyBranchId || selectedBranchId || ALL_BRANCHES_VALUE
              : String(effectiveBranchId || "")
          }
          onBranchChange={setHistoryBranchId}
          reloadKey={historyReloadKey}
        />
      ) : (
        <SettlementWizard
          suppliers={suppliers}
          initialSupplierAccountId={initialSupplierAccountId}
          onCalculate={handleCalculate}
          onConfirm={handleConfirm}
          settlementData={settlementData}
          breakdown={breakdown}
          isLoading={isLoading}
          isConfirming={isConfirming}
          branchId={effectiveBranchId}
          disabled={isAccountant && !userBranchId}
          sessionKey={sessionKey}
          showBranchPicker={requiresBranchPicker}
          allowAllBranches={requiresBranchPicker}
          branches={branches}
          selectedBranchId={selectedBranchId}
          onBranchChange={handleBranchChange}
        />
      )}
    </div>
  );
}
