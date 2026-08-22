export type WorkbookRowKind = "row" | "section" | "subtotal" | "total";

export type WorkbookTable = {
    caption?: string;
    columns: string[];
    moneyCols: number[];
    rows: { kind: WorkbookRowKind; values: (string | number | null)[] }[];
};

export type LedgerWorkbook = {
    sheetName: string;
    company: string;
    title: string;
    subtitle: string;
    meta: [string, string][];
    tables: WorkbookTable[];
    notes: string[];
};

function escapeHtml(value: unknown): string {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;");
}

function moneyText(value: number): string {
    return Number.isFinite(value) ? value.toFixed(2) : "0.00";
}

function csvCell(value: string | number | null | undefined, money: boolean): string {
    if (money && typeof value === "number") {
        return moneyText(value);
    }
    const text = value == null ? "" : String(value);
    if (/[",\n\r]/.test(text)) {
        return `"${text.replaceAll('"', '""')}"`;
    }
    return text;
}

function pad(values: (string | number | null)[], width: number): (string | number | null)[] {
    const next = values.slice(0, width);
    while (next.length < width) next.push("");
    return next;
}

export function buildLedgerCsv(book: LedgerWorkbook): string {
    const tables = Array.isArray(book.tables) ? book.tables : [];
    const width = Math.max(
        2,
        ...tables.map((table) => (table.columns || []).length),
        ...tables.flatMap((table) => (table.rows || []).map((row) => (row.values || []).length))
    );

    const lines: string[] = [];
    const line = (cells: (string | number | null)[], moneyCols: number[] = []) => {
        lines.push(pad(cells, width).map((cell, index) => csvCell(cell, moneyCols.includes(index))).join(","));
    };

    line([book.company || ""]);
    line([book.title || ""]);
    line([book.subtitle || ""]);
    line([""]);
    for (const [label, value] of book.meta || []) {
        line([label, value]);
    }
    line([""]);

    for (const table of tables) {
        const columns = table.columns || [];
        const moneyCols = table.moneyCols || [];
        if (table.caption) {
            line([table.caption]);
        }
        line(columns);
        for (const row of table.rows || []) {
            line(row.values || [], moneyCols);
        }
        line([""]);
    }

    for (const note of book.notes || []) {
        line([note]);
    }

    return "\uFEFF" + lines.join("\r\n");
}

function cellHtml(value: string | number | null | undefined, money: boolean): string {
    if (money && typeof value === "number") {
        return `<td style="mso-number-format:'\\#\\,\\#\\#0.00';text-align:left">${moneyText(value)}</td>`;
    }
    return `<td>${escapeHtml(value ?? "")}</td>`;
}

export function buildLedgerPrintHtml(book: LedgerWorkbook): string {
    const tables = Array.isArray(book.tables) ? book.tables : [];
    const tablesHtml = tables.map((table) => {
        const columns = table.columns || [];
        const moneyCols = table.moneyCols || [];
        const caption = table.caption ? `<caption>${escapeHtml(table.caption)}</caption>` : "";
        const head = `<tr>${columns.map((col) => `<th>${escapeHtml(col)}</th>`).join("")}</tr>`;
        const body = (table.rows || [])
            .map((row) => {
                const values = row.values || [];
                const cells = columns.map((_, index) => cellHtml(values[index], moneyCols.includes(index)));
                return `<tr class="${row.kind || "row"}">${cells.join("")}</tr>`;
            })
            .join("");
        return `<table>${caption}<thead>${head}</thead><tbody>${body}</tbody></table>`;
    }).join("");

    const meta = (book.meta || [])
        .map(([label, value]) => `<div><b>${escapeHtml(label)}:</b> ${escapeHtml(value)}</div>`)
        .join("");
    const notes = (book.notes || []).map((note) => `<p>${escapeHtml(note)}</p>`).join("");

    return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>${escapeHtml(book.title)}</title>
<style>
  body{font-family:Tahoma,Arial,sans-serif;color:#17201f;padding:24px;font-size:12px}
  h1{font-size:20px;margin:0 0 4px;color:#007A7A}
  h2{font-size:15px;margin:0 0 12px}
  .sub{color:#66706e;margin-bottom:16px}
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 18px;margin-bottom:18px;color:#3f4a48}
  table{width:100%;border-collapse:collapse;margin:14px 0}
  caption{text-align:right;font-weight:800;padding:8px;background:#173532;color:#fff}
  th,td{border:1px solid #dfe5e3;padding:7px 8px;text-align:right}
  th{background:#0f6e6e;color:#fff}
  tr.section td{background:#e7f3f2;font-weight:800}
  tr.subtotal td{background:#f4f1e6;font-weight:700}
  tr.total td{background:#0f6e6e;color:#fff;font-weight:800}
  .notes{color:#5b6563;font-size:11px;margin-top:16px}
  @page{size:A4 landscape;margin:12mm}
</style></head><body>
<h1>${escapeHtml(book.company)}</h1>
<h2>${escapeHtml(book.title)}</h2>
<div class="sub">${escapeHtml(book.subtitle)}</div>
<div class="meta">${meta}</div>
${tablesHtml}
<div class="notes">${notes}</div>
</body></html>`;
}

export function downloadCsv(book: LedgerWorkbook, filename: string) {
    const csv = buildLedgerCsv(book);
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename.endsWith(".csv") ? filename : `${filename}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

const EXCEL_COLORS = {
    primary: "FF087F7B",
    primaryDark: "FF075E5B",
    primarySoft: "FFE8F5F3",
    ink: "FF17201F",
    muted: "FF66706E",
    border: "FFDCE6E4",
    gold: "FFC99519",
    goldSoft: "FFFFF5D9",
    white: "FFFFFFFF",
    zebra: "FFF7FAF9",
};

function excelColumnName(index: number): string {
    let value = index;
    let result = "";
    while (value > 0) {
        value -= 1;
        result = String.fromCharCode(65 + (value % 26)) + result;
        value = Math.floor(value / 26);
    }
    return result;
}

function safeSheetName(value: string): string {
    return (value || "Report").replace(/[\\/*?:[\]]/g, " ").slice(0, 31) || "Report";
}

export async function downloadXlsx(book: LedgerWorkbook, filename: string) {
    const excelModule = await import("exceljs");
    const ExcelJS = ("Workbook" in excelModule ? excelModule : (excelModule as { default: typeof excelModule }).default);
    const workbook = new ExcelJS.Workbook();
    workbook.creator = book.company || "Dar Al-Manahel";
    workbook.company = book.company || "Dar Al-Manahel";
    workbook.title = book.title;
    workbook.subject = book.subtitle;
    workbook.created = new Date();
    workbook.modified = new Date();
    workbook.calcProperties.fullCalcOnLoad = true;

    const tables = Array.isArray(book.tables) ? book.tables : [];
    const tableWidth = Math.max(5, ...tables.map((table) => (table.columns || []).length));
    const lastColumn = excelColumnName(tableWidth);
    const fullRange = (row: number) => `A${row}:${lastColumn}${row}`;
    const border = {
        top: { style: "thin" as const, color: { argb: EXCEL_COLORS.border } },
        left: { style: "thin" as const, color: { argb: EXCEL_COLORS.border } },
        bottom: { style: "thin" as const, color: { argb: EXCEL_COLORS.border } },
        right: { style: "thin" as const, color: { argb: EXCEL_COLORS.border } },
    };
    const baseFont = { name: "Arial", size: 10, color: { argb: EXCEL_COLORS.ink } };

    const sheet = workbook.addWorksheet(safeSheetName(book.sheetName), {
        views: [{ rightToLeft: true, showGridLines: false }],
        pageSetup: {
            orientation: "landscape",
            paperSize: 9,
            fitToPage: true,
            fitToWidth: 1,
            fitToHeight: 0,
            margins: { left: 0.25, right: 0.25, top: 0.55, bottom: 0.55, header: 0.2, footer: 0.2 },
        },
        properties: { defaultRowHeight: 21 },
    });

    sheet.mergeCells(fullRange(1));
    const companyCell = sheet.getCell("A1");
    companyCell.value = book.company;
    companyCell.font = { ...baseFont, size: 18, bold: true, color: { argb: EXCEL_COLORS.white } };
    companyCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primaryDark } };
    companyCell.alignment = { horizontal: "right", vertical: "middle" };
    sheet.getRow(1).height = 34;

    sheet.mergeCells(fullRange(2));
    const titleCell = sheet.getCell("A2");
    titleCell.value = book.title;
    titleCell.font = { ...baseFont, size: 15, bold: true, color: { argb: EXCEL_COLORS.primaryDark } };
    titleCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primarySoft } };
    titleCell.alignment = { horizontal: "right", vertical: "middle" };
    sheet.getRow(2).height = 29;

    sheet.mergeCells(fullRange(3));
    const subtitleCell = sheet.getCell("A3");
    subtitleCell.value = book.subtitle;
    subtitleCell.font = { ...baseFont, size: 9, color: { argb: EXCEL_COLORS.muted } };
    subtitleCell.alignment = { horizontal: "right", vertical: "middle", wrapText: true };
    sheet.getRow(3).height = 28;

    let rowIndex = 5;
    for (const [label, value] of book.meta || []) {
        const labelCell = sheet.getCell(rowIndex, 1);
        labelCell.value = label;
        labelCell.font = { ...baseFont, bold: true, color: { argb: EXCEL_COLORS.primaryDark } };
        labelCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primarySoft } };
        labelCell.alignment = { horizontal: "right", vertical: "middle" };
        labelCell.border = border;
        if (tableWidth > 2) sheet.mergeCells(rowIndex, 2, rowIndex, tableWidth);
        const valueCell = sheet.getCell(rowIndex, 2);
        valueCell.value = value;
        valueCell.font = baseFont;
        valueCell.alignment = { horizontal: "right", vertical: "middle", wrapText: true };
        valueCell.border = border;
        rowIndex += 1;
    }
    rowIndex += 1;

    let firstHeaderRow: number | null = null;
    let firstDataEndRow: number | null = null;
    for (const table of tables) {
        const columns = table.columns || [];
        const moneyCols = table.moneyCols || [];
        const rows = table.rows || [];
        if (table.caption) {
            sheet.mergeCells(fullRange(rowIndex));
            const caption = sheet.getCell(rowIndex, 1);
            caption.value = table.caption;
            caption.font = { ...baseFont, bold: true, color: { argb: EXCEL_COLORS.white } };
            caption.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primaryDark } };
            caption.alignment = { horizontal: "right", vertical: "middle" };
            sheet.getRow(rowIndex).height = 25;
            rowIndex += 1;
        }

        const headerRow = sheet.getRow(rowIndex);
        columns.forEach((column, index) => {
            const cell = headerRow.getCell(index + 1);
            cell.value = column;
            cell.font = { ...baseFont, bold: true, color: { argb: EXCEL_COLORS.white } };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primary } };
            cell.alignment = { horizontal: "center", vertical: "middle", wrapText: true };
            cell.border = border;
        });
        headerRow.height = 28;
        if (firstHeaderRow === null) firstHeaderRow = rowIndex;
        rowIndex += 1;

        rows.forEach((sourceRow, sourceIndex) => {
            const row = sheet.getRow(rowIndex);
            (sourceRow.values || []).forEach((value, index) => {
                const cell = row.getCell(index + 1);
                cell.value = value;
                cell.font = { ...baseFont, bold: sourceRow.kind !== "row" };
                cell.alignment = { horizontal: typeof value === "number" ? "left" : "right", vertical: "middle", wrapText: true };
                cell.border = border;
                if (moneyCols.includes(index) && typeof value === "number") {
                    cell.numFmt = "#,##0.00;[Red]-#,##0.00;–";
                } else if (typeof value === "number") {
                    cell.numFmt = "#,##0";
                }
                if (sourceRow.kind === "section") {
                    cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primarySoft } };
                    cell.font = { ...cell.font, color: { argb: EXCEL_COLORS.primaryDark } };
                } else if (sourceRow.kind === "subtotal") {
                    cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.goldSoft } };
                    cell.font = { ...cell.font, color: { argb: EXCEL_COLORS.gold } };
                } else if (sourceRow.kind === "total") {
                    cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.primaryDark } };
                    cell.font = { ...cell.font, color: { argb: EXCEL_COLORS.white } };
                } else if (sourceIndex % 2 === 1) {
                    cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.zebra } };
                }
            });
            row.height = sourceRow.kind === "row" ? 23 : 25;
            rowIndex += 1;
        });
        if (firstDataEndRow === null) firstDataEndRow = rowIndex - 1;
        rowIndex += 2;
    }

    if ((book.notes || []).length) {
        sheet.mergeCells(fullRange(rowIndex));
        const noteTitle = sheet.getCell(rowIndex, 1);
        noteTitle.value = "یادداشت‌های گزارش";
        noteTitle.font = { ...baseFont, bold: true, color: { argb: EXCEL_COLORS.gold } };
        noteTitle.fill = { type: "pattern", pattern: "solid", fgColor: { argb: EXCEL_COLORS.goldSoft } };
        noteTitle.alignment = { horizontal: "right" };
        rowIndex += 1;
        for (const note of book.notes) {
            sheet.mergeCells(fullRange(rowIndex));
            const cell = sheet.getCell(rowIndex, 1);
            cell.value = `• ${note}`;
            cell.font = { ...baseFont, size: 9, color: { argb: EXCEL_COLORS.muted } };
            cell.alignment = { horizontal: "right", vertical: "middle", wrapText: true };
            sheet.getRow(rowIndex).height = 28;
            rowIndex += 1;
        }
    }

    sheet.columns.forEach((column, index) => {
        let width = index === 0 ? 18 : 15;
        column.eachCell?.({ includeEmpty: false }, (cell) => {
            const text = String(cell.value ?? "");
            width = Math.max(width, Math.min(38, text.length + 3));
        });
        column.width = Math.min(width, index === 0 ? 30 : 24);
    });
    if (firstHeaderRow !== null) {
        sheet.views = [{ rightToLeft: true, showGridLines: false, state: "frozen", ySplit: firstHeaderRow }];
        if (firstDataEndRow !== null && firstDataEndRow >= firstHeaderRow) {
            sheet.autoFilter = { from: { row: firstHeaderRow, column: 1 }, to: { row: firstDataEndRow, column: tableWidth } };
        }
    }
    sheet.pageSetup.printArea = `A1:${lastColumn}${Math.max(1, rowIndex - 1)}`;
    sheet.headerFooter.oddHeader = `&R${book.company}&C${book.title}&L&D`;
    sheet.headerFooter.oddFooter = "&Rصفحه &P از &N&Lمحرمانه — گزارش داخلی";

    const output = await workbook.xlsx.writeBuffer();
    const blob = new Blob([new Uint8Array(output as ArrayBuffer)], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename.endsWith(".xlsx") ? filename : `${filename}.xlsx`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

export function printWorkbook(book: LedgerWorkbook) {
    const frame = document.createElement("iframe");
    Object.assign(frame.style, { position: "fixed", width: "0", height: "0", border: "0" });
    document.body.appendChild(frame);
    const doc = frame.contentDocument;
    if (!doc) {
        frame.remove();
        return;
    }
    doc.open();
    doc.write(buildLedgerPrintHtml(book));
    doc.close();
    window.setTimeout(() => {
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
        window.setTimeout(() => frame.remove(), 1000);
    }, 250);
}
