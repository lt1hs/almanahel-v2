/**
 * Print-friendly consignment settlement sheet via hidden iframe (no popup blocker).
 */

export type PrintSettlementLabels = {
  brand: string;
  title: string;
  supplier: string;
  period: string;
  fromDate: string;
  toDate: string;
  book: string;
  qty: string;
  total: string;
  commission: string;
  publisherShare: string;
  finalPayable: string;
  footer: string;
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
  total: number;
  commission: number;
};

export function printSettlement(
  opts: {
    supplierName: string;
    fromDate: string;
    toDate: string;
    items: SettlementPrintItem[];
    formatNumber: (n: number) => string;
    currencySymbol: string;
    labels: PrintSettlementLabels;
    dir?: "rtl" | "ltr";
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
  } = opts;

  const totalGross = items.reduce((a, i) => a + Number(i.total || 0), 0);
  const totalCommission = items.reduce((a, i) => a + Number(i.commission || 0), 0);
  const totalPayable = totalGross - totalCommission;

  const rows = items
    .map((item, idx) => {
      const share = Number(item.total || 0) - Number(item.commission || 0);
      return `
        <tr>
          <td>${idx + 1}</td>
          <td class="title">${esc(item.title)}</td>
          <td>${esc(formatNumber(Number(item.qty || 0)))}</td>
          <td>${esc(formatNumber(Number(item.total || 0)))}</td>
          <td>${esc(formatNumber(Number(item.commission || 0)))}</td>
          <td>${esc(formatNumber(share))}</td>
        </tr>`;
    })
    .join("");

  const html = `<!DOCTYPE html>
<html lang="${dir === "rtl" ? "fa" : "ar"}" dir="${dir}">
<head>
  <meta charset="utf-8" />
  <title>${esc(labels.title)} — ${esc(supplierName)}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Tahoma, "Segoe UI", Arial, sans-serif;
      color: #1a1a1a; background: #fff; padding: 24px; font-size: 12px; line-height: 1.6;
    }
    .sheet { max-width: 760px; margin: 0 auto; }
    .header {
      display: flex; justify-content: space-between; align-items: flex-start;
      border-bottom: 2px solid #111; padding-bottom: 14px; margin-bottom: 16px;
    }
    .brand { font-size: 20px; font-weight: 800; }
    .subtitle { font-size: 11px; color: #666; margin-top: 2px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-bottom: 16px; }
    .grid span { display: block; color: #777; font-size: 10px; }
    .grid b { font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    th, td {
      border-bottom: 1px solid #e5e5e5; padding: 8px 6px;
      text-align: ${dir === "rtl" ? "right" : "left"}; vertical-align: top;
    }
    th { font-size: 10px; color: #666; font-weight: 700; background: #f7f7f7; }
    td.title { font-weight: 700; }
    .totals {
      width: 280px; margin-${dir === "rtl" ? "right" : "left"}: auto;
      border-top: 2px solid #111; padding-top: 10px;
    }
    .totals .row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .totals .payable { font-size: 16px; font-weight: 800; margin-top: 6px; }
    .footer {
      margin-top: 28px; padding-top: 12px; border-top: 1px dashed #ccc;
      text-align: center; color: #888; font-size: 10px;
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <div>
        <div class="brand">${esc(labels.brand)}</div>
        <div class="subtitle">${esc(labels.title)}</div>
      </div>
    </div>
    <div class="grid">
      <div><span>${esc(labels.supplier)}</span><b>${esc(supplierName)}</b></div>
      <div><span>${esc(labels.period)}</span><b>${esc(fromDate)} — ${esc(toDate)}</b></div>
    </div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>${esc(labels.book)}</th>
          <th>${esc(labels.qty)}</th>
          <th>${esc(labels.total)}</th>
          <th>${esc(labels.commission)}</th>
          <th>${esc(labels.publisherShare)}</th>
        </tr>
      </thead>
      <tbody>${rows || `<tr><td colspan="6">—</td></tr>`}</tbody>
    </table>
    <div class="totals">
      <div class="row"><span>${esc(labels.total)}</span><b>${esc(formatNumber(totalGross))} ${esc(currencySymbol)}</b></div>
      <div class="row"><span>${esc(labels.commission)}</span><b>−${esc(formatNumber(totalCommission))} ${esc(currencySymbol)}</b></div>
      <div class="row payable"><span>${esc(labels.finalPayable)}</span><b>${esc(formatNumber(totalPayable))} ${esc(currencySymbol)}</b></div>
    </div>
    <div class="footer">${esc(labels.footer)}</div>
  </div>
</body>
</html>`;

  const existing = document.getElementById("settlement-print-frame");
  if (existing) existing.remove();

  const iframe = document.createElement("iframe");
  iframe.id = "settlement-print-frame";
  iframe.setAttribute("aria-hidden", "true");
  iframe.style.cssText =
    "position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none;";
  document.body.appendChild(iframe);

  const doc = iframe.contentDocument || iframe.contentWindow?.document;
  if (!doc) {
    iframe.remove();
    throw new Error("PRINT_UNAVAILABLE");
  }

  doc.open();
  doc.write(html);
  doc.close();

  const triggerPrint = () => {
    try {
      iframe.contentWindow?.focus();
      iframe.contentWindow?.print();
    } finally {
      window.setTimeout(() => iframe.remove(), 60_000);
    }
  };

  if (iframe.contentDocument?.readyState === "complete") {
    window.setTimeout(triggerPrint, 50);
  } else {
    iframe.onload = () => window.setTimeout(triggerPrint, 50);
  }
}

export function buildSettlementPrintLabels(
  t: (key: string, params?: Record<string, string | number>) => string
): PrintSettlementLabels {
  return {
    brand: t("meta.title").split("|")[0].trim() || "دارالمناهل",
    title: t("finance.settlementTab"),
    supplier: t("finance.settlement.supplier"),
    period: t("finance.settlement.period"),
    fromDate: t("finance.settlement.fromDate"),
    toDate: t("finance.settlement.toDate"),
    book: t("finance.settlement.table.book"),
    qty: t("finance.settlement.table.soldQty"),
    total: t("finance.settlement.table.total"),
    commission: t("finance.settlement.table.commission"),
    publisherShare: t("finance.settlement.table.publisherShare"),
    finalPayable: t("finance.settlement.finalPayable"),
    footer: t("finance.settlement.printFooter"),
  };
}
