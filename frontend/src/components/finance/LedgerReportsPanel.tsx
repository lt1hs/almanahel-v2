"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
    AlertTriangle, BadgeDollarSign, BarChart3, Boxes, CalendarRange,
    CheckCircle2, Download, FileSpreadsheet, Globe2, HandCoins,
    Landmark, Printer, RefreshCw, Scale, UserRound, WalletCards,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { apiRequest } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { cn } from "@/lib/utils";
import { downloadXlsx, printWorkbook, type LedgerWorkbook, type WorkbookTable } from "@/components/finance/ledgerWorkbook";

type Currency = "toman" | "dinar";
type Tab = "pnl" | "treasury" | "trial" | "position" | "ar" | "ap" | "checks" | "iraq" | "inventory";
type Row = Record<string, unknown>;

const TAB_ICONS: Record<Tab, React.ElementType> = {
    pnl: BarChart3, treasury: WalletCards, trial: Scale, position: Landmark,
    ar: UserRound, ap: HandCoins, checks: BadgeDollarSign, iraq: Globe2, inventory: Boxes,
};

const isoDate = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
const record = (value: unknown): Row => value && typeof value === "object" && !Array.isArray(value) ? value as Row : {};
const rows = (value: unknown): Row[] => Array.isArray(value) ? value.filter((item) => item && typeof item === "object") as Row[] : [];
const amount = (value: unknown) => Number.isFinite(Number(value ?? 0)) ? Number(value ?? 0) : 0;

function statusLabel(t: (key: string) => string, value: unknown) {
    const status = String(value || "").trim();
    if (!status) return "—";
    const key = `finance.ledger.statuses.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
}

function typeLabel(t: (key: string) => string, type: string) {
    const key = `finance.ledger.types.${type}`;
    const translated = t(key);
    return translated === key ? type : translated;
}

function accountType(code: unknown) {
    const parts = String(code || "").split(".");
    return parts[0] === "sys" && parts.length > 1 ? parts[1] : "";
}

function accountTitle(t: (key: string) => string, row: Row) {
    const type = accountType(row.code) || String(row.type || "");
    const translated = type ? typeLabel(t, type) : "";
    if (translated && translated !== type) return translated;
    const name = String(row.name || "");
    if (name && !name.startsWith("sys.")) return name;
    return translated || name || "—";
}

function currencyLabel(t: (key: string) => string, currency: string) {
    if (currency === "toman") return t("common.toman");
    if (currency === "dinar") return t("common.dinar");
    return "";
}

function accountSubtitle(t: (key: string) => string, row: Row) {
    const code = String(row.code || "");
    const parts = code.split(".").filter(Boolean);
    const type = accountType(code) || String(row.type || "");
    const currency = parts[0] === "sys" && parts.length >= 3 ? parts[parts.length - 1] : String(row.currency || "");
    const title = accountTitle(t, row);
    const typeText = type ? typeLabel(t, type) : "";
    const bits = [
        typeText && typeText !== title ? typeText : "",
        currencyLabel(t, currency),
    ].filter(Boolean);
    return bits.join(" · ");
}

function treasuryTitle(t: (key: string) => string, row: Row) {
    const type = String(row.type || "");
    const automatic = `${type} ${row.currency || ""}`.trim();
    return !row.name || row.name === automatic || row.name === type ? typeLabel(t, type) : String(row.name);
}

function closingBalance(row: Row) {
    const debit = amount(row.closing_debit);
    const credit = amount(row.closing_credit);
    if (debit > credit) return { value: debit - credit, side: "debit" };
    if (credit > debit) return { value: credit - debit, side: "credit" };
    return { value: 0, side: "balanced" };
}

function Empty({ label }: { label: string }) {
    return <div className="flex min-h-44 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-ink/10 bg-ink/[0.015] text-ink/30"><FileSpreadsheet className="h-8 w-8" /><p className="text-[11px] font-bold">{label}</p></div>;
}

function Money({ value, symbol, format, bold = false }: { value: unknown; symbol?: string; format: (value: number) => string; bold?: boolean }) {
    const number = amount(value);
    return <span className={cn("tabular-nums", bold && "font-black", number < 0 ? "text-rose-600" : "text-ink")}>{format(number)}{symbol && <small className="ms-1 text-[9px] font-bold text-ink/30">{symbol}</small>}</span>;
}

function Table({ headers, data }: { headers: string[]; data: React.ReactNode[][] }) {
    return <div className="overflow-x-auto rounded-2xl border border-ink/5"><table className="w-full min-w-[680px] text-[10px]"><thead className="bg-ink/[0.035] text-[9px] font-black text-ink/45"><tr>{headers.map((header) => <th key={header} className="whitespace-nowrap px-4 py-3 text-start">{header}</th>)}</tr></thead><tbody className="divide-y divide-ink/5">{data.map((row, index) => <tr key={index} className="hover:bg-primary/[0.025]">{row.map((cell, cellIndex) => <td key={cellIndex} className="whitespace-nowrap px-4 py-3 text-start tabular-nums text-ink/65">{cell}</td>)}</tr>)}</tbody></table></div>;
}

export function LedgerReportsPanel() {
    const { t, formatNumber, preferredCurrency } = useTranslation();
    const { user } = useAuth();
    const aggregate = ["admin", "super_admin", "accountant"].includes(String(user?.role));
    const [currency, setCurrency] = useState<Currency>(preferredCurrency);
    const [tab, setTab] = useState<Tab>("pnl");
    const [from, setFrom] = useState(() => isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)));
    const [to, setTo] = useState(() => isoDate(new Date()));
    const [branchId, setBranchId] = useState(user?.branch_id ? String(user.branch_id) : "");
    const [branches, setBranches] = useState<{ id: number; name: string }[]>([]);
    const [report, setReport] = useState<Row | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [updated, setUpdated] = useState<Date | null>(null);

    useEffect(() => {
        if (!aggregate) return;
        apiRequest("/branches?lite=1").then((value) => setBranches(Array.isArray(value) ? value : [])).catch(() => setBranches([]));
    }, [aggregate]);

    const query = useMemo(() => {
        const params = new URLSearchParams({ currency, date_from: from, date_to: to });
        if (aggregate && branchId) params.set("branch_id", branchId);
        return params.toString();
    }, [currency, from, to, aggregate, branchId]);

    const load = useCallback(async () => {
        if (!from || !to || from > to) {
            setReport(null);
            setError(t("finance.ledger.invalidPeriod"));
            return;
        }
        const endpoints: Record<Tab, string> = {
            pnl: `/finance/pnl?${query}`, treasury: `/finance/treasury?${query}`,
            trial: `/finance/trial-balance?${query}`, position: `/finance/financial-position?${query}`,
            ar: `/finance/receivables?${query}`, ap: `/finance/payables?${query}`,
            checks: `/finance/checks?${query}`, iraq: `/reports/iraq-profit?${query}`,
            inventory: `/finance/inventory-value?currency=${currency}${aggregate && branchId ? `&branch_id=${branchId}` : ""}`,
        };
        setLoading(true);
        setError(null);
        try {
            setReport(record(await apiRequest(endpoints[tab])));
            setUpdated(new Date());
        } catch (cause) {
            setReport(null);
            setError(cause instanceof Error ? cause.message : t("finance.loadError"));
        } finally {
            setLoading(false);
        }
    }, [from, to, query, tab, currency, aggregate, branchId, t]);

    useEffect(() => { load(); }, [load]);

    const symbol = currency === "dinar" ? t("common.currency.dinarSymbol") : t("common.currency.tomanSymbol");
    const branchName = branchId ? branches.find((branch) => String(branch.id) === branchId)?.name || `#${branchId}` : t("finance.ledger.allBranches");
    const tabs = (Object.keys(TAB_ICONS) as Tab[]).map((id) => ({ id, icon: TAB_ICONS[id], label: t(`finance.ledger.${id}`), description: t(`finance.ledger.descriptions.${id}`) }));
    const active = tabs.find((item) => item.id === tab) || tabs[0];

    const preset = (kind: "today" | "month" | "previous") => {
        const now = new Date();
        if (kind === "today") { setFrom(isoDate(now)); setTo(isoDate(now)); return; }
        const start = kind === "month" ? new Date(now.getFullYear(), now.getMonth(), 1) : new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const end = kind === "month" ? now : new Date(now.getFullYear(), now.getMonth(), 0);
        setFrom(isoDate(start)); setTo(isoDate(end));
    };

    const workbook = useCallback((): LedgerWorkbook | null => {
        if (!report) return null;
        const data = report;
        const currencyName = currency === "dinar" ? t("common.dinar") : t("common.toman");
        const days = Math.max(1, Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000) + 1);
        const meta: [string, string][] = [
            [t("finance.ledger.report"), active.label],
            [t("finance.ledger.branch"), branchName],
            [t("finance.ledger.currency"), `${currencyName} (${symbol})`],
            [t("finance.ledger.period"), `${from} — ${to}`],
            [t("finance.ledger.dayCount"), String(days)],
        ];
        if (data.as_of) meta.push([t("finance.ledger.asOf"), String(data.as_of)]);
        meta.push([t("finance.ledger.journalBasis"), t("finance.ledger.journalBasisValue")]);
        if ("reconciled" in data) {
            meta.push([
                t("finance.ledger.reconciled"),
                data.reconciled ? t("common.yes") : `${t("common.no")} · ${t("finance.ledger.difference")}: ${formatNumber(amount(data.difference))} ${symbol}`,
            ]);
        }
        meta.push([t("finance.ledger.generatedAt"), new Date().toLocaleString()]);

        const tables: WorkbookTable[] = [];
        const notes = [t("finance.ledger.exportDisclaimer")];

        if (tab === "pnl") {
            const expenses = amount(data.operating_expenses) + amount(data.gift_expenses);
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.exportSection"), t("finance.ledger.account"), t("finance.ledger.exportNature"), t("finance.ledger.amount")],
                moneyCols: [4],
                rows: [
                    { kind: "section", values: ["", t("finance.ledger.exportIncome"), "", "", null] },
                    { kind: "row", values: [1, t("finance.ledger.exportIncome"), t("finance.ledger.gross_sales"), t("finance.ledger.natureIncome"), amount(data.gross_sales)] },
                    { kind: "row", values: [2, t("finance.ledger.exportIncome"), t("finance.ledger.sales_returns"), t("finance.ledger.natureContra"), amount(data.sales_returns)] },
                    { kind: "subtotal", values: ["", t("finance.ledger.exportIncome"), t("finance.ledger.net_sales"), t("finance.ledger.natureSubtotal"), amount(data.net_sales)] },
                    { kind: "section", values: ["", t("finance.ledger.exportCogs"), "", "", null] },
                    { kind: "row", values: [3, t("finance.ledger.exportCogs"), t("finance.ledger.cogs"), t("finance.ledger.natureExpense"), amount(data.net_cogs ?? data.cogs)] },
                    { kind: "subtotal", values: ["", t("finance.ledger.exportCogs"), t("finance.ledger.gross_profit"), t("finance.ledger.natureSubtotal"), amount(data.gross_profit)] },
                    { kind: "section", values: ["", t("finance.ledger.exportExpenses"), "", "", null] },
                    { kind: "row", values: [4, t("finance.ledger.exportExpenses"), t("finance.ledger.operating_expenses"), t("finance.ledger.natureExpense"), amount(data.operating_expenses)] },
                    { kind: "row", values: [5, t("finance.ledger.exportExpenses"), t("finance.ledger.gift_expenses"), t("finance.ledger.natureExpense"), amount(data.gift_expenses)] },
                    { kind: "subtotal", values: ["", t("finance.ledger.exportExpenses"), t("finance.ledger.totalExpenses"), t("finance.ledger.natureSubtotal"), expenses] },
                    { kind: "total", values: ["", t("finance.ledger.exportResult"), t("finance.ledger.net_profit"), t("finance.ledger.natureResult"), amount(data.net_profit)] },
                ],
            });
            notes.unshift(t("finance.ledger.pnlEquation"));
        } else if (tab === "treasury") {
            const accounts = rows(data.accounts).filter((row) => row.currency === currency);
            const sum = accounts.reduce<{ opening: number; debit: number; credit: number; closing: number }>((result, row) => ({
                opening: result.opening + amount(row.opening_balance),
                debit: result.debit + amount(row.period_debit),
                credit: result.credit + amount(row.period_credit),
                closing: result.closing + amount(row.closing_balance),
            }), { opening: 0, debit: 0, credit: 0, closing: 0 });
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.account"), t("finance.ledger.type"), t("finance.ledger.branch"), t("finance.ledger.opening"), t("finance.ledger.debit"), t("finance.ledger.credit"), t("finance.ledger.closing")],
                moneyCols: [4, 5, 6, 7],
                rows: [
                    ...accounts.map((row, index) => ({
                        kind: "row" as const,
                        values: [index + 1, treasuryTitle(t, row), typeLabel(t, String(row.type || "")), row.branch_id == null ? t("finance.ledger.corporate") : String(row.branch_id), amount(row.opening_balance), amount(row.period_debit), amount(row.period_credit), amount(row.closing_balance)],
                    })),
                    { kind: "total", values: ["", t("finance.ledger.totals"), "", "", sum.opening, sum.debit, sum.credit, sum.closing] },
                ],
            });
        } else if (tab === "trial") {
            const accounts = rows(data.accounts);
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.account"), t("finance.ledger.type"), t("finance.ledger.opening"), t("finance.ledger.debit"), t("finance.ledger.credit"), t("finance.ledger.closing"), t("finance.ledger.balanceNature")],
                moneyCols: [3, 4, 5, 6],
                rows: [
                    ...accounts.map((row, index) => {
                        const close = closingBalance(row);
                        return { kind: "row" as const, values: [index + 1, accountTitle(t, row), typeLabel(t, String(row.type || accountType(row.code))), Math.abs(amount(row.opening_net)), amount(row.period_debit), amount(row.period_credit), close.value, t(`finance.ledger.${close.side}`)] };
                    }),
                    { kind: "total", values: ["", t("finance.ledger.totals"), "", null, amount(data.total_debits), amount(data.total_credits), null, data.balanced ? t("finance.ledger.balanced") : t("common.no")] },
                ],
            });
        } else if (tab === "position") {
            const body: WorkbookTable["rows"] = [];
            (["assets", "liabilities", "equity"] as const).forEach((group) => {
                body.push({ kind: "section", values: [t(`finance.ledger.${group}`), "", null] });
                Object.entries(record(data[group])).forEach(([key, value]) => {
                    body.push({ kind: "row", values: [t(`finance.ledger.${group}`), typeLabel(t, key), amount(value)] });
                });
            });
            body.push({ kind: "subtotal", values: [t("finance.ledger.totalAssets"), "", amount(data.total_assets)] });
            body.push({ kind: "subtotal", values: [t("finance.ledger.totalLiabilities"), "", amount(data.total_liabilities)] });
            body.push({ kind: "total", values: [t("finance.ledger.liabilitiesAndEquity"), "", amount(data.total_liabilities_and_equity)] });
            tables.push({ columns: [t("finance.ledger.group"), t("finance.ledger.account"), t("finance.ledger.amount")], moneyCols: [2], rows: body });
            notes.unshift(t("finance.ledger.positionEquation"));
        } else if (tab === "ar") {
            const opening = record(data.opening);
            const movement = record(data.period_movement);
            const closing = record(data.closing);
            const customers = rows(data.customers);
            tables.push({
                caption: t("finance.ledger.ledgerTotal"),
                columns: [t("finance.ledger.account"), t("finance.ledger.opening"), t("finance.ledger.periodMovement"), t("finance.ledger.closing"), t("finance.ledger.operationalAsOf")],
                moneyCols: [1, 2, 3, 4],
                rows: [{ kind: "total", values: [t("finance.ledger.ar"), amount(opening.ledger), amount(movement.ledger), amount(closing.ledger), amount(data.operational_as_of)] }],
            });
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.customer"), t("finance.ledger.openAr"), t("finance.ledger.overdue"), t("finance.ledger.customerCredit"), t("finance.ledger.netBalance"), t("finance.ledger.invoiceCount")],
                moneyCols: [2, 3, 4, 5],
                rows: customers.map((row, index) => ({
                    kind: "row" as const,
                    values: [index + 1, String(row.customer_name || `#${row.customer_id || "—"}`), amount(row.open_ar), amount(row.overdue_ar), amount(row.customer_credit_liability), amount(row.net_position), rows(row.invoices).length],
                })),
            });
            const invoices = customers.flatMap((row) => rows(row.invoices).map((invoice) => ({ customer: String(row.customer_name || `#${row.customer_id || "—"}`), invoice })));
            if (invoices.length) {
                tables.push({
                    caption: t("finance.ledger.invoiceDetail"),
                    columns: [t("finance.ledger.customer"), t("finance.ledger.invoiceNo"), t("finance.ledger.faceAmount"), t("finance.ledger.outstanding"), t("finance.ledger.historicalStatus"), t("finance.ledger.currentStatus")],
                    moneyCols: [2, 3],
                    rows: invoices.map((item) => ({
                        kind: "row" as const,
                        values: [item.customer, `#${item.invoice.invoice_id || "—"}`, amount(item.invoice.face_amount), amount(item.invoice.outstanding), statusLabel(t, item.invoice.historical_status), statusLabel(t, item.invoice.current_status)],
                    })),
                });
            }
        } else if (tab === "ap") {
            const suppliers = rows(data.suppliers);
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.supplier"), t("finance.ledger.branch"), t("finance.ledger.supplierPayable"), t("finance.ledger.checkPayable"), t("finance.ledger.supplierRecoverable"), t("finance.ledger.operational")],
                moneyCols: [3, 4, 5, 6],
                rows: [
                    ...suppliers.map((row, index) => ({
                        kind: "row" as const,
                        values: [index + 1, String(row.supplier_name || `#${row.supplier_id || "—"}`), String(row.branch_name || "—"), amount(row.supplier_payable), amount(row.checks_payable), amount(row.supplier_recoverable), amount(row.operational_payable)],
                    })),
                    { kind: "total", values: ["", t("finance.ledger.totals"), "", suppliers.reduce((sum, row) => sum + amount(row.supplier_payable), 0), suppliers.reduce((sum, row) => sum + amount(row.checks_payable), 0), suppliers.reduce((sum, row) => sum + amount(row.supplier_recoverable), 0), suppliers.reduce((sum, row) => sum + amount(row.operational_payable), 0)] },
                ],
            });
        } else if (tab === "checks") {
            const body: WorkbookTable["rows"] = [];
            (["incoming", "outgoing"] as const).forEach((direction) => {
                body.push({ kind: "section", values: [t(`finance.ledger.${direction}`), "", null] });
                Object.entries(record(data[`${direction}_historical`] || data[direction])).forEach(([status, value]) => {
                    body.push({ kind: "row", values: [t(`finance.ledger.${direction}`), statusLabel(t, status), amount(value)] });
                });
                body.push({ kind: "subtotal", values: [t(`finance.ledger.${direction}`), t("finance.ledger.ledgerBalance"), amount(data[direction === "incoming" ? "ledger_checks_receivable" : "ledger_checks_payable"])] });
            });
            tables.push({ columns: [t("finance.ledger.type"), t("common.status"), t("finance.ledger.amount")], moneyCols: [2], rows: body });
        } else if (tab === "iraq") {
            tables.push({
                columns: [t("finance.ledger.source"), t("finance.ledger.net_sales"), t("finance.ledger.cogs"), t("finance.ledger.operating_expenses"), t("finance.ledger.gift_expenses"), t("finance.ledger.net_profit")],
                moneyCols: [1, 2, 3, 4, 5],
                rows: (["iraq_local", "qom_distributed", "shared", "combined"] as const).map((key) => {
                    const row = record(data[key]);
                    return { kind: key === "combined" ? "total" as const : "row" as const, values: [t(`finance.ledger.${key}`), amount(row.net_sales), amount(row.cogs), amount(row.operating_expenses), amount(row.gift_expenses), amount(row.net_profit ?? row.contribution_profit)] };
                }),
            });
        } else {
            const lots = rows(data.owned_lots);
            tables.push({
                columns: [t("finance.ledger.exportRow"), t("finance.ledger.book"), t("finance.ledger.branch"), t("finance.ledger.quantity"), t("finance.ledger.unitCost"), t("finance.ledger.value"), t("finance.ledger.source")],
                moneyCols: [4, 5],
                rows: [
                    ...lots.map((row, index) => ({ kind: "row" as const, values: [index + 1, String(row.book_title || `#${row.book_id || "—"}`), String(row.branch_name || `#${row.branch_id || "—"}`), amount(row.quantity), amount(row.unit_cost), amount(row.value), String(row.origin || "—")] })),
                    { kind: "total", values: ["", t("finance.ledger.totals"), "", lots.reduce((sum, row) => sum + amount(row.quantity), 0), null, amount(data.stock_lot_owned_inventory), ""] },
                ],
            });
            notes.unshift(t("finance.ledger.consignmentMemo"));
        }

        return {
            sheetName: active.label,
            company: t("common.appName"),
            title: `${t("finance.ledger.title")} — ${active.label}`,
            subtitle: active.description,
            meta,
            tables,
            notes,
        };
    }, [report, tab, currency, t, active, branchName, symbol, from, to, formatNumber]);

    const download = async () => {
        const book = workbook();
        if (!book) return;
        await downloadXlsx(book, `almanahel-${tab}-${currency}-${from}-${to}.xlsx`);
    };

    const print = () => {
        const book = workbook();
        if (!book) return;
        printWorkbook(book);
    };

    const reconciliation = report && "reconciled" in report ? <div className={cn("flex items-center gap-2 rounded-xl border px-3 py-2 text-[10px] font-black", report.reconciled ? "border-emerald-100 bg-emerald-50 text-emerald-700" : "border-rose-100 bg-rose-50 text-rose-700")}>{report.reconciled ? <CheckCircle2 className="h-4 w-4" /> : <AlertTriangle className="h-4 w-4" />}<span>{report.reconciled ? t("finance.ledger.reconciled") : t("finance.ledger.notReconciled")}</span>{!report.reconciled && <span>· {t("finance.ledger.difference")}: {formatNumber(amount(report.difference))} {symbol}</span>}</div> : null;

    return <Card className="overflow-hidden rounded-3xl border border-white/80 bg-white/75 shadow-[0_18px_70px_rgba(23,32,31,0.06)] backdrop-blur-xl">
        <CardHeader className="space-y-5 border-b border-ink/5 bg-gradient-to-bl from-primary/[0.055] via-white/60 to-amber-50/40 px-4 py-5 sm:px-6">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div className="flex items-start gap-3"><div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/20"><active.icon className="h-5 w-5" /></div><div><CardTitle className="text-base font-black font-vazirmatn">{t("finance.ledger.title")}</CardTitle><p className="mt-1 max-w-2xl text-[10px] font-bold leading-5 text-ink/45">{active.description}</p></div></div><div className="flex flex-wrap gap-2"><button type="button" onClick={download} disabled={!report || loading} className="flex h-9 items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-[10px] font-black text-emerald-700 disabled:opacity-40"><Download className="h-3.5 w-3.5" />{t("finance.ledger.exportExcel")}</button><button type="button" onClick={print} disabled={!report || loading} className="flex h-9 items-center gap-1.5 rounded-xl border border-ink/10 bg-white px-3 text-[10px] font-black text-ink/60 disabled:opacity-40"><Printer className="h-3.5 w-3.5" />{t("finance.ledger.printPdf")}</button><button type="button" onClick={load} disabled={loading} className="flex h-9 w-9 items-center justify-center rounded-xl border border-ink/10 bg-white text-ink/45 disabled:opacity-40"><RefreshCw className={cn("h-3.5 w-3.5", loading && "animate-spin")} /></button></div></div>
            <div className={cn("grid gap-3 rounded-2xl border border-white bg-white/75 p-3 shadow-sm sm:grid-cols-2", aggregate ? "lg:grid-cols-4" : "lg:grid-cols-3")}><Filter label={t("finance.ledger.currency")}><select value={currency} onChange={(event) => setCurrency(event.target.value as Currency)}><option value="toman">{t("finance.branchProfit.currencyToman")}</option><option value="dinar">{t("finance.branchProfit.currencyDinar")}</option></select></Filter>{aggregate && <Filter label={t("finance.ledger.branch")}><select value={branchId} onChange={(event) => setBranchId(event.target.value)}><option value="">{t("finance.ledger.allBranches")}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></Filter>}<Filter label={t("finance.ledger.from")}><input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></Filter><Filter label={t("finance.ledger.to")}><input type="date" value={to} onChange={(event) => setTo(event.target.value)} /></Filter></div>
            <div className="flex flex-wrap items-center justify-between gap-2"><div className="flex flex-wrap items-center gap-1.5"><span className="flex items-center gap-1 text-[9px] font-bold text-ink/35"><CalendarRange className="h-3 w-3" />{t("finance.ledger.quickPeriod")}</span>{(["today", "month", "previous"] as const).map((item) => <button key={item} onClick={() => preset(item)} className="rounded-lg bg-ink/5 px-2.5 py-1 text-[9px] font-black text-ink/55 hover:bg-primary/10 hover:text-primary">{t(`finance.ledger.presets.${item}`)}</button>)}</div>{updated && <p className="text-[9px] font-bold text-ink/30">{t("finance.ledger.lastUpdated")}: {updated.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</p>}</div>
            <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-5 lg:grid-cols-9">{tabs.map((item) => <button key={item.id} onClick={() => setTab(item.id)} className={cn("flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border px-2 py-2 transition", tab === item.id ? "border-primary bg-primary text-white shadow-lg shadow-primary/15" : "border-white bg-white/70 text-ink/55 hover:text-primary")}><item.icon className="h-4 w-4" /><span className="text-[9px] font-black">{item.label}</span></button>)}</div>
        </CardHeader>
        <CardContent className="p-4 font-vazirmatn sm:p-6">{loading ? <Loading /> : error ? <div className="rounded-2xl border border-rose-100 bg-rose-50 p-5 text-[11px] font-black text-rose-700">{error}</div> : !report ? <Empty label={t("finance.settlement.noData")} /> : <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-sm font-black">{active.label}</h3><p className="mt-1 text-[9px] font-bold text-ink/35">{branchName} · {from} — {to} · {symbol}</p></div>{reconciliation}</div><ReportBody tab={tab} data={report} currency={currency} symbol={symbol} t={t} format={formatNumber} /></div>}</CardContent>
    </Card>;
}

function Filter({ label, children }: { label: string; children: React.ReactElement<{ className?: string }> }) {
    return <label className="space-y-1.5 text-[9px] font-black text-ink/40"><span>{label}</span>{React.cloneElement(children, { className: "h-10 w-full rounded-xl border border-ink/10 bg-white px-3 text-[11px] font-black text-ink outline-none focus:border-primary/30" })}</label>;
}

function Loading() { return <div className="space-y-3"><div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <div key={index} className="h-24 animate-pulse rounded-2xl bg-ink/5" />)}</div><div className="h-56 animate-pulse rounded-2xl bg-ink/[0.035]" /></div>; }

function ReportBody({ tab, data, currency, symbol, t, format }: { tab: Tab; data: Row; currency: Currency; symbol: string; t: (key: string) => string; format: (value: number) => string }) {
    if (tab === "pnl") {
        const details = ["gross_sales", "sales_returns", "net_sales", "cogs", "gross_profit", "operating_expenses", "gift_expenses", "net_profit"];
        const cards = [["net_sales", data.net_sales, "text-sky-700 bg-sky-50 border-sky-100"], ["gross_profit", data.gross_profit, "text-emerald-700 bg-emerald-50 border-emerald-100"], ["totalExpenses", amount(data.operating_expenses) + amount(data.gift_expenses), "text-amber-700 bg-amber-50 border-amber-100"], ["net_profit", data.net_profit, amount(data.net_profit) < 0 ? "text-rose-700 bg-rose-50 border-rose-100" : "text-primary bg-primary/[0.05] border-primary/15"]] as const;
        return <div className="space-y-4"><div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(([key, value, style]) => <div key={key} className={cn("rounded-2xl border p-4", style)}><p className="text-[9px] font-black opacity-65">{t(`finance.ledger.${key}`)}</p><p className="mt-2 text-lg font-black"><Money value={value} symbol={symbol} format={format} /></p></div>)}</div><div className="overflow-hidden rounded-2xl border border-ink/5">{details.map((key) => <div key={key} className={cn("flex justify-between border-b border-ink/5 px-4 py-3 text-[11px] last:border-0", key === "net_profit" && "bg-primary/[0.035]")}><span className="font-bold text-ink/55">{t(`finance.ledger.${key}`)}</span><Money value={data[key === "cogs" ? "net_cogs" : key]} symbol={symbol} format={format} bold /></div>)}</div><p className="rounded-xl bg-ink/[0.025] px-3 py-2 text-[9px] font-bold text-ink/40">{t("finance.ledger.pnlEquation")}</p></div>;
    }
    if (tab === "treasury") {
        const accounts = rows(data.accounts).filter((row) => row.currency === currency);
        if (!accounts.length) return <Empty label={t("finance.settlement.noData")} />;
        const sum = accounts.reduce<{ opening: number; debit: number; credit: number; closing: number }>((result, row) => ({ opening: result.opening + amount(row.opening_balance), debit: result.debit + amount(row.period_debit), credit: result.credit + amount(row.period_credit), closing: result.closing + amount(row.closing_balance) }), { opening: 0, debit: 0, credit: 0, closing: 0 });
        return <div className="space-y-4"><Summary values={[["opening", sum.opening], ["cashIn", sum.debit], ["cashOut", sum.credit], ["closing", sum.closing]]} symbol={symbol} t={t} format={format} /><Table headers={[t("finance.ledger.account"), t("finance.ledger.opening"), t("finance.ledger.debit"), t("finance.ledger.credit"), t("finance.ledger.closing")]} data={accounts.map((row) => [<div key="title"><b>{treasuryTitle(t, row)}</b><p className="text-[9px] text-ink/35">{typeLabel(t, String(row.type || ""))}{row.branch_id == null ? ` · ${t("finance.ledger.corporate")}` : ""}</p></div>, format(amount(row.opening_balance)), format(amount(row.period_debit)), format(amount(row.period_credit)), <Money key="close" value={row.closing_balance} format={format} bold />])} /></div>;
    }
    if (tab === "trial") {
        const accounts = rows(data.accounts); if (!accounts.length) return <Empty label={t("finance.settlement.noData")} />;
        return <div className="space-y-4"><div className={cn("rounded-2xl border p-4 text-[11px] font-black", data.balanced ? "border-emerald-100 bg-emerald-50 text-emerald-700" : "border-rose-100 bg-rose-50 text-rose-700")}>{t("finance.ledger.balanced")}: {data.balanced ? t("common.yes") : t("common.no")} <span className="ms-3 text-ink/50">{t("finance.ledger.debit")}: {format(amount(data.total_debits))} · {t("finance.ledger.credit")}: {format(amount(data.total_credits))}</span></div><Table headers={[t("finance.ledger.account"), t("finance.ledger.opening"), t("finance.ledger.debit"), t("finance.ledger.credit"), t("finance.ledger.closing")]} data={accounts.map((row) => { const close = closingBalance(row); const subtitle = accountSubtitle(t, row); return [<div key="account"><b>{accountTitle(t, row)}</b>{subtitle ? <p className="text-[9px] text-ink/30">{subtitle}</p> : null}</div>, format(Math.abs(amount(row.opening_net))), format(amount(row.period_debit)), format(amount(row.period_credit)), <div key="close"><b>{format(close.value)}</b><p className="text-[8px] text-ink/35">{t(`finance.ledger.${close.side}`)}</p></div>]; })} /></div>;
    }
    if (tab === "position") return <div className="space-y-4"><div className="grid gap-3 md:grid-cols-3">{(["assets", "liabilities", "equity"] as const).map((group) => <div key={group} className="rounded-2xl border border-ink/5 p-4"><p className="mb-3 text-[11px] font-black text-primary">{t(`finance.ledger.${group}`)}</p>{Object.entries(record(data[group])).map(([key, value]) => <div key={key} className="flex justify-between gap-2 py-1 text-[10px]"><span className="text-ink/50">{typeLabel(t, key)}</span><Money value={value} format={format} bold /></div>)}</div>)}</div><Summary values={[["totalAssets", data.total_assets], ["totalLiabilities", data.total_liabilities], ["liabilitiesAndEquity", data.total_liabilities_and_equity]]} symbol={symbol} t={t} format={format} /><p className="rounded-xl bg-ink/[0.025] px-3 py-2 text-[9px] text-ink/40">{t("finance.ledger.positionEquation")}</p></div>;
    if (tab === "ar" || tab === "ap") {
        const list = rows(tab === "ar" ? data.customers : data.suppliers); const opening = record(data.opening); const movement = record(data.period_movement); const closing = record(data.closing);
        return <div className="space-y-4"><Summary values={[["opening", opening.ledger], ["periodMovement", movement.ledger], ["closing", closing.ledger], ["operationalAsOf", data.operational_as_of]]} symbol={symbol} t={t} format={format} />{!list.length ? <Empty label={t("finance.settlement.noData")} /> : tab === "ar" ? <Table headers={[t("finance.ledger.customer"), t("finance.ledger.openAr"), t("finance.ledger.overdue"), t("finance.ledger.customerCredit"), t("finance.ledger.netBalance"), t("finance.ledger.invoiceCount")]} data={list.map((row) => [String(row.customer_name || `#${row.customer_id || "—"}`), format(amount(row.open_ar)), <span key="over" className={amount(row.overdue_ar) > 0 ? "font-black text-rose-600" : "text-ink/30"}>{format(amount(row.overdue_ar))}</span>, format(amount(row.customer_credit_liability)), format(amount(row.net_position)), format(rows(row.invoices).length)])} /> : <Table headers={[t("finance.ledger.supplier"), t("finance.ledger.branch"), t("finance.ledger.supplierPayable"), t("finance.ledger.checkPayable"), t("finance.ledger.supplierRecoverable"), t("finance.ledger.operational")]} data={list.map((row) => [String(row.supplier_name || `#${row.supplier_id || "—"}`), String(row.branch_name || "—"), format(amount(row.supplier_payable)), format(amount(row.checks_payable)), format(amount(row.supplier_recoverable)), format(amount(row.operational_payable))])} />}</div>;
    }
    if (tab === "checks") return <div className="grid gap-4 lg:grid-cols-2">{(["incoming", "outgoing"] as const).map((direction) => <div key={direction} className="rounded-2xl border border-ink/5 p-4"><p className="mb-4 text-[11px] font-black">{t(`finance.ledger.${direction}`)}</p><div className="grid grid-cols-2 gap-2">{Object.entries(record(data[`${direction}_historical`] || data[direction])).map(([status, value]) => <div key={status} className="rounded-xl bg-ink/[0.025] p-3"><p className="text-[9px] text-ink/40">{t(`finance.ledger.statuses.${status}`)}</p><Money value={value} symbol={symbol} format={format} bold /></div>)}</div><p className="mt-3 border-t border-ink/5 pt-3 text-[10px]">{t("finance.ledger.ledgerBalance")}: <Money value={data[direction === "incoming" ? "ledger_checks_receivable" : "ledger_checks_payable"]} symbol={symbol} format={format} bold /></p></div>)}</div>;
    if (tab === "iraq") return <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">{(["iraq_local", "qom_distributed", "shared", "combined"] as const).map((key) => { const item = record(data[key]); return <div key={key} className={cn("rounded-2xl border p-4", key === "combined" ? "border-primary/15 bg-primary/[0.04]" : "border-ink/5")}><p className="mb-3 text-[11px] font-black">{t(`finance.ledger.${key}`)}</p>{[["net_sales", item.net_sales], ["cogs", item.cogs], ["operating_expenses", item.operating_expenses], ["net_profit", item.net_profit || item.contribution_profit]].map(([label, value]) => <div key={String(label)} className="flex justify-between py-1 text-[10px]"><span className="text-ink/45">{t(`finance.ledger.${label}`)}</span><Money value={value} symbol={symbol} format={format} bold /></div>)}</div>; })}</div>;
    const lots = rows(data.owned_lots); const consignment = rows(data.consignment_memorandum).reduce((total, row) => total + amount(row.quantity), 0);
    return <div className="space-y-4"><Summary values={[["lotValue", data.stock_lot_owned_inventory], ["ledgerValue", data.ledger_owned_inventory], ["consignmentQuantity", consignment]]} symbol={symbol} t={t} format={format} noSymbolLast />{!lots.length ? <Empty label={t("finance.settlement.noData")} /> : <Table headers={[t("finance.ledger.book"), t("finance.ledger.branch"), t("finance.ledger.quantity"), t("finance.ledger.unitCost"), t("finance.ledger.value"), t("finance.ledger.source")]} data={lots.map((row) => [String(row.book_title || `#${row.book_id || "—"}`), String(row.branch_name || `#${row.branch_id || "—"}`), format(amount(row.quantity)), format(amount(row.unit_cost)), format(amount(row.value)), String(row.origin || "—")])} />}<p className="rounded-xl bg-amber-50 px-3 py-2 text-[9px] font-bold text-amber-700">{t("finance.ledger.consignmentMemo")}</p></div>;
}

function Summary({ values, symbol, t, format, noSymbolLast = false }: { values: [string, unknown][]; symbol: string; t: (key: string) => string; format: (value: number) => string; noSymbolLast?: boolean }) {
    return <div className={cn("grid gap-3", values.length === 3 ? "sm:grid-cols-3" : "grid-cols-2 lg:grid-cols-4")}>{values.map(([key, value], index) => <div key={key} className="rounded-2xl border border-ink/5 bg-ink/[0.018] p-3"><p className="text-[9px] font-black text-ink/40">{t(`finance.ledger.${key}`)}</p><p className="mt-1 text-base font-black"><Money value={value} symbol={noSymbolLast && index === values.length - 1 ? undefined : symbol} format={format} /></p></div>)}</div>;
}
