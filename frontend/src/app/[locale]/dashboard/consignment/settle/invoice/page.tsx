"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Card, CardContent } from "@/components/ui/Card";
import { SettlementInvoiceDesigner } from "@/components/finance/SettlementInvoiceDesigner";
import { useTranslation } from "@/hooks/useTranslation";
import { apiRequest } from "@/lib/api";
import {
  loadInvoiceDesign,
  readCachedSettlementInvoiceRow,
  type SettlementInvoiceSource,
} from "@/lib/settlementInvoiceLayout";

function InvoiceDesignerContent() {
  const searchParams = useSearchParams();
  const id = Number(searchParams.get("id"));
  const { t, language } = useTranslation();
  const [source, setSource] = useState<SettlementInvoiceSource | null>(
    Number.isFinite(id) ? readCachedSettlementInvoiceRow(id) : null
  );
  const [error, setError] = useState(false);
  const [ready, setReady] = useState(Boolean(source));
  usePageReady(ready);

  useEffect(() => {
    if (!Number.isFinite(id) || id <= 0) {
      setError(true);
      setReady(true);
      return;
    }
    const cached = readCachedSettlementInvoiceRow(id);
    if (cached) setSource(cached);
    let cancelled = false;
    apiRequest(`/consignments/settlements/${id}`)
      .then((data) => {
        if (!cancelled && data) setSource(data);
      })
      .catch(() => {
        if (!cancelled && !cached) setError(true);
      })
      .finally(() => {
        if (!cancelled) setReady(true);
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (!ready) {
    return <div className="h-64 animate-pulse rounded-3xl bg-parchment/20" />;
  }

  if (error || !source) {
    return (
      <Card className="rounded-3xl border border-white/80 bg-white/70">
        <CardContent className="p-10 pt-10 text-center text-[12px] font-black text-ink/35">
          {t("consignment.settle.invoice.notFound")}
        </CardContent>
      </Card>
    );
  }

  return <SettlementInvoiceDesigner source={source} initialDesign={loadInvoiceDesign(language)} />;
}

export default function SettlementInvoicePage() {
  return (
    <Suspense fallback={<div className="h-64 animate-pulse rounded-3xl bg-parchment/20" />}>
      <InvoiceDesignerContent />
    </Suspense>
  );
}
