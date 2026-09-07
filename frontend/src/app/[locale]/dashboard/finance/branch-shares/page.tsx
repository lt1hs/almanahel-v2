"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, Building2, Check, CheckCircle2, Percent, RefreshCw, Search, ShieldCheck } from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { RequireRole } from "@/components/auth/RequireRole";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest, ApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { localDateTimeInputValue, localDateTimeToIso } from "@/lib/localDateTime";

type Currency = "toman" | "dinar";
type Scope = "all_branches" | "selected_branches";

interface BranchOption { id: number; name: string; city?: string | null; rate?: string | null }
interface Totals {
    gross_sales: string; discount: string; net_sales: string;
    gross_share: string; share_returns: string; net_share: string; rates_used: string[];
}
interface PreviewBranch {
    branch_id: number | null; branch_name: string; previous_rate: string | null;
    new_rate: string; overlap: boolean; closes_previous: boolean;
}
interface Preview {
    preview_hash: string; new_rule_count: number; past_sales_unchanged: boolean;
    has_overlap: boolean; warnings: string[]; branches: PreviewBranch[]; note?: string; rate: string; effective_from: string;
}
interface HistoryRow {
    id: number; branch_name: string; previous_rate: string | null; new_rate: string;
    effective_from: string | null; created_by?: string | null; reason?: string | null;
}
interface InvoiceRow {
    invoice_id: number; invoice_item_id: number; invoice_number?: string; currency: string; net_sales_amount: string;
    rate: string; share_amount: string; share_returns: string; net_share: string;
}

const inputClass = "h-12 w-full rounded-xl border border-ink/10 bg-white/80 px-4 text-sm font-vazirmatn outline-none transition focus:border-primary/30 focus:ring-4 focus:ring-primary/[0.07]";

function moneyLabel(value: string | undefined, formatNumber: (n: number) => string) {
    return formatNumber(Number(value ?? 0));
}

export default function BranchSharesPage() {
    const { t, formatNumber, preferredCurrency } = useTranslation();
    const { user } = useAuth();
    const notify = useNotify();
    const notifyRef = useRef(notify);
    notifyRef.current = notify;
    const isAdmin = user?.role === "admin" || user?.role === "super_admin";
    const [currency, setCurrency] = useState<Currency>(preferredCurrency);
    const [scope, setScope] = useState<Scope>("all_branches");
    const [selected, setSelected] = useState<number[]>(user?.branch_id ? [user.branch_id] : []);
    const [rate, setRate] = useState("10.00");
    const [reason, setReason] = useState("");
    const [effectiveFrom, setEffectiveFrom] = useState(() => localDateTimeInputValue());
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
    const [enabled, setEnabled] = useState(false);
    const [totals, setTotals] = useState<Record<Currency, Totals> | null>(null);
    const [rules, setRules] = useState<BranchOption[]>([]);
    const [history, setHistory] = useState<HistoryRow[]>([]);
    const [invoices, setInvoices] = useState<InvoiceRow[]>([]);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [ready, setReady] = useState(false);
    usePageReady(ready);

    const load = useCallback(async () => {
        const lite = await apiRequest("/branches?lite=1").catch(() => []);
        const liteRows: Array<{ id: number; name: string; city?: string; type?: string; status?: string }> = Array.isArray(lite) ? lite : [];
        const stores = liteRows.filter((row) => row.status !== "inactive" && (row.type === "store" || !row.type));

        const [summary, ruleData, hist, inv] = await Promise.all([
            apiRequest("/branch-sales-shares/summary").catch(() => null),
            apiRequest("/branch-sales-shares/rules").catch(() => null),
            apiRequest("/branch-sales-shares/history").catch(() => null),
            apiRequest("/branch-sales-shares/invoices").catch(() => null),
        ]);
        const rateById = new Map<number, string | null>(
            (ruleData?.branches ?? []).map((row: { branch_id: number; rate: string | null }) => [row.branch_id, row.rate])
        );
        const fromRules: BranchOption[] = (ruleData?.branches ?? []).map((row: { branch_id: number; branch_name: string; rate: string | null }) => ({
            id: row.branch_id, name: row.branch_name, rate: row.rate,
        }));
        const source: BranchOption[] = stores.length
            ? stores.map((row) => ({ id: row.id, name: row.name, city: row.city ?? null }))
            : fromRules;
        const merged: BranchOption[] = source.map((row) => ({
            id: row.id,
            name: row.name,
            city: row.city,
            rate: rateById.get(row.id) ?? row.rate ?? null,
        }));
        setRules(merged);
        if (summary) {
            setEnabled(Boolean(summary.enabled));
            setTotals(summary.totals ?? null);
        }
        setHistory(hist?.history ?? []);
        setInvoices(inv?.invoices ?? []);
        if (!summary && !ruleData && !hist && !inv && merged.length === 0) {
            notifyRef.current.error("branchShares.loadError");
        }
        setReady(true);
    }, []);

    useEffect(() => { void load(); }, [load]);
    useEffect(() => { setPreview(null); }, [scope, selected, rate, reason, effectiveFrom]);

    const currentTotals = totals?.[currency];
    const payload = useMemo(() => {
        const body: Record<string, unknown> = {
            scope,
            rate,
            effective_from: localDateTimeToIso(effectiveFrom),
            reason: reason.trim(),
            idempotency_key: idempotencyKey,
        };
        if (scope === "selected_branches") body.branch_ids = selected;
        return body;
    }, [scope, rate, effectiveFrom, reason, idempotencyKey, selected]);

    const runPreview = async () => {
        if (!rate.trim()) return notify.error("branchShares.rateRequired");
        if (!reason.trim()) return notify.error("branchShares.reasonRequired");
        if (scope === "selected_branches" && !selected.length) return notify.error("branchShares.branchRequired");
        setLoading(true);
        try {
            setPreview(await apiRequest("/branch-sales-shares/preview", { method: "POST", body: JSON.stringify(payload) }));
            notify.success("branchShares.previewReady");
        } catch (error) {
            notify.rawError(error instanceof Error ? error.message : t("common.error"));
        } finally {
            setLoading(false);
        }
    };

    const runApply = async () => {
        if (!preview) return;
        setLoading(true);
        try {
            await apiRequest("/branch-sales-shares", { method: "POST", body: JSON.stringify({ ...payload, preview_hash: preview.preview_hash }) });
            notify.success("branchShares.applied");
            setPreview(null);
            setReason("");
            setIdempotencyKey(crypto.randomUUID());
            await load();
        } catch (error) {
            if (error instanceof ApiError && error.status === 409) {
                notify.error("branchShares.stale");
                setPreview(null);
            } else {
                notify.rawError(error instanceof Error ? error.message : t("common.error"));
            }
        } finally {
            setLoading(false);
        }
    };

    const toggleEnabled = async () => {
        setToggling(true);
        try {
            const next = !enabled;
            const result = await apiRequest("/branch-sales-shares/enabled", {
                method: "PUT",
                body: JSON.stringify({ enabled: next }),
            });
            setEnabled(Boolean(result?.enabled));
            notify.success(next ? "branchShares.enabledOk" : "branchShares.disabledOk");
        } catch (error) {
            notify.rawError(error instanceof Error ? error.message : t("common.error"));
        } finally {
            setToggling(false);
        }
    };

    return (
        <RequireRole roles={["super_admin", "admin"]}>
            <div className="mx-auto max-w-[1400px] space-y-6 pb-16">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="flex items-center gap-2.5 font-vazirmatn text-xl font-black text-ink">
                            <span className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/10 bg-primary/10">
                                <Percent className="h-4 w-4 text-primary" />
                            </span>
                            {t("branchShares.title")}
                        </h1>
                        <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
                            {t("branchShares.subtitle")}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={enabled ? "success" : "outline"}>{enabled ? t("branchShares.enabled") : t("branchShares.disabled")}</Badge>
                        <div className="inline-flex rounded-xl border border-ink/10 bg-parchment/60 p-1">
                            {(["toman", "dinar"] as Currency[]).map((item) => (
                                <button key={item} onClick={() => setCurrency(item)} className={cn("min-w-20 rounded-lg px-3 py-1.5 text-xs font-black font-vazirmatn", currency === item ? "bg-white text-primary shadow-sm" : "text-ink/40")}>
                                    {item === "toman" ? t("common.toman") : t("common.dinar")}
                                </button>
                            ))}
                        </div>
                        <Button variant="ghost" onClick={() => void load()} className="h-10 w-10 p-0"><RefreshCw className="h-4 w-4" /></Button>
                    </div>
                </div>

                <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[11px] leading-6 text-amber-900 font-vazirmatn">
                    {t("branchShares.modeNote")}
                </p>
                {!enabled && (
                    <div className="flex flex-col gap-3 rounded-xl border border-ink/10 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-[11px] leading-6 text-ink/55 font-vazirmatn">{t("branchShares.flagOff")}</p>
                        {isAdmin && (
                            <Button onClick={() => void toggleEnabled()} isLoading={toggling} className="h-10 shrink-0 text-xs">
                                {t("branchShares.enableAction")}
                            </Button>
                        )}
                    </div>
                )}
                {enabled && isAdmin && (
                    <div className="flex justify-end">
                        <button type="button" onClick={() => void toggleEnabled()} className="text-[10px] font-black text-ink/35 hover:text-ink/60">
                            {t("branchShares.disableAction")}
                        </button>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi label={t("branchShares.netSales")} value={moneyLabel(currentTotals?.net_sales, formatNumber)} />
                    <Kpi label={t("branchShares.netShare")} value={moneyLabel(currentTotals?.net_share, formatNumber)} />
                    <Kpi label={t("branchShares.shareReturns")} value={moneyLabel(currentTotals?.share_returns, formatNumber)} />
                    <Kpi label={t("branchShares.grossShare")} value={moneyLabel(currentTotals?.gross_share, formatNumber)} />
                </div>

                {isAdmin && (
                    <Card className="border-ink/[0.07] bg-white/80">
                        <CardContent className="space-y-4 p-5 sm:p-6">
                            <h2 className="font-vazirmatn text-sm font-black">{t("branchShares.setRate")}</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Choice active={scope === "all_branches"} title={t("branchShares.allBranches")} onClick={() => setScope("all_branches")} />
                                <Choice active={scope === "selected_branches"} title={t("branchShares.selectedBranches")} onClick={() => setScope("selected_branches")} />
                            </div>
                            {scope === "selected_branches" && (
                                <div className="space-y-3 rounded-xl border border-ink/10 bg-parchment/40 p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-[11px] font-vazirmatn text-ink/55">{t("branchShares.pickBranches")}</p>
                                        {rules.length > 0 && (
                                            <button type="button" onClick={() => setSelected(selected.length === rules.length ? [] : rules.map((row) => row.id))} className="text-[10px] font-black text-primary">
                                                {selected.length === rules.length ? t("branchShares.clearAll") : t("branchShares.selectAll")}
                                            </button>
                                        )}
                                    </div>
                                    {rules.length === 0 ? (
                                        <p className="py-6 text-center text-xs text-ink/40">{t("branchShares.noBranches")}</p>
                                    ) : (
                                        <div className="grid gap-2 md:grid-cols-2">
                                            {rules.map((branch) => {
                                                const checked = selected.includes(branch.id);
                                                return (
                                                    <button type="button" key={branch.id} onClick={() => setSelected((old) => old.includes(branch.id) ? old.filter((id) => id !== branch.id) : [...old, branch.id])} className={cn("flex items-center justify-between rounded-xl border p-3 text-start", checked ? "border-primary/30 bg-white ring-2 ring-primary/[0.08]" : "border-ink/[0.08] bg-white")}>
                                                        <span className="flex items-center gap-2">
                                                            <span className={cn("flex h-5 w-5 items-center justify-center rounded-md border", checked && "border-primary bg-primary text-white")}>{checked && <Check className="h-3 w-3" />}</span>
                                                            <span>
                                                                <b className="block text-xs font-vazirmatn">{branch.name}</b>
                                                                {branch.city && <small className="text-[10px] text-ink/35">{branch.city}</small>}
                                                            </span>
                                                        </span>
                                                        <small className="text-[10px] text-ink/40">{t("branchShares.currentRate")}: {branch.rate ?? "—"}%</small>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label className="mb-2 block text-[10px] font-black text-ink/55">{t("branchShares.rate")}</label>
                                    <input className={inputClass} value={rate} onChange={(e) => setRate(e.target.value.replace(/[^\d.]/g, ""))} inputMode="decimal" />
                                </div>
                                <div>
                                    <label className="mb-2 block text-[10px] font-black text-ink/55">{t("branchShares.effectiveFrom")}</label>
                                    <input className={inputClass} type="datetime-local" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
                                </div>
                                <div>
                                    <label className="mb-2 block text-[10px] font-black text-ink/55">{t("branchShares.reason")}</label>
                                    <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
                                </div>
                            </div>
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p className="flex items-center gap-2 text-[10px] text-ink/45"><ShieldCheck className="h-4 w-4 text-emerald-600" />{t("branchShares.pastUnchanged")}</p>
                                <Button onClick={() => void runPreview()} isLoading={loading} className="gap-2"><Search className="h-4 w-4" />{t("branchShares.preview")}</Button>
                            </div>
                            {preview && (
                                <div className="space-y-3 rounded-xl border border-primary/15 bg-primary/[0.03] p-4">
                                    <div className="flex items-center justify-between">
                                        <b className="text-xs font-vazirmatn">{t("branchShares.newRules")}: {preview.new_rule_count}</b>
                                        {preview.has_overlap ? <Badge variant="outline">{t("branchShares.overlap")}</Badge> : <Badge variant="success">{t("branchShares.pastUnchanged")}</Badge>}
                                    </div>
                                    {preview.warnings?.map((warning) => (
                                        <p key={warning} className="flex gap-2 text-[10px] text-amber-800"><AlertTriangle className="h-4 w-4" />{warning}</p>
                                    ))}
                                    {preview.branches.map((row) => (
                                        <div key={`${row.branch_id ?? "all"}`} className="flex justify-between rounded-lg bg-white/80 px-3 py-2 text-[11px]">
                                            <span>{row.branch_name}</span>
                                            <span>{row.previous_rate ?? "—"}% → <b className="text-primary">{row.new_rate}%</b></span>
                                        </div>
                                    ))}
                                    <Button onClick={() => void runApply()} isLoading={loading} disabled={preview.has_overlap} className="w-full gap-2 bg-emerald-700">
                                        <CheckCircle2 className="h-4 w-4" />{t("branchShares.apply")}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card className="border-ink/[0.07] bg-white/80">
                    <CardContent className="p-5 sm:p-6">
                        <h2 className="mb-4 font-vazirmatn text-sm font-black">{t("branchShares.history")}</h2>
                        {history.length === 0 ? <p className="py-6 text-center text-xs text-ink/40">{t("branchShares.noHistory")}</p> : (
                            <div className="space-y-2">
                                {history.map((row) => (
                                    <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-ink/5 p-3 text-[11px]">
                                        <span className="flex items-center gap-2"><Building2 className="h-3.5 w-3.5 text-ink/30" /><b>{row.branch_name}</b></span>
                                        <span>{row.previous_rate ?? "—"}% → <b className="text-primary">{row.new_rate}%</b></span>
                                        <small className="text-ink/40">{row.effective_from ?? ""}</small>
                                        <small className="text-ink/40">{row.created_by ?? ""}</small>
                                        <small className="w-full text-ink/35">{row.reason}</small>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="border-ink/[0.07] bg-white/80">
                    <CardContent className="p-5 sm:p-6">
                        <h2 className="mb-4 font-vazirmatn text-sm font-black">{t("branchShares.details")}</h2>
                        {invoices.filter((row) => row.currency === currency).length === 0 ? <p className="py-6 text-center text-xs text-ink/40">{t("branchShares.noRows")}</p> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px] text-start text-[11px]">
                                    <thead className="text-ink/40">
                                        <tr>
                                            <th className="p-2 font-vazirmatn">{t("branchShares.invoice")}</th>
                                            <th className="p-2">{t("branchShares.netSales")}</th>
                                            <th className="p-2">{t("branchShares.snapshotRate")}</th>
                                            <th className="p-2">{t("branchShares.createdShare")}</th>
                                            <th className="p-2">{t("branchShares.shareReturns")}</th>
                                            <th className="p-2">{t("branchShares.finalShare")}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.filter((row) => row.currency === currency).map((row) => (
                                            <tr key={row.invoice_item_id} className="border-t border-ink/5">
                                                <td className="p-2 font-vazirmatn">{row.invoice_number}</td>
                                                <td className="p-2">{moneyLabel(row.net_sales_amount, formatNumber)}</td>
                                                <td className="p-2">{row.rate}%</td>
                                                <td className="p-2">{moneyLabel(row.share_amount, formatNumber)}</td>
                                                <td className="p-2">{moneyLabel(row.share_returns, formatNumber)}</td>
                                                <td className="p-2 font-black text-primary">{moneyLabel(row.net_share, formatNumber)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </RequireRole>
    );
}

function Kpi({ label, value }: { label: string; value: string }) {
    return (
        <Card className="border-ink/[0.07] bg-white/80">
            <CardContent className="p-4">
                <p className="text-[10px] font-bold text-ink/40">{label}</p>
                <p className="mt-2 font-vazirmatn text-lg font-black text-primary">{value}</p>
            </CardContent>
        </Card>
    );
}

function Choice({ active, title, onClick }: { active: boolean; title: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className={cn("flex items-center justify-between rounded-xl border p-3 text-start text-xs font-vazirmatn", active ? "border-primary/30 bg-primary/[0.04] ring-2 ring-primary/[0.08]" : "border-ink/[0.08] bg-white")}>
            <span>{title}</span>
            {active && <Check className="h-4 w-4 text-primary" />}
        </button>
    );
}
