/**
 * Print-friendly consignment settlement sheets via hidden iframe (no popup blocker).
 */

import {
  buildDesignedInvoiceHtml,
  invoiceFieldTexts,
  loadInvoiceDesign,
  loadInvoiceTexts,
  mergeInvoiceTexts,
  type InvoiceDesign,
  type InvoiceFieldTexts,
  type SettlementInvoiceSource,
} from "@/lib/settlementInvoiceLayout";

export type SettlementPrintVariant = "report" | "invoice";

export type PrintSettlementLabels = {
  brand: string;
  reportTitle: string;
  invoiceTitle: string;
  supplier: string;
  period: string;
  fromDate: string;
  toDate: string;
  book: string;
  qty: string;
  remainingQty: string;
  unitPrice: string;
  total: string;
  publisherShare: string;
  finalPayable: string;
  footer: string;
  docNumber: string;
  issueDate: string;
  invoiceDateLabel: string;
  issuer: string;
  receiver: string;
  signatureIssuer: string;
  signatureReceiver: string;
  invoiceNote: string;
  reportBadge: string;
  invoiceBadge: string;
  branch: string;
  bismillah: string;
  attachment: string;
  attachmentValue: string;
  recipientHonorific: string;
  greeting: string;
  invoiceBody: string;
  invoiceAttachNote: string;
  invoiceClosing: string;
  invoiceContact: string;
};

function esc(value: unknown): string {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

export type SettlementPrintItem = {
  title: string;
  qty: number;
  remainingQty?: number;
  price?: number;
  total: number;
  commission: number;
  branchName?: string | null;
};

function shareOf(item: SettlementPrintItem): number {
  return Number(item.total || 0) - Number(item.commission || 0);
}

function groupItems(items: SettlementPrintItem[]): [string, SettlementPrintItem[]][] {
  const groups = new Map<string, SettlementPrintItem[]>();
  for (const item of items) {
    const key = String(item.branchName || "").trim() || "__all__";
    const list = groups.get(key) ?? [];
    list.push(item);
    groups.set(key, list);
  }
  return Array.from(groups.entries());
}

function safeFilePart(value: string): string {
  return value.replace(/[\\/:*?"<>|]+/g, "-").replace(/\s+/g, " ").trim() || "report";
}

async function downloadPdfFromHtml(
  html: string,
  filename: string,
  opts?: { format?: "a4" | "a5" }
): Promise<void> {
  const format = opts?.format ?? "a4";
  const frameW = format === "a5" ? 559 : 794;
  const frameH = format === "a5" ? 794 : 1123;
  const iframe = document.createElement("iframe");
  iframe.setAttribute("aria-hidden", "true");
  iframe.style.cssText =
    `position:fixed;left:-12000px;top:0;width:${frameW}px;height:${frameH}px;border:0;opacity:0;pointer-events:none;`;
  document.body.appendChild(iframe);

  const doc = iframe.contentDocument || iframe.contentWindow?.document;
  if (!doc) {
    iframe.remove();
    throw new Error("PRINT_UNAVAILABLE");
  }

  doc.open();
  doc.write(html);
  doc.close();

  await new Promise<void>((resolve) => {
    const done = () => resolve();
    if (doc.readyState === "complete") {
      window.setTimeout(done, 80);
    } else {
      iframe.onload = () => window.setTimeout(done, 80);
    }
  });

  const target = (doc.querySelector(".sheet") as HTMLElement | null) ?? doc.body;
  const html2canvas = (await import("html2canvas")).default;
  const { jsPDF } = await import("jspdf");
  const canvas = await html2canvas(target, {
    scale: 2,
    useCORS: true,
    backgroundColor: "#ffffff",
    windowWidth: frameW,
  });

  const pdf = new jsPDF({ orientation: "portrait", unit: "mm", format });
  const pageW = pdf.internal.pageSize.getWidth();
  const pageH = pdf.internal.pageSize.getHeight();
  const margin = format === "a5" ? 8 : 10;
  const usableW = pageW - margin * 2;
  const usableH = pageH - margin * 2;
  const imgW = usableW;
  const imgH = (canvas.height * imgW) / canvas.width;
  const img = canvas.toDataURL("image/jpeg", 0.92);

  let heightLeft = imgH;
  let offset = 0;
  pdf.addImage(img, "JPEG", margin, margin, imgW, imgH);
  heightLeft -= usableH;
  while (heightLeft > 0) {
    offset += usableH;
    pdf.addPage();
    pdf.addImage(img, "JPEG", margin, margin - offset, imgW, imgH);
    heightLeft -= usableH;
  }

  pdf.save(filename);
  iframe.remove();
}

function reportTables(
  items: SettlementPrintItem[],
  labels: PrintSettlementLabels,
  formatNumber: (n: number) => string
): string {
  return groupItems(items)
    .map(([name, groupItems]) => {
      const rows = groupItems
        .map((item, idx) => `
        <tr>
          <td class="num">${idx + 1}</td>
          <td class="title">${esc(item.title)}</td>
          <td class="qty">${esc(formatNumber(Number(item.qty || 0)))}</td>
          <td class="qty">${esc(formatNumber(Number(item.remainingQty || 0)))}</td>
          <td class="money">${esc(formatNumber(Number(item.total || 0)))}</td>
          <td class="money">${esc(formatNumber(shareOf(item)))}</td>
        </tr>`)
        .join("");
      const heading = name !== "__all__" ? `<h3 class="branch">${esc(name)}</h3>` : "";
      return `${heading}
    <table class="grid-table">
      <colgroup>
        <col class="col-idx" />
        <col class="col-book" />
        <col class="col-qty" />
        <col class="col-qty" />
        <col class="col-money" />
        <col class="col-money" />
      </colgroup>
      <thead>
        <tr>
          <th class="num">#</th>
          <th>${esc(labels.book)}</th>
          <th class="qty">${esc(labels.qty)}</th>
          <th class="qty">${esc(labels.remainingQty)}</th>
          <th class="money">${esc(labels.total)}</th>
          <th class="money">${esc(labels.publisherShare)}</th>
        </tr>
      </thead>
      <tbody>${rows || `<tr><td colspan="6">—</td></tr>`}</tbody>
    </table>`;
    })
    .join("");
}

export async function printSettlement(
  opts: {
    supplierName: string;
    fromDate: string;
    toDate: string;
    items: SettlementPrintItem[];
    formatNumber: (n: number) => string;
    currencySymbol: string;
    labels: PrintSettlementLabels;
    dir?: "rtl" | "ltr";
    lang?: "fa" | "ar";
    variant?: SettlementPrintVariant;
    settledAmount?: number;
    docNumber?: string;
    issueDate?: string;
    settlementId?: number;
    texts?: InvoiceFieldTexts;
  }
) {
  const {
    supplierName,
    fromDate,
    toDate,
    items,
    formatNumber,
    currencySymbol,
    labels,
    dir = "rtl",
    lang = "fa",
    variant = "report",
    settledAmount,
    docNumber,
    issueDate,
    settlementId,
    texts,
  } = opts;

  const totalGross = items.reduce((a, i) => a + Number(i.total || 0), 0);
  const totalPayable = items.reduce((a, i) => a + shareOf(i), 0);
  const issued = issueDate || new Date().toISOString().slice(0, 10);
  const docNo = docNumber || `SET-${issued.replace(/-/g, "")}-${String(Date.now()).slice(-4)}`;
  const payable = Number.isFinite(Number(settledAmount)) ? Number(settledAmount) : totalPayable;
  const align = dir === "rtl" ? "right" : "left";

  const invoiceSource: SettlementInvoiceSource = {
    id: settlementId,
    settlement_number: docNo,
    amount: payable,
    period_start: fromDate,
    period_end: toDate,
    paid_at: issued,
    supplier: { name: supplierName },
  };

  const html =
    variant === "invoice"
      ? buildDesignedInvoiceHtml({
          design: loadInvoiceDesign(),
          texts: texts ?? mergeInvoiceTexts(
            invoiceFieldTexts({
              source: invoiceSource,
              labels,
              formatNumber,
              currencySymbol,
            }),
            loadInvoiceTexts(settlementId)
          ),
          title: `${labels.invoiceTitle} — ${supplierName}`,
          lang,
        })
      : `<!DOCTYPE html>
<html lang="${lang}" dir="${dir}">
<head>
  <meta charset="utf-8" />
  <title>${esc(labels.reportTitle)} — ${esc(supplierName)}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Tahoma, "Segoe UI", Arial, sans-serif; color: #1a1a1a; background: #fff; font-size: 11px; line-height: 1.4; }
    .sheet { max-width: 794px; margin: 0 auto; padding: 4px 2px 8px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1.5px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
    .brand { font-size: 15px; font-weight: 800; }
    .badge { display: inline-block; margin-top: 3px; padding: 1px 7px; border: 1px solid #888; border-radius: 999px; font-size: 9px; color: #555; }
    h1 { font-size: 13px; margin: 0 0 6px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 16px; margin-bottom: 6px; }
    .grid span { display: block; color: #777; font-size: 9px; }
    .grid b { font-size: 11px; font-weight: 700; }
    .branch { font-size: 11px; font-weight: 800; margin: 8px 0 3px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; table-layout: fixed; }
    .col-idx { width: 32px; }
    .col-book { width: auto; }
    .col-qty { width: 92px; }
    .col-money { width: 104px; }
    .grid-table th, .grid-table td { border: 1px solid #d7d7d7; padding: 4px 5px; text-align: ${align}; vertical-align: middle; }
    .grid-table th { font-size: 9px; color: #555; background: #f6f6f6; font-weight: 700; line-height: 1.25; }
    .grid-table td { font-size: 11px; }
    td.title { font-weight: 700; }
    td.num, th.num, td.qty, th.qty, td.money, th.money { text-align: center; white-space: nowrap; }
    .totals { width: 100%; margin-top: 6px; border-top: 1.5px solid #111; padding-top: 7px; }
    .totals .row { display: flex; justify-content: space-between; align-items: baseline; gap: 20px; margin-bottom: 3px; }
    .totals .row span { font-size: 11px; color: #444; white-space: nowrap; }
    .totals .row b { font-size: 12px; white-space: nowrap; }
    .totals .payable span { font-size: 11px; color: #111; font-weight: 700; }
    .totals .payable b { font-size: 13px; }
    .footer { margin-top: 10px; padding-top: 6px; border-top: 1px dashed #ccc; text-align: center; color: #888; font-size: 9px; }
    @media print { body { padding: 0; } }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <div>
        <div class="brand">${esc(labels.brand)}</div>
        <span class="badge">${esc(labels.reportBadge)}</span>
      </div>
    </div>
    <h1>${esc(labels.reportTitle)}</h1>
    <div class="grid">
      <div><span>${esc(labels.supplier)}</span><b>${esc(supplierName)}</b></div>
      <div><span>${esc(labels.period)}</span><b>${esc(fromDate)} — ${esc(toDate)}</b></div>
    </div>
    ${reportTables(items, labels, formatNumber)}
    <div class="totals">
      <div class="row"><span>${esc(labels.total)}</span><b>${esc(formatNumber(totalGross))} ${esc(currencySymbol)}</b></div>
      <div class="row payable"><span>${esc(labels.finalPayable)}</span><b>${esc(formatNumber(totalPayable))} ${esc(currencySymbol)}</b></div>
    </div>
    <div class="footer">${esc(labels.footer)}</div>
  </div>
</body>
</html>`;

  const title = variant === "invoice" ? labels.invoiceTitle : labels.reportTitle;
  await downloadPdfFromHtml(
    html,
    `${safeFilePart(title)}-${safeFilePart(supplierName)}-${safeFilePart(docNo)}.pdf`,
    { format: variant === "invoice" ? "a5" : "a4" }
  );
}

export async function printDesignedInvoice(opts: {
  source: SettlementInvoiceSource;
  labels: PrintSettlementLabels;
  formatNumber: (n: number) => string;
  currencySymbol: string;
  lang?: "fa" | "ar";
  design?: InvoiceDesign;
  texts?: InvoiceFieldTexts;
}): Promise<void> {
  const lang = opts.lang ?? "fa";
  const supplierName = opts.source.supplier?.name || "—";
  const docNo = opts.source.settlement_number || "invoice";
  await downloadPdfFromHtml(
    buildDesignedInvoiceHtml({
      design: opts.design ?? loadInvoiceDesign(),
      texts: opts.texts ?? mergeInvoiceTexts(
        invoiceFieldTexts({
          source: opts.source,
          labels: opts.labels,
          formatNumber: opts.formatNumber,
          currencySymbol: opts.currencySymbol,
        }),
        loadInvoiceTexts(opts.source.id)
      ),
      title: `${opts.labels.invoiceTitle} — ${supplierName}`,
      lang,
    }),
    `${safeFilePart(opts.labels.invoiceTitle)}-${safeFilePart(supplierName)}-${safeFilePart(docNo)}.pdf`,
    { format: "a5" }
  );
}

export function buildSettlementPrintLabels(
  t: (key: string, params?: Record<string, string | number>) => string
): PrintSettlementLabels {
  return {
    brand: t("meta.title").split("|")[0].trim() || "دارالمناهل",
    reportTitle: t("finance.settlement.reportTitle"),
    invoiceTitle: t("finance.settlement.invoiceTitle"),
    supplier: t("finance.settlement.supplier"),
    period: t("finance.settlement.period"),
    fromDate: t("finance.settlement.fromDate"),
    toDate: t("finance.settlement.toDate"),
    book: t("finance.settlement.table.book"),
    qty: t("finance.settlement.table.soldQty"),
    remainingQty: t("finance.settlement.table.remainingQty"),
    unitPrice: t("finance.settlement.table.unitPrice"),
    total: t("finance.settlement.table.total"),
    publisherShare: t("finance.settlement.table.publisherShare"),
    finalPayable: t("finance.settlement.finalPayable"),
    footer: t("finance.settlement.printFooter"),
    docNumber: t("finance.settlement.docNumber"),
    issueDate: t("finance.settlement.issueDate"),
    invoiceDateLabel: t("finance.settlement.invoiceDateLabel"),
    issuer: t("finance.settlement.issuer"),
    receiver: t("finance.settlement.receiver"),
    signatureIssuer: t("finance.settlement.signatureIssuer"),
    signatureReceiver: t("finance.settlement.signatureReceiver"),
    invoiceNote: t("finance.settlement.invoiceNote"),
    reportBadge: t("finance.settlement.reportBadge"),
    invoiceBadge: t("finance.settlement.invoiceBadge"),
    branch: t("distribution.branchFallback"),
    bismillah: t("finance.settlement.bismillah"),
    attachment: t("finance.settlement.attachment"),
    attachmentValue: t("finance.settlement.attachmentValue"),
    recipientHonorific: t("finance.settlement.recipientHonorific"),
    greeting: t("finance.settlement.greeting"),
    invoiceBody: t("finance.settlement.invoiceBody"),
    invoiceAttachNote: t("finance.settlement.invoiceAttachNote"),
    invoiceClosing: t("finance.settlement.invoiceClosing"),
    invoiceContact: t("finance.settlement.invoiceContact"),
  };
}
