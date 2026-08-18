/**
 * Opens a print-friendly invoice receipt in a new window.
 * Works with static export (no server PDF needed).
 */

export type PrintInvoiceLabels = {
  brand: string;
  title: string;
  invoiceNumber: string;
  date: string;
  branch: string;
  cashier: string;
  customer: string;
  phone: string;
  paymentMethod: string;
  dueDate: string;
  items: string;
  book: string;
  qty: string;
  unitPrice: string;
  lineTotal: string;
  subtotal: string;
  discount: string;
  payable: string;
  checkNumber: string;
  bankName: string;
  payerName: string;
  checkStatus: string;
  footer: string;
  cash: string;
  check: string;
  credit: string;
  card: string;
  statusPaid: string;
  statusPending: string;
  statusOverdue: string;
  checkCleared: string;
  checkBounced: string;
  checkPending: string;
};

function esc(value: unknown): string {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatDue(value: unknown): string {
  if (!value) return "—";
  return String(value).slice(0, 10);
}

function formatDateTime(value: unknown): string {
  if (!value) return "—";
  const d = new Date(String(value));
  if (Number.isNaN(d.getTime())) return String(value).slice(0, 19).replace("T", " ");
  return d.toLocaleString("fa-IR", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

type PrintInvoiceDoc = {
  payment_method?: string;
  payment_status?: string;
  items?: Array<Record<string, unknown>>;
  invoice_number?: string;
  created_at?: string;
  branch?: { name?: string };
  user?: { name?: string };
  customer_name?: string;
  customer_phone?: string;
  due_date?: string;
  subtotal?: number;
  discount_amount?: number;
  total?: number;
  check?: Record<string, unknown>;
  [key: string]: unknown;
};

export function printInvoice(
  invoice: PrintInvoiceDoc,
  opts: {
    formatNumber: (n: number) => string;
    currencySymbol: string;
    labels: PrintInvoiceLabels;
    dir?: "rtl" | "ltr";
  }
) {
  const { formatNumber, currencySymbol, labels, dir = "rtl" } = opts;

  const paymentMethod =
    invoice.payment_method === "check"
      ? labels.check
      : invoice.payment_method === "credit"
        ? labels.credit
        : invoice.payment_method === "card"
          ? labels.card
          : labels.cash;

  const paymentStatus =
    invoice.payment_status === "paid"
      ? labels.statusPaid
      : invoice.payment_status === "overdue"
        ? labels.statusOverdue
        : labels.statusPending;

  const items: Array<Record<string, unknown>> = invoice.items || [];
  const rows = items
    .map((item, idx) => {
      const qty = Number(item.quantity || 0);
      const price = Number(item.actual_price || 0);
      const discount = Number(item.discount || 0);
      const line = Math.max(0, qty * price - qty * discount);
      const book = item.book as { title?: string; author?: string } | undefined;
      return `
        <tr>
          <td>${idx + 1}</td>
          <td class="title">${esc(book?.title || `#${String(item.book_id ?? "")}`)}${
            book?.author ? `<div class="muted">${esc(book.author)}</div>` : ""
          }</td>
          <td>${esc(formatNumber(qty))}</td>
          <td>${esc(formatNumber(price))}</td>
          <td>${esc(formatNumber(line))}</td>
        </tr>`;
    })
    .join("");

  const checkBlock =
    invoice.payment_method === "check" && invoice.check
      ? `
      <div class="box">
        <h3>${esc(labels.check)}</h3>
        <div class="grid">
          <div><span>${esc(labels.checkNumber)}</span><b>${esc(invoice.check.check_number)}</b></div>
          <div><span>${esc(labels.bankName)}</span><b>${esc(invoice.check.bank_name || "—")}</b></div>
          <div><span>${esc(labels.payerName)}</span><b>${esc(invoice.check.payer_name || "—")}</b></div>
          <div><span>${esc(labels.dueDate)}</span><b>${esc(formatDue(invoice.check.due_date))}</b></div>
          <div><span>${esc(labels.checkStatus)}</span><b>${esc(
            invoice.check.status === "cleared"
              ? labels.checkCleared
              : invoice.check.status === "bounced"
                ? labels.checkBounced
                : labels.checkPending
          )}</b></div>
        </div>
      </div>`
      : "";

  const html = `<!DOCTYPE html>
<html lang="${dir === "rtl" ? "fa" : "ar"}" dir="${dir}">
<head>
  <meta charset="utf-8" />
  <title>${esc(labels.title)} — ${esc(invoice.invoice_number)}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Tahoma, "Segoe UI", Arial, sans-serif;
      color: #1a1a1a;
      background: #fff;
      padding: 24px;
      font-size: 12px;
      line-height: 1.6;
    }
    .sheet { max-width: 720px; margin: 0 auto; }
    .header {
      display: flex; justify-content: space-between; align-items: flex-start;
      border-bottom: 2px solid #111; padding-bottom: 14px; margin-bottom: 16px;
    }
    .brand { font-size: 20px; font-weight: 800; }
    .subtitle { font-size: 11px; color: #666; margin-top: 2px; }
    .meta { text-align: ${dir === "rtl" ? "left" : "right"}; }
    .meta b { display: block; font-size: 14px; margin-top: 2px; }
    .badge {
      display: inline-block; margin-top: 6px; padding: 2px 8px;
      border: 1px solid #ccc; border-radius: 999px; font-size: 10px;
    }
    .grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-bottom: 16px;
    }
    .grid span { display: block; color: #777; font-size: 10px; }
    .grid b { font-size: 12px; }
    .box {
      border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin-bottom: 16px;
      background: #fafafa;
    }
    .box h3 { font-size: 11px; margin-bottom: 8px; color: #444; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    th, td {
      border-bottom: 1px solid #e5e5e5; padding: 8px 6px;
      text-align: ${dir === "rtl" ? "right" : "left"}; vertical-align: top;
    }
    th { font-size: 10px; color: #666; font-weight: 700; background: #f7f7f7; }
    td.title { font-weight: 700; }
    .muted { font-weight: 400; color: #888; font-size: 10px; margin-top: 2px; }
    .totals {
      width: 260px; margin-${dir === "rtl" ? "right" : "left"}: auto;
      border-top: 2px solid #111; padding-top: 10px;
    }
    .totals .row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .totals .payable { font-size: 16px; font-weight: 800; margin-top: 6px; }
    .footer {
      margin-top: 28px; padding-top: 12px; border-top: 1px dashed #ccc;
      text-align: center; color: #888; font-size: 10px;
    }
    @media print {
      body { padding: 0; }
      .no-print { display: none !important; }
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
      <div class="meta">
        <span>${esc(labels.invoiceNumber)}</span>
        <b>${esc(invoice.invoice_number)}</b>
        <span class="badge">${esc(paymentStatus)} · ${esc(paymentMethod)}</span>
      </div>
    </div>

    <div class="grid">
      <div><span>${esc(labels.date)}</span><b>${esc(formatDateTime(invoice.created_at))}</b></div>
      <div><span>${esc(labels.branch)}</span><b>${esc(invoice.branch?.name || "—")}</b></div>
      <div><span>${esc(labels.cashier)}</span><b>${esc(invoice.user?.name || "—")}</b></div>
      <div><span>${esc(labels.paymentMethod)}</span><b>${esc(paymentMethod)}</b></div>
      <div><span>${esc(labels.customer)}</span><b>${esc(invoice.customer_name || labels.cash)}</b></div>
      ${
        invoice.customer_phone
          ? `<div><span>${esc(labels.phone)}</span><b>${esc(invoice.customer_phone)}</b></div>`
          : ""
      }
      ${
        invoice.due_date
          ? `<div><span>${esc(labels.dueDate)}</span><b>${esc(formatDue(invoice.due_date))}</b></div>`
          : ""
      }
    </div>

    ${checkBlock}

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>${esc(labels.book)}</th>
          <th>${esc(labels.qty)}</th>
          <th>${esc(labels.unitPrice)}</th>
          <th>${esc(labels.lineTotal)}</th>
        </tr>
      </thead>
      <tbody>
        ${rows || `<tr><td colspan="5">${esc(labels.items)} —</td></tr>`}
      </tbody>
    </table>

    <div class="totals">
      <div class="row"><span>${esc(labels.subtotal)}</span><b>${esc(formatNumber(Number(invoice.subtotal || 0)))} ${esc(currencySymbol)}</b></div>
      ${
        Number(invoice.discount_amount) > 0
          ? `<div class="row"><span>${esc(labels.discount)}</span><b>−${esc(formatNumber(Number(invoice.discount_amount)))} ${esc(currencySymbol)}</b></div>`
          : ""
      }
      <div class="row payable"><span>${esc(labels.payable)}</span><b>${esc(formatNumber(Number(invoice.total || 0)))} ${esc(currencySymbol)}</b></div>
    </div>

    <div class="footer">${esc(labels.footer)}</div>
  </div>
</body>
</html>`;

  // Hidden iframe avoids popup blockers (especially after async fetch).
  const existing = document.getElementById("invoice-print-frame");
  if (existing) existing.remove();

  const iframe = document.createElement("iframe");
  iframe.id = "invoice-print-frame";
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
      // Keep frame briefly so the print dialog can finish reading it
      window.setTimeout(() => {
        iframe.remove();
      }, 60_000);
    }
  };

  // Some browsers fire load immediately after write; others need a tick
  if (iframe.contentDocument?.readyState === "complete") {
    window.setTimeout(triggerPrint, 50);
  } else {
    iframe.onload = () => window.setTimeout(triggerPrint, 50);
  }
}

export function buildInvoicePrintLabels(t: (key: string, params?: Record<string, string | number>) => string): PrintInvoiceLabels {
  return {
    brand: t("meta.title").split("|")[0].trim() || "دارالمناهل",
    title: t("sales.invoiceDetail.title"),
    invoiceNumber: t("sales.invoiceDetail.invoiceNumber"),
    date: t("sales.invoiceDetail.date"),
    branch: t("sales.invoiceDetail.branch"),
    cashier: t("sales.invoiceDetail.cashier"),
    customer: t("sales.customerName"),
    phone: t("sales.customerPhone"),
    paymentMethod: t("sales.invoiceDetail.paymentMethod"),
    dueDate: t("sales.dueDate"),
    items: t("sales.invoiceDetail.items"),
    book: t("inventory.bookTitle"),
    qty: t("sales.invoiceDetail.qty"),
    unitPrice: t("sales.invoiceDetail.unitPrice"),
    lineTotal: t("sales.invoiceDetail.lineTotal"),
    subtotal: t("sales.subtotal"),
    discount: t("sales.discount"),
    payable: t("sales.payable"),
    checkNumber: t("sales.checkNumber"),
    bankName: t("sales.bankName"),
    payerName: t("sales.payerName"),
    checkStatus: t("sales.invoiceDetail.checkStatus"),
    footer: t("sales.invoiceDetail.printFooter"),
    cash: t("sales.cash"),
    check: t("sales.check"),
    credit: t("sales.credit"),
    card: t("sales.card"),
    statusPaid: t("sales.invoiceDetail.statusPaid"),
    statusPending: t("sales.invoiceDetail.statusPending"),
    statusOverdue: t("sales.invoiceDetail.statusOverdue"),
    checkCleared: t("checks.status.cleared"),
    checkBounced: t("checks.status.bounced"),
    checkPending: t("checks.status.pending"),
  };
}
