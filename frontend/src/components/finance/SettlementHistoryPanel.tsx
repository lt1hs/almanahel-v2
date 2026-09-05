"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Building2, CalendarDays, FileText, History, Palette } from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Link } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";
import {
  ALL_BRANCHES_VALUE,
} from "@/lib/supplierAccountSelection";
import {
  buildSettlementHistoryUrl,
} from "@/lib/financeRequests";
import {
  cacheSettlementInvoiceRow,
  type SettlementInvoiceSource,
} from "@/lib/settlementInvoiceLayout";
import { printSettlement, buildSettlementPrintLabels } from "@/lib/printSettlement";

type SettlementRow = SettlementInvoiceSource & {
  id: number;
  payment_method?: string | null;
};

function dayPart(value: string | null | undefined): string {
  return value ? String(value).slice(0, 10) : "—";
}

export function SettlementHistoryPanel({
  isAdmin,
  branches,
  selectedBranchId,
  onBranchChange,
  reloadKey = 0,
}: {
  isAdmin: boolean;
  branches: { id: number; name: string }[];
  selectedBranchId: string;
  onBranchChange?: (branchId: string) => void;
  reloadKey?: number;
}) {
  const { t, formatNumber, language } = useTranslation();
  const notify = useNotify();
  const [rows, setRows] = useState<SettlementRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);

  const allBranches = isAdmin && selectedBranchId === ALL_BRANCHES_VALUE;
  const historyUrl = useMemo(
    () =>
      buildSettlementHistoryUrl({
        aggregate: allBranches,
        branchId: allBranches ? null : selectedBranchId,
      }),
    [allBranches, selectedBranchId]
  );

  const load = useCallback(
    async (nextPage: number, replace: boolean) => {
      if (!historyUrl) {
        setRows([]);
        setIsLoading(false);
        return;
      }
      setIsLoading(true);
      try {
        const data = await apiRequest(`${historyUrl}${historyUrl.includes("?") ? "&" : "?"}page=${nextPage}`);
        const list = (data?.data ?? data ?? []) as SettlementRow[];
        const items = Array.isArray(list) ? list : [];
        setRows((prev) => (replace ? items : [...prev, ...items]));
        setHasMore(Boolean(data?.next_page_url) || (data?.current_page ?? 1) < (data?.last_page ?? 1));
        setPage(nextPage);
      } catch {
        if (replace) setRows([]);
        notify.error("finance.historyLoadError");
      } finally {
        setIsLoading(false);
      }
    },
    [historyUrl, notify]
  );

  useEffect(() => {
    load(1, true);
  }, [load, reloadKey]);

  return (
    <div className="space-y-3">
      {isAdmin ? (
        <select
          value={selectedBranchId}
          onChange={(event) => onBranchChange?.(event.target.value)}
          className="h-10 w-full max-w-xs rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink"
        >
          <option value={ALL_BRANCHES_VALUE}>{t("inventory.allBranches")}</option>
          {branches.map((branch) => (
            <option key={branch.id} value={String(branch.id)}>
              {branch.name}
            </option>
          ))}
        </select>
      ) : null}

      {!historyUrl ? (
        <Card className="rounded-3xl border border-white/80 bg-white/70">
          <CardContent className="p-8 pt-8 text-center text-[12px] font-black text-ink/35">
            {t("expenses.form.selectBranch")}
          </CardContent>
        </Card>
      ) : isLoading && rows.length === 0 ? (
        Array.from({ length: 4 }).map((_, index) => (
          <div key={index} className="h-16 animate-pulse rounded-xl bg-parchment/20" />
        ))
      ) : rows.length === 0 ? (
        <Card className="rounded-3xl border border-white/80 bg-white/70">
          <CardContent className="flex flex-col items-center gap-2 p-10 pt-10 text-[12px] font-black text-ink/30">
            <History className="h-5 w-5 text-ink/20" />
            {t("finance.historyEmpty")}
          </CardContent>
        </Card>
      ) : (
        rows.map((row) => (
          <div
            key={row.id}
            className="flex flex-col gap-3 rounded-2xl border border-white/80 bg-white/75 px-3.5 py-3 sm:flex-row sm:items-center"
          >
            <div className="flex min-w-0 flex-1 items-start gap-2.5">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-primary/10 bg-primary/10">
                <FileText className="h-3.5 w-3.5 text-primary" />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="truncate font-vazirmatn text-[12px] font-black text-ink">
                    {row.settlement_number}
                  </span>
                  <span className="font-mono text-[9px] text-ink/30">{row.payment_method}</span>
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-2.5 text-[9px] text-ink/35">
                  <span className="flex items-center gap-0.5">
                    <Building2 className="h-2.5 w-2.5" />
                    {row.supplier?.name || "—"}
                    {row.branch?.name ? ` · ${row.branch.name}` : ""}
                  </span>
                  <span className="flex items-center gap-0.5">
                    <CalendarDays className="h-2.5 w-2.5" />
                    {dayPart(row.period_start)} — {dayPart(row.period_end)}
                  </span>
                </div>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <Link
                href={`/dashboard/consignment/settle/invoice?id=${row.id}`}
                onClick={() => cacheSettlementInvoiceRow(row)}
                className={cn(
                  "flex h-8 items-center gap-1.5 rounded-lg border border-ink/10 bg-white px-2.5 text-[10px] font-black text-ink/55 hover:border-primary/20 hover:text-primary"
                )}
              >
                <Palette className="h-3 w-3" />
                {t("consignment.settle.invoice.design")}
              </Link>
              <button
                type="button"
                onClick={() => {
                  printSettlement({
                    supplierName: row.supplier?.name || "—",
                    fromDate: dayPart(row.period_start),
                    toDate: dayPart(row.period_end),
                    items: [],
                    formatNumber,
                    currencySymbol:
                      row.currency === "dinar"
                        ? t("common.currency.dinarSymbol")
                        : t("common.currency.tomanSymbol"),
                    labels: buildSettlementPrintLabels(t),
                    dir: "rtl",
                    lang: language === "ar" ? "ar" : "fa",
                    variant: "invoice",
                    settledAmount: Number(row.amount || 0),
                    docNumber: row.settlement_number || undefined,
                    issueDate: dayPart(row.paid_at || row.created_at),
                    settlementId: row.id,
                  }).catch(() => notify.error("toast.invoicePrintError"));
                }}
                className="h-8 rounded-lg border border-ink/10 bg-white px-2.5 text-[10px] font-black text-ink/55 hover:border-primary/20 hover:text-primary"
              >
                {t("finance.settlement.printInvoice")}
              </button>
              <div className="min-w-[4.5rem] text-end">
                <p className="font-vazirmatn text-[14px] font-black leading-none tabular-nums text-primary">
                  {formatNumber(Number(row.amount || 0))}
                </p>
                <p className="mt-0.5 text-[8px] text-ink/30">
                  {row.currency === "dinar"
                    ? t("common.currency.dinarSymbol")
                    : t("common.currency.tomanSymbol")}
                </p>
              </div>
            </div>
          </div>
        ))
      )}

      {hasMore ? (
        <button
          type="button"
          disabled={isLoading}
          onClick={() => load(page + 1, false)}
          className="h-10 w-full rounded-xl border border-ink/10 text-[11px] font-black text-ink/50 disabled:opacity-40"
        >
          {t("consignment.loadMore")}
        </button>
      ) : null}
    </div>
  );
}
