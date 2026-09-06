"use client";

import React, { useMemo, useRef, useState } from "react";
import { ArrowRight, Download, ImagePlus, RotateCcw, Save, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Card, CardContent } from "@/components/ui/Card";
import { Link } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { printDesignedInvoice, buildSettlementPrintLabels } from "@/lib/printSettlement";
import {
  INVOICE_A5_HEIGHT_PX,
  INVOICE_A5_WIDTH_PX,
  INVOICE_FIELD_ORDER,
  compressTemplateImage,
  defaultInvoiceDesign,
  invoiceFieldTexts,
  invoiceProtectedFacts,
  keepProtectedFacts,
  loadInvoiceTexts,
  mergeInvoiceTexts,
  saveInvoiceDesign,
  saveInvoiceTexts,
  type InvoiceDesign,
  type InvoiceFieldId,
  type InvoiceFieldLayout,
  type InvoiceFieldTexts,
  type SettlementInvoiceSource,
} from "@/lib/settlementInvoiceLayout";

const ALIGN_OPTIONS: InvoiceFieldLayout["align"][] = ["start", "center", "end", "justify"];

export function SettlementInvoiceDesigner({
  source,
  initialDesign,
}: {
  source: SettlementInvoiceSource;
  initialDesign: InvoiceDesign;
}) {
  const { t, formatNumber, language, isArabic } = useTranslation();
  const notify = useNotify();
  const labels = useMemo(() => buildSettlementPrintLabels(t), [t]);
  const symbol =
    source.currency === "dinar" ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");
  const generatedTexts = useMemo(
    () => invoiceFieldTexts({ source, labels, formatNumber, currencySymbol: symbol }),
    [source, labels, formatNumber, symbol]
  );
  const facts = useMemo(() => invoiceProtectedFacts(source, formatNumber), [source, formatNumber]);
  const [design, setDesign] = useState<InvoiceDesign>(initialDesign);
  const [texts, setTexts] = useState<InvoiceFieldTexts>(() =>
    mergeInvoiceTexts(generatedTexts, loadInvoiceTexts(source.id), facts)
  );
  const [selectedId, setSelectedId] = useState<InvoiceFieldId>("body");
  const [isSavingPdf, setIsSavingPdf] = useState(false);
  const [dirty, setDirty] = useState(false);
  const canvasRef = useRef<HTMLDivElement | null>(null);
  const designRef = useRef(design);
  const textsRef = useRef(texts);
  const dragRef = useRef<{ id: InvoiceFieldId; ox: number; oy: number } | null>(null);
  const resizeRef = useRef<{ id: InvoiceFieldId; startW: number; startH: number; sx: number; sy: number } | null>(null);
  designRef.current = design;
  textsRef.current = texts;

  const selected = design.fields.find((field) => field.id === selectedId) ?? design.fields[0];

  const persist = (next: InvoiceDesign, store = false) => {
    designRef.current = next;
    setDesign(next);
    setDirty(true);
    if (store) {
      saveInvoiceDesign(next);
      saveInvoiceTexts(source.id, textsRef.current);
      setDirty(false);
    }
  };

  const patchField = (id: InvoiceFieldId, patch: Partial<InvoiceFieldLayout>, store = false) => {
    persist({
      ...designRef.current,
      fields: designRef.current.fields.map((field) => (field.id === id ? { ...field, ...patch } : field)),
    }, store);
  };

  const patchText = (id: InvoiceFieldId, value: string) => {
    const prev = textsRef.current[id] ?? "";
    const nextValue = keepProtectedFacts(value, prev, facts);
    if (nextValue === prev) return;
    const next = { ...textsRef.current, [id]: nextValue };
    textsRef.current = next;
    setTexts(next);
    setDirty(true);
  };

  const saveAll = (silent = false) => {
    saveInvoiceDesign(designRef.current);
    saveInvoiceTexts(source.id, textsRef.current);
    setDirty(false);
    if (!silent) notify.success("consignment.settle.invoice.saved");
  };

  const percentPoint = (event: React.PointerEvent) => {
    const canvas = canvasRef.current;
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    return {
      x: ((event.clientX - rect.left) / rect.width) * 100,
      y: ((event.clientY - rect.top) / rect.height) * 100,
      rect,
    };
  };

  const onMovePointerDown = (event: React.PointerEvent, id: InvoiceFieldId) => {
    event.preventDefault();
    event.stopPropagation();
    setSelectedId(id);
    const point = percentPoint(event);
    const field = designRef.current.fields.find((item) => item.id === id);
    if (!point || !field) return;
    dragRef.current = { id, ox: point.x - field.x, oy: point.y - field.y };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
  };

  const onResizePointerDown = (event: React.PointerEvent, id: InvoiceFieldId) => {
    event.preventDefault();
    event.stopPropagation();
    setSelectedId(id);
    const field = designRef.current.fields.find((item) => item.id === id);
    if (!field) return;
    resizeRef.current = {
      id,
      startW: field.w,
      startH: field.h,
      sx: event.clientX,
      sy: event.clientY,
    };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
  };

  const onPointerMove = (event: React.PointerEvent) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    if (dragRef.current) {
      const point = percentPoint(event);
      if (!point) return;
      patchField(dragRef.current.id, {
        x: Math.min(92, Math.max(0, point.x - dragRef.current.ox)),
        y: Math.min(94, Math.max(0, point.y - dragRef.current.oy)),
      });
      return;
    }
    if (resizeRef.current) {
      const dw = ((event.clientX - resizeRef.current.sx) / rect.width) * 100;
      const dh = ((event.clientY - resizeRef.current.sy) / rect.height) * 100;
      patchField(resizeRef.current.id, {
        w: Math.min(100, Math.max(8, resizeRef.current.startW + dw)),
        h: Math.min(80, Math.max(4, resizeRef.current.startH + dh)),
      });
    }
  };

  const onPointerUp = () => {
    if (dragRef.current || resizeRef.current) {
      saveInvoiceDesign(designRef.current);
      saveInvoiceTexts(source.id, textsRef.current);
      setDirty(false);
    }
    dragRef.current = null;
    resizeRef.current = null;
  };

  const onUpload = async (file: File | undefined) => {
    if (!file) return;
    try {
      const templateDataUrl = await compressTemplateImage(file);
      persist({ ...designRef.current, templateDataUrl }, true);
      notify.success("consignment.settle.invoice.templateSaved");
    } catch {
      notify.error("consignment.settle.invoice.templateError");
    }
  };

  const download = async () => {
    setIsSavingPdf(true);
    try {
      saveAll(true);
      await printDesignedInvoice({
        source,
        labels,
        formatNumber,
        currencySymbol: symbol,
        lang: language === "ar" ? "ar" : "fa",
        design: designRef.current,
        texts: textsRef.current,
      });
    } catch {
      notify.error("toast.invoicePrintError");
    } finally {
      setIsSavingPdf(false);
    }
  };

  return (
    <div className="space-y-4 pb-10">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Link href="/dashboard/consignment/settle?tab=history">
            <Button variant="ghost" size="sm" className="h-9 w-9 rounded-xl border border-ink/5 p-0">
              <ArrowRight className={cn("h-4 w-4", isArabic && "rotate-180")} />
            </Button>
          </Link>
          <div>
            <h1 className="text-xl font-black font-vazirmatn text-ink">
              {t("consignment.settle.invoice.title")}
            </h1>
            <p className="mt-0.5 text-[10px] font-bold text-ink/35">
              {source.settlement_number || "—"} · {source.supplier?.name || "—"}
              {dirty ? ` · ${t("consignment.settle.invoice.unsaved")}` : ""}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-10 rounded-xl px-4 text-[11px] font-black"
            onClick={saveAll}
            disabled={!dirty}
          >
            <Save className="ms-1.5 h-3.5 w-3.5" />
            {t("consignment.settle.invoice.save")}
          </Button>
          <Button
            variant="primary"
            size="sm"
            className="h-10 rounded-xl px-4 text-[11px] font-black"
            onClick={download}
            disabled={isSavingPdf}
          >
            <Download className="ms-1.5 h-3.5 w-3.5" />
            {t("finance.settlement.printInvoice")}
          </Button>
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <Card className="overflow-hidden rounded-3xl border border-white/80 bg-white/75">
          <CardContent className="flex justify-center overflow-auto p-4 pt-4">
            <div
              ref={canvasRef}
              className="relative shrink-0 overflow-hidden rounded-md border border-ink/10 bg-white shadow-sm"
              style={{
                width: INVOICE_A5_WIDTH_PX,
                height: INVOICE_A5_HEIGHT_PX,
                backgroundImage: design.templateDataUrl ? `url(${design.templateDataUrl})` : undefined,
                backgroundSize: "100% 100%",
              }}
              onPointerMove={onPointerMove}
              onPointerUp={onPointerUp}
              onPointerCancel={onPointerUp}
            >
              {design.fields.map((field) => (
                <div
                  key={field.id}
                  className={cn(
                    "absolute",
                    selectedId === field.id ? "z-10 ring-2 ring-primary/60" : "hover:ring-1 hover:ring-ink/15"
                  )}
                  style={{
                    left: `${field.x}%`,
                    top: `${field.y}%`,
                    width: `${field.w}%`,
                    height: `${field.h}%`,
                  }}
                  onPointerDown={() => setSelectedId(field.id)}
                >
                  <button
                    type="button"
                    onPointerDown={(event) => onMovePointerDown(event, field.id)}
                    className="absolute inset-x-0 top-0 z-10 h-3 cursor-grab bg-primary/20 active:cursor-grabbing"
                    aria-label={t("consignment.settle.invoice.move")}
                  />
                  <textarea
                    value={texts[field.id]}
                    onChange={(event) => patchText(field.id, event.target.value)}
                    onBlur={() => {
                      saveInvoiceDesign(designRef.current);
                      saveInvoiceTexts(source.id, textsRef.current);
                      setDirty(false);
                    }}
                    className="h-full w-full resize-none overflow-hidden bg-transparent px-1 pb-3 pt-3 outline-none"
                    style={{
                      fontSize: `${field.fontSize}px`,
                      lineHeight: 1.55,
                      textAlign:
                        field.align === "start" ? "right" : field.align === "end" ? "left" : field.align,
                      fontWeight: field.bold ? 700 : 400,
                      fontFamily: '"Traditional Arabic", "Arabic Typesetting", Tahoma, serif',
                    }}
                  />
                  <button
                    type="button"
                    onPointerDown={(event) => onResizePointerDown(event, field.id)}
                    className="absolute bottom-0 left-0 z-10 h-3.5 w-3.5 cursor-nwse-resize rounded-br-sm bg-primary"
                    aria-label={t("consignment.settle.invoice.resize")}
                  />
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        <div className="space-y-3">
          <Card className="rounded-3xl border border-white/80 bg-white/75">
            <CardContent className="space-y-3 p-4 pt-4">
              <p className="text-[11px] font-black text-ink">{t("consignment.settle.invoice.template")}</p>
              <label className="flex h-10 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-ink/15 bg-parchment/40 text-[11px] font-black text-ink/55">
                <ImagePlus className="h-4 w-4" />
                {t("consignment.settle.invoice.uploadTemplate")}
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  className="hidden"
                  onChange={(event) => onUpload(event.target.files?.[0])}
                />
              </label>
              {design.templateDataUrl ? (
                <button
                  type="button"
                  onClick={() => persist({ ...designRef.current, templateDataUrl: null }, true)}
                  className="flex h-9 w-full items-center justify-center gap-2 rounded-xl border border-ink/10 text-[11px] font-black text-ink/50"
                >
                  <Trash2 className="h-3.5 w-3.5" />
                  {t("consignment.settle.invoice.removeTemplate")}
                </button>
              ) : null}
              <button
                type="button"
                onClick={() => persist({ ...defaultInvoiceDesign(), templateDataUrl: designRef.current.templateDataUrl }, true)}
                className="flex h-9 w-full items-center justify-center gap-2 rounded-xl border border-ink/10 text-[11px] font-black text-ink/50"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                {t("consignment.settle.invoice.resetLayout")}
              </button>
            </CardContent>
          </Card>

          <Card className="rounded-3xl border border-white/80 bg-white/75">
            <CardContent className="space-y-3 p-4 pt-4">
              <p className="text-[11px] font-black text-ink">{t("consignment.settle.invoice.selectedField")}</p>
              <select
                value={selected.id}
                onChange={(event) => setSelectedId(event.target.value as InvoiceFieldId)}
                className="h-10 w-full rounded-xl border border-ink/10 bg-white px-3 text-[12px] font-bold text-ink"
              >
                {INVOICE_FIELD_ORDER.map((id) => (
                  <option key={id} value={id}>
                    {t(`consignment.settle.invoice.fields.${id}`)}
                  </option>
                ))}
              </select>
              <label className="block text-[10px] font-bold text-ink/40">
                {t("consignment.settle.invoice.editText")}
                <textarea
                  value={texts[selected.id]}
                  onChange={(event) => patchText(selected.id, event.target.value)}
                  onBlur={() => {
                    saveInvoiceDesign(designRef.current);
                    saveInvoiceTexts(source.id, textsRef.current);
                    setDirty(false);
                  }}
                  rows={5}
                  className="mt-1 w-full rounded-xl border border-ink/10 bg-white px-3 py-2 text-[12px] font-bold leading-6 text-ink outline-none focus:border-primary/30"
                />
              </label>
              <p className="text-[10px] font-bold leading-5 text-ink/40">
                {t("consignment.settle.invoice.lockedHint")}
              </p>
              <button
                type="button"
                onClick={() => {
                  patchText(selected.id, generatedTexts[selected.id]);
                  saveInvoiceTexts(source.id, textsRef.current);
                  setDirty(false);
                }}
                className="h-8 w-full rounded-lg border border-ink/10 text-[10px] font-black text-ink/45"
              >
                {t("consignment.settle.invoice.resetText")}
              </button>
              <label className="block text-[10px] font-bold text-ink/40">
                {t("consignment.settle.invoice.fontSize")}: {selected.fontSize}
                <input
                  type="range"
                  min={8}
                  max={28}
                  value={selected.fontSize}
                  onChange={(event) => patchField(selected.id, { fontSize: Number(event.target.value) })}
                  onPointerUp={() => {
                    saveInvoiceDesign(designRef.current);
                    setDirty(false);
                  }}
                  className="mt-1 w-full"
                />
              </label>
              <label className="block text-[10px] font-bold text-ink/40">
                {t("consignment.settle.invoice.width")}: {Math.round(selected.w)}%
                <input
                  type="range"
                  min={8}
                  max={100}
                  value={selected.w}
                  onChange={(event) => patchField(selected.id, { w: Number(event.target.value) })}
                  onPointerUp={() => {
                    saveInvoiceDesign(designRef.current);
                    setDirty(false);
                  }}
                  className="mt-1 w-full"
                />
              </label>
              <label className="block text-[10px] font-bold text-ink/40">
                {t("consignment.settle.invoice.height")}: {Math.round(selected.h)}%
                <input
                  type="range"
                  min={4}
                  max={80}
                  value={selected.h}
                  onChange={(event) => patchField(selected.id, { h: Number(event.target.value) })}
                  onPointerUp={() => {
                    saveInvoiceDesign(designRef.current);
                    setDirty(false);
                  }}
                  className="mt-1 w-full"
                />
              </label>
              <div className="flex flex-wrap gap-1.5">
                {ALIGN_OPTIONS.map((align) => (
                  <button
                    key={align}
                    type="button"
                    onClick={() => {
                      patchField(selected.id, { align }, true);
                    }}
                    className={cn(
                      "h-8 rounded-lg px-2.5 text-[10px] font-black",
                      selected.align === align
                        ? "bg-primary text-white"
                        : "border border-ink/10 bg-white text-ink/50"
                    )}
                  >
                    {t(`consignment.settle.invoice.align.${align}`)}
                  </button>
                ))}
              </div>
              <label className="flex items-center gap-2 text-[11px] font-bold text-ink/55">
                <input
                  type="checkbox"
                  checked={Boolean(selected.bold)}
                  onChange={(event) => patchField(selected.id, { bold: event.target.checked }, true)}
                />
                {t("consignment.settle.invoice.bold")}
              </label>
              <p className="text-[10px] font-bold leading-5 text-ink/35">
                {t("consignment.settle.invoice.dragHint")}
              </p>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
