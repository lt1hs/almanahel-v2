"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import { ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Link } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { SettlementWizard } from "@/components/finance/SettlementWizard";
import { supplierAccountsUrl } from "@/lib/supplierAccountSelection";
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

  const effectiveBranchId = useMemo(() => {
    if (requiresBranchPicker) return selectedBranchId ? Number(selectedBranchId) : null;
    return userBranchId ? Number(userBranchId) : null;
  }, [requiresBranchPicker, selectedBranchId, userBranchId]);

  const canUseSettlement = Boolean(effectiveBranchId) && !(isAccountant && !userBranchId);

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
          persistOperationalBranchId(defaultId);
        }
      })
      .catch(console.error);
  }, [requiresBranchPicker, user?.role, userBranchId]);

  useEffect(() => {
    if (!effectiveBranchId) {
      setSuppliers([]);
      return;
    }
    let cancelled = false;
    apiRequest(supplierAccountsUrl(effectiveBranchId, true))
      .then((data) => {
        if (cancelled) return;
        const rows = Array.isArray(data) ? data : [];
        setSuppliers(
          rows.map((row: { id: number; display_name?: string; name?: string }) => ({
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
  }, [effectiveBranchId]);

  const handleBranchChange = (value: string) => {
    setSelectedBranchId(value);
    setSettlementData([]);
    setBreakdown(null);
    setSessionKey((key) => key + 1);
    persistOperationalBranchId(value ? Number(value) : null);
  };

  const handleCalculate = useCallback(
    async (supplierAccountId: number, fromDate: string, toDate: string) => {
      if (!effectiveBranchId) return;
      setIsLoading(true);
      try {
        const data = await apiRequest(
          `/consignments/settlement-preview?supplier_account_id=${supplierAccountId}&period_start=${fromDate}&period_end=${toDate}&currency=${preferredCurrency}&branch_id=${effectiveBranchId}`
        );
        setBreakdown(data.breakdown || null);
        setSettlementData(
          (data.items || []).map((item: any) => {
            const bookId = item.book_id != null ? Number(item.book_id) : null;
            return {
              title: item.title || (bookId ? `#${bookId}` : "—"),
              qty: Number(item.open_qty ?? item.qty_sold ?? 0),
              price: Number(item.unit_cost ?? item.cost_price ?? 0),
              total: Number(item.open_amount ?? item.total ?? 0),
              commission: 0,
              publisherShare: Number(item.open_amount ?? item.total ?? 0),
              kind: item.kind === "gift" ? "gift" : "sale",
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
    [preferredCurrency, notify, effectiveBranchId]
  );

  const handleConfirm = async (
    supplierAccountId: number,
    fromDate: string,
    toDate: string,
    amount: number
  ) => {
    if (!effectiveBranchId) return;
    setIsConfirming(true);
    try {
      await apiRequest("/consignments/settle", {
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
      notify.success("toast.settlementSuccess");
    } catch (error) {
      console.error("Settlement failed:", error);
      notify.error("toast.settlementError");
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
        branches={branches}
        selectedBranchId={selectedBranchId}
        onBranchChange={handleBranchChange}
      />
    </div>
  );
}
