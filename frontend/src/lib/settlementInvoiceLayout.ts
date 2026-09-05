import type { PrintSettlementLabels } from "@/lib/printSettlement";

export const INVOICE_A5_WIDTH_PX = 559;
export const INVOICE_A5_HEIGHT_PX = 794;

const STORAGE_KEY = "almanahel.settlementInvoice.design.v1";
const TEXTS_PREFIX = "almanahel.settlementInvoice.texts.v1.";
const SNAPSHOT_PREFIX = "almanahel.settlementInvoice.row.";

export type InvoiceFieldId =
  | "bismillah"
  | "date"
  | "number"
  | "recipient"
  | "greeting"
  | "body"
  | "closing"
  | "signIssuer"
  | "signReceiver"
  | "contact";

export type InvoiceFieldAlign = "start" | "center" | "end" | "justify";

export type InvoiceFieldLayout = {
  id: InvoiceFieldId;
  x: number;
  y: number;
  w: number;
  h: number;
  fontSize: number;
  align: InvoiceFieldAlign;
  bold?: boolean;
};

export type InvoiceFieldTexts = Record<InvoiceFieldId, string>;

export type InvoiceDesign = {
  version: 1;
  fields: InvoiceFieldLayout[];
  templateDataUrl: string | null;
};

export type SettlementInvoiceSource = {
  id?: number;
  settlement_number?: string | null;
  amount?: number | string | null;
  currency?: string | null;
  period_start?: string | null;
  period_end?: string | null;
  paid_at?: string | null;
  created_at?: string | null;
  supplier?: { name?: string | null } | null;
  branch?: { name?: string | null } | null;
};

export const INVOICE_FIELD_ORDER: InvoiceFieldId[] = [
  "bismillah",
  "date",
  "number",
  "recipient",
  "greeting",
  "body",
  "closing",
  "signIssuer",
  "signReceiver",
  "contact",
];

export function defaultInvoiceDesign(): InvoiceDesign {
  return {
    version: 1,
    templateDataUrl: null,
    fields: [
      { id: "bismillah", x: 12, y: 7, w: 76, h: 6, fontSize: 16, align: "center", bold: true },
      { id: "date", x: 6, y: 16, w: 36, h: 5, fontSize: 11, align: "start" },
      { id: "number", x: 6, y: 21, w: 36, h: 5, fontSize: 11, align: "start" },
      { id: "recipient", x: 8, y: 30, w: 84, h: 6, fontSize: 13, align: "start", bold: true },
      { id: "greeting", x: 8, y: 36, w: 84, h: 5, fontSize: 12, align: "start" },
      { id: "body", x: 8, y: 43, w: 84, h: 16, fontSize: 12, align: "justify" },
      { id: "closing", x: 8, y: 62, w: 84, h: 6, fontSize: 12, align: "start" },
      { id: "signIssuer", x: 52, y: 72, w: 38, h: 10, fontSize: 11, align: "center", bold: true },
      { id: "signReceiver", x: 8, y: 72, w: 38, h: 10, fontSize: 11, align: "center", bold: true },
      { id: "contact", x: 8, y: 90, w: 84, h: 6, fontSize: 9, align: "center" },
    ],
  };
}

function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

export function normalizeInvoiceDesign(raw: unknown): InvoiceDesign {
  const fallback = defaultInvoiceDesign();
  if (!raw || typeof raw !== "object") return fallback;
  const data = raw as Partial<InvoiceDesign>;
  const byId = new Map(
    (Array.isArray(data.fields) ? data.fields : []).map((field) => [field.id, field])
  );
  return {
    version: 1,
    templateDataUrl: typeof data.templateDataUrl === "string" ? data.templateDataUrl : null,
    fields: fallback.fields.map((base) => {
      const next = byId.get(base.id);
      if (!next) return base;
      return {
        ...base,
        x: clamp(Number(next.x ?? base.x), 0, 92),
        y: clamp(Number(next.y ?? base.y), 0, 94),
        w: clamp(Number(next.w ?? base.w), 8, 100),
        h: clamp(Number(next.h ?? base.h), 4, 80),
        fontSize: clamp(Number(next.fontSize ?? base.fontSize), 8, 28),
        align: ["start", "center", "end", "justify"].includes(String(next.align))
          ? (next.align as InvoiceFieldAlign)
          : base.align,
        bold: Boolean(next.bold ?? base.bold),
      };
    }),
  };
}

export function loadInvoiceDesign(): InvoiceDesign {
  if (typeof window === "undefined") return defaultInvoiceDesign();
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    return raw ? normalizeInvoiceDesign(JSON.parse(raw)) : defaultInvoiceDesign();
  } catch {
    return defaultInvoiceDesign();
  }
}

export function saveInvoiceDesign(design: InvoiceDesign): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(normalizeInvoiceDesign(design)));
}

export function emptyInvoiceTexts(): InvoiceFieldTexts {
  return {
    bismillah: "",
    date: "",
    number: "",
    recipient: "",
    greeting: "",
    body: "",
    closing: "",
    signIssuer: "",
    signReceiver: "",
    contact: "",
  };
}

export function mergeInvoiceTexts(
  base: InvoiceFieldTexts,
  overrides?: Partial<InvoiceFieldTexts> | null
): InvoiceFieldTexts {
  const next = { ...base };
  if (!overrides) return next;
  for (const id of INVOICE_FIELD_ORDER) {
    if (typeof overrides[id] === "string") next[id] = overrides[id] as string;
  }
  return next;
}

export function loadInvoiceTexts(settlementId?: number | null): Partial<InvoiceFieldTexts> {
  if (typeof window === "undefined" || !settlementId) return {};
  try {
    const raw = window.localStorage.getItem(`${TEXTS_PREFIX}${settlementId}`);
    return raw ? (JSON.parse(raw) as Partial<InvoiceFieldTexts>) : {};
  } catch {
    return {};
  }
}

export function saveInvoiceTexts(settlementId: number | null | undefined, texts: InvoiceFieldTexts): void {
  if (typeof window === "undefined" || !settlementId) return;
  window.localStorage.setItem(`${TEXTS_PREFIX}${settlementId}`, JSON.stringify(texts));
}

export function cacheSettlementInvoiceRow(row: SettlementInvoiceSource): void {
  if (typeof window === "undefined" || row.id == null) return;
  window.sessionStorage.setItem(`${SNAPSHOT_PREFIX}${row.id}`, JSON.stringify(row));
}

export function readCachedSettlementInvoiceRow(id: number): SettlementInvoiceSource | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.sessionStorage.getItem(`${SNAPSHOT_PREFIX}${id}`);
    return raw ? (JSON.parse(raw) as SettlementInvoiceSource) : null;
  } catch {
    return null;
  }
}

function esc(value: unknown): string {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function fill(template: string, vars: Record<string, string>): string {
  return Object.entries(vars).reduce(
    (acc, [key, value]) => acc.replaceAll(`{{${key}}}`, value),
    template
  );
}

export function dayPart(value: string | null | undefined): string {
  return value ? String(value).slice(0, 10) : "";
}

export function invoiceFieldTexts(opts: {
  source: SettlementInvoiceSource;
  labels: PrintSettlementLabels;
  formatNumber: (n: number) => string;
  currencySymbol: string;
}): InvoiceFieldTexts {
  const { source, labels, formatNumber, currencySymbol } = opts;
  const supplierName = source.supplier?.name || "—";
  const fromDate = dayPart(source.period_start) || "—";
  const toDate = dayPart(source.period_end) || "—";
  const issued = dayPart(source.paid_at || source.created_at) || new Date().toISOString().slice(0, 10);
  const docNo = source.settlement_number || "—";
  const amount = formatNumber(Number(source.amount || 0));
  const vars = {
    supplier: supplierName,
    issuer: labels.brand,
    from: fromDate,
    to: toDate,
    amount,
    currency: currencySymbol,
  };

  return {
    bismillah: labels.bismillah,
    date: `${labels.invoiceDateLabel}: ${issued}`,
    number: `${labels.docNumber}: ${docNo}`,
    recipient: fill(labels.recipientHonorific, vars),
    greeting: labels.greeting,
    body: fill(labels.invoiceBody, vars),
    closing: labels.invoiceClosing,
    signIssuer: `${labels.signatureIssuer}\n${labels.brand}`,
    signReceiver: `${labels.signatureReceiver}\n${supplierName}`,
    contact: labels.invoiceContact,
  };
}

export function buildDesignedInvoiceHtml(opts: {
  design: InvoiceDesign;
  texts: InvoiceFieldTexts;
  title: string;
  lang: "fa" | "ar";
}): string {
  const { design, texts, title, lang } = opts;
  const bg = design.templateDataUrl
    ? `background-image:url(${JSON.stringify(design.templateDataUrl)});background-size:100% 100%;background-repeat:no-repeat;`
    : "background:#fff;";
  const nodes = design.fields
    .map((field) => {
      const align =
        field.align === "start" ? "right" : field.align === "end" ? "left" : field.align;
      return `<div style="position:absolute;left:${field.x}%;top:${field.y}%;width:${field.w}%;height:${field.h}%;overflow:hidden;font-size:${field.fontSize}px;line-height:1.55;text-align:${align};font-weight:${field.bold ? 700 : 400};white-space:pre-wrap;">${esc(texts[field.id])}</div>`;
    })
    .join("");

  return `<!DOCTYPE html>
<html lang="${lang}" dir="rtl">
<head>
  <meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #fff; }
    .sheet {
      position: relative;
      width: ${INVOICE_A5_WIDTH_PX}px;
      height: ${INVOICE_A5_HEIGHT_PX}px;
      overflow: hidden;
      ${bg}
      font-family: "Traditional Arabic", "Arabic Typesetting", Tahoma, serif;
      color: #1a1a1a;
    }
  </style>
</head>
<body>
  <div class="sheet">${nodes}</div>
</body>
</html>`;
}

export async function compressTemplateImage(file: File): Promise<string> {
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ""));
    reader.onerror = () => reject(new Error("READ_FAILED"));
    reader.readAsDataURL(file);
  });

  const image = await new Promise<HTMLImageElement>((resolve, reject) => {
    const el = new Image();
    el.onload = () => resolve(el);
    el.onerror = () => reject(new Error("IMAGE_FAILED"));
    el.src = dataUrl;
  });

  const maxW = 1200;
  const scale = image.width > maxW ? maxW / image.width : 1;
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(image.width * scale));
  canvas.height = Math.max(1, Math.round(image.height * scale));
  const ctx = canvas.getContext("2d");
  if (!ctx) return dataUrl;
  ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL("image/jpeg", 0.86);
}
