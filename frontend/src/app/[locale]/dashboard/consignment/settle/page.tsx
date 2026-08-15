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
import { cn } from "@/lib/utils";

export default function ConsignmentSettlePage() {
  const { t, isArabic, preferredCurrency } = useTranslation();
  const notify = useNotify();
  const { user } = useAuth();
  const isAdmin = user?.role === "admin" || user?.role === "super_admin";
  const userBranchId = user?.branch_id ?? user?.branch?.id ?? null;

  const [suppliers, setSuppliers] = useState<{ id: number; name: string }[]>([]);
  const [settlementData, setSettlementData] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isConfirming, setIsConfirming] = useState(false);

  const initialSupplierId = useMemo(() => {
    if (typeof window === "undefined") return null;
    const sid = new URLSearchParams(window.location.search).get("supplier_id");
    return sid ? Number(sid) : null;
  }, []);

  useEffect(() => {
    apiRequest("/suppliers?status=active")
      .then((data) => setSuppliers(Array.isArray(data) ? data : []))
      .catch(console.error);
  }, []);

  const handleCalculate = useCallback(
    async (supplierId: number, fromDate: string, toDate: string) => {
      setIsLoading(true);
      try {
        const branchQs = !isAdmin && userBranchId ? `&branch_id=${userBranchId}` : "";
        const data = await apiRequest(
          `/consignments/settlement-preview?supplier_id=${supplierId}&period_start=${fromDate}&period_end=${toDate}&currency=${preferredCurrency}${branchQs}`
        );
        setSettlementData(
          (data.items || []).map((item: any) => ({
            title: item.title || `#${item.book_id ?? ""}`,
            qty: item.qty_sold,
            price: item.cost_price,
            total: item.total,
            commission: 0,
          }))
        );
      } catch (error) {
        console.error("Calculation failed:", error);
        setSettlementData([]);
        notify.error("toast.settlementError");
      } finally {
        setIsLoading(false);
      }
    },
    [preferredCurrency, notify, isAdmin, userBranchId]
  );

  const handleConfirm = async (
    supplierId: number,
    fromDate: string,
    toDate: string,
    amount: number
  ) => {
    setIsConfirming(true);
    try {
      await apiRequest("/consignments/settle", {
        method: "POST",
        body: JSON.stringify({
          supplier_id: supplierId,
          period_type: "custom",
          period_start: fromDate,
          period_end: toDate,
          amount,
          currency: preferredCurrency,
          payment_method: "bank_transfer",
          ...(!isAdmin && userBranchId ? { branch_id: Number(userBranchId) } : {}),
        }),
      });
      setSettlementData([]);
      notify.success("toast.settlementSuccess");
    } catch (error) {
      console.error("Settlement failed:", error);
      notify.error("toast.settlementError");
    } finally {
      setIsConfirming(false);
    }
  };

  return (
    <div className="space-y-6 pb-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
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
      </div>

      <SettlementWizard
        suppliers={suppliers}
        initialSupplierId={initialSupplierId}
        onCalculate={handleCalculate}
        onConfirm={handleConfirm}
        settlementData={settlementData}
        isLoading={isLoading}
        isConfirming={isConfirming}
      />
    </div>
  );
}
