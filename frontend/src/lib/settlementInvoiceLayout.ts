import type { PrintSettlementLabels } from "@/lib/printSettlement";

export const INVOICE_A5_WIDTH_PX = 559;
export const INVOICE_A5_HEIGHT_PX = 794;
export const PLATFORM_FONT_FAMILY =
  'var(--font-ibm-plex-arabic-face), "IBM Plex Sans Arabic", "Segoe UI", Tahoma, system-ui, sans-serif';

const STORAGE_KEY = "almanahel.settlementInvoice.design.v4";
const TEXTS_PREFIX = "almanahel.settlementInvoice.texts.v1.";
const SNAPSHOT_PREFIX = "almanahel.settlementInvoice.row.";

export type InvoiceFieldId =
  | "bismillah"
  | "date"
  | "number"
  | "attachment"
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

export const OFFICIAL_INVOICE_TEMPLATES = {
  fa: "/manahel-pr-invo.jpg",
  ar: "/manahel-ar-invo.jpg",
} as const;

export type InvoiceLetterheadLang = "fa" | "ar";

export function officialInvoiceTemplateUrl(lang: string): string {
  return lang === "ar" ? OFFICIAL_INVOICE_TEMPLATES.ar : OFFICIAL_INVOICE_TEMPLATES.fa;
}

export function invoiceLetterheadLang(
  url: string | null | undefined,
  fallback: string = "fa"
): InvoiceLetterheadLang {
  if (url === OFFICIAL_INVOICE_TEMPLATES.ar) return "ar";
  if (url === OFFICIAL_INVOICE_TEMPLATES.fa) return "fa";
  return fallback === "ar" ? "ar" : "fa";
}

export function isCustomInvoiceTemplate(url: string | null | undefined): boolean {
  if (!url) return false;
  return url !== OFFICIAL_INVOICE_TEMPLATES.fa && url !== OFFICIAL_INVOICE_TEMPLATES.ar;
}

export function resolveInvoiceTemplate(url: string | null | undefined, lang: string): string {
  if (isCustomInvoiceTemplate(url)) return String(url);
  if (url === OFFICIAL_INVOICE_TEMPLATES.fa || url === OFFICIAL_INVOICE_TEMPLATES.ar) return url;
  return officialInvoiceTemplateUrl(lang);
}

export const INVOICE_FIELD_ORDER: InvoiceFieldId[] = [
  "bismillah",
  "date",
  "number",
  "attachment",
  "recipient",
  "greeting",
  "body",
  "closing",
  "signIssuer",
  "signReceiver",
  "contact",
];

export const HEADER_META_FIELD_IDS: InvoiceFieldId[] = ["date", "number", "attachment"];
export const NUDGE_STEP = 0.15;
export const NUDGE_STEP_LARGE = 1;

export function defaultInvoiceDesign(lang: string = "fa"): InvoiceDesign {
  const metaH = 2.7;
  const metaX = 2.6;
  const metaW = 24.6;
  const header = lang === "ar"
    ? {
        date: { x: metaX, y: 8.63 - metaH / 2, w: metaW, h: metaH },
        number: { x: metaX, y: 10.9 - metaH / 2, w: metaW, h: metaH },
        attachment: { x: 8, y: 18.4, w: 50, h: 3.2 },
      }
    : {
        date: { x: metaX, y: 8.75 - metaH / 2, w: metaW, h: metaH },
        number: { x: metaX, y: 10.78 - metaH / 2, w: metaW, h: metaH },
        attachment: { x: metaX, y: 12.93 - metaH / 2, w: metaW, h: metaH },
      };

  return {
    version: 1,
    templateDataUrl: officialInvoiceTemplateUrl(lang),
    fields: [
      { id: "bismillah", x: 18, y: 21, w: 64, h: 5, fontSize: 14, align: "center", bold: true },
      { id: "date", ...header.date, fontSize: 11, align: "end" },
      { id: "number", ...header.number, fontSize: 11, align: "end" },
      { id: "attachment", ...header.attachment, fontSize: 10, align: "end" },
      { id: "recipient", x: 8, y: 28, w: 84, h: 6, fontSize: 13, align: "start", bold: true },
      { id: "greeting", x: 8, y: 35, w: 84, h: 5, fontSize: 12, align: "start" },
      { id: "body", x: 8, y: 42, w: 84, h: 18, fontSize: 12, align: "justify" },
      { id: "closing", x: 8, y: 62, w: 84, h: 6, fontSize: 12, align: "start" },
      { id: "signIssuer", x: 52, y: 72, w: 38, h: 10, fontSize: 11, align: "center", bold: true },
      { id: "signReceiver", x: 8, y: 72, w: 38, h: 10, fontSize: 11, align: "center", bold: true },
      { id: "contact", x: 8, y: 94, w: 84, h: 4, fontSize: 8, align: "center" },
    ],
  };
}

export function applyOfficialLetterhead(design: InvoiceDesign, lang: InvoiceLetterheadLang): InvoiceDesign {
  const next = defaultInvoiceDesign(lang);
  const headerIds = new Set<InvoiceFieldId>(HEADER_META_FIELD_IDS);
  const previous = new Map(design.fields.map((field) => [field.id, field]));
  return {
    ...design,
    templateDataUrl: next.templateDataUrl,
    fields: next.fields.map((base) => {
      if (headerIds.has(base.id)) return base;
      return previous.get(base.id) ?? base;
    }),
  };
}

function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

export function nudgeFieldPosition(
  field: Pick<InvoiceFieldLayout, "x" | "y">,
  key: "ArrowUp" | "ArrowDown" | "ArrowLeft" | "ArrowRight",
  large = false
): { x: number; y: number } {
  const step = large ? NUDGE_STEP_LARGE : NUDGE_STEP;
  let x = field.x;
  let y = field.y;
  if (key === "ArrowLeft") x -= step;
  if (key === "ArrowRight") x += step;
  if (key === "ArrowUp") y -= step;
  if (key === "ArrowDown") y += step;
  return { x: clamp(x, 0, 92), y: clamp(y, 0, 94) };
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
        w: clamp(Number(next.w ?? base.w), 4, 100),
        h: clamp(Number(next.h ?? base.h), 1.5, 80),
        fontSize: clamp(Number(next.fontSize ?? base.fontSize), 8, 28),
        align: ["start", "center", "end", "justify"].includes(String(next.align))
          ? (next.align as InvoiceFieldAlign)
          : base.align,
        bold: Boolean(next.bold ?? base.bold),
      };
    }),
  };
}

export function loadInvoiceDesign(lang: string = "fa"): InvoiceDesign {
  const fallback = defaultInvoiceDesign(lang);
  if (typeof window === "undefined") return fallback;
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const design = raw ? normalizeInvoiceDesign(JSON.parse(raw)) : fallback;
    return { ...design, templateDataUrl: resolveInvoiceTemplate(design.templateDataUrl, lang) };
  } catch {
    return fallback;
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
    attachment: "",
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
  overrides?: Partial<InvoiceFieldTexts> | null,
  facts: string[] = []
): InvoiceFieldTexts {
  const next = { ...base };
  if (!overrides) return next;
  for (const id of INVOICE_FIELD_ORDER) {
    if (typeof overrides[id] !== "string") continue;
    if (facts.length && !preservesProtectedFacts(overrides[id] as string, base[id], facts)) continue;
    next[id] = overrides[id] as string;
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

export function invoiceProtectedFacts(
  source: SettlementInvoiceSource,
  formatNumber: (n: number) => string
): string[] {
  const amount = formatNumber(Number(source.amount || 0));
  const raw = [
    dayPart(source.paid_at || source.created_at),
    dayPart(source.period_start),
    dayPart(source.period_end),
    amount,
  ];
  const seen = new Set<string>();
  const facts: string[] = [];
  for (const item of raw) {
    if (!item || item === "—") continue;
    if (seen.has(item)) continue;
    seen.add(item);
    facts.push(item);
  }
  facts.sort((a, b) => b.length - a.length);
  return facts;
}

export function preservesProtectedFacts(text: string, reference: string, facts: string[]): boolean {
  return facts.every((fact) => !reference.includes(fact) || text.includes(fact));
}

export function keepProtectedFacts(next: string, prev: string, facts: string[]): string {
  return preservesProtectedFacts(next, prev, facts) ? next : prev;
}

export function invoiceFieldTexts(opts: {
  source: SettlementInvoiceSource;
  labels: PrintSettlementLabels;
  formatNumber: (n: number) => string;
  currencySymbol: string;
  lang?: string;
}): InvoiceFieldTexts {
  const { source, labels, formatNumber, currencySymbol, lang = "fa" } = opts;
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
    date: issued,
    number: docNo,
    attachment: lang === "ar" ? "" : labels.attachmentValue,
    recipient: fill(labels.recipientHonorific, vars),
    greeting: labels.greeting,
    body: fill(labels.invoiceBody, vars),
    closing: labels.invoiceClosing,
    signIssuer: `${labels.signatureIssuer}\n${labels.brand}`,
    signReceiver: `${labels.signatureReceiver}\n${supplierName}`,
    contact: "",
  };
}

export function buildDesignedInvoiceHtml(opts: {
  design: InvoiceDesign;
  texts: InvoiceFieldTexts;
  title: string;
  lang: "fa" | "ar";
}): string {
  const { design, texts, title, lang } = opts;
  const letterhead = design.templateDataUrl
    ? `<img class="letterhead" src="${esc(design.templateDataUrl)}" alt="" />`
    : "";
  const nodes = design.fields
    .map((field) => {
      const numericMeta = field.id === "date" || field.id === "number";
      const align = numericMeta
        ? "left"
        : field.align === "start"
          ? "right"
          : field.align === "end"
            ? "left"
            : field.align;
      const extra = numericMeta
        ? "display:flex;align-items:center;justify-content:flex-start;line-height:1.1;direction:ltr;"
        : "line-height:1.55;direction:rtl;";
      return `<div class="field" style="left:${field.x}%;top:${field.y}%;width:${field.w}%;height:${field.h}%;font-size:${field.fontSize}px;${extra}text-align:${align};font-weight:${field.bold ? 700 : 400};">${esc(texts[field.id])}</div>`;
    })
    .join("");

  return `<!DOCTYPE html>
<html lang="${lang}" dir="rtl">
<head>
  <meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: #fff; }
    .sheet {
      position: relative;
      width: ${INVOICE_A5_WIDTH_PX}px;
      height: ${INVOICE_A5_HEIGHT_PX}px;
      overflow: hidden;
      font-family: ${PLATFORM_FONT_FAMILY};
      color: #1a1a1a;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .letterhead {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: fill;
      z-index: 0;
    }
    .field {
      position: absolute;
      z-index: 1;
      overflow: hidden;
      white-space: pre-wrap;
      unicode-bidi: isolate;
      background: transparent;
      border: none;
      box-shadow: none;
      letter-spacing: normal;
      word-spacing: normal;
      font-kerning: normal;
      font-variant-ligatures: common-ligatures discretionary-ligatures;
    }
    @page { size: A5 portrait; margin: 0; }
    @media print {
      html, body { width: 148mm; height: 210mm; margin: 0; }
      .sheet { width: 148mm; height: 210mm; }
    }
  </style>
</head>
<body>
  <div class="sheet">${letterhead}${nodes}</div>
</body>
</html>`;
}

export function wrapTextLines(
  text: string,
  maxWidth: number,
  measure: (value: string) => number
): string[] {
  const lines: string[] = [];
  for (const paragraph of String(text ?? "").split("\n")) {
    if (paragraph === "") {
      lines.push("");
      continue;
    }
    const words = paragraph.split(/\s+/).filter((word) => word.length > 0);
    let current = "";
    for (const word of words) {
      const next = current ? `${current} ${word}` : word;
      if (current && measure(next) > maxWidth) {
        lines.push(current);
        current = word;
      } else {
        current = next;
      }
    }
    if (current) lines.push(current);
  }
  return lines;
}

export function invoicePdfFileName(title: string): string {
  const base =
    String(title || "invoice")
      .replace(/[^\u0600-\u06FFa-zA-Z0-9]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 80) || "invoice";
  return `${base}.pdf`;
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
