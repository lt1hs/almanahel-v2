"use client";

import { usePageReady } from "@/components/NavigationProgress";
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, Banknote, BookOpen, Building2, Check, CheckCircle2, ChevronDown, History, Landmark, RefreshCw, Search, ShieldCheck, Tag, X } from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { RequireRole } from "@/components/auth/RequireRole";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest, ApiError } from "@/lib/api";
import { formatPriceDisplay, parsePriceDigits } from "@/lib/bookFormUtils";
import { cn } from "@/lib/utils";

interface BookHit { id: number; title: string; author?: string; isbn?: string }
interface BranchRow {
    branch_id: number; branch_name: string; selling_price: string; price_version: number;
    consignment_cost: string | null; unsold_qty: number; reserved_qty: number;
    consignment_lots: number; can_set_pricing: boolean; supports_toman?: boolean; supports_dinar?: boolean;
}
interface PreviewRow { branch_id: number; old_price?: string; new_price?: string; old_cost?: string; new_cost?: string; margin_warning?: string | null }
interface Preview { preview_hash: string; applied: PreviewRow[]; rejected: Array<{ branch_id: number; reason: string }>; affected_lots?: number; unsold_qty?: number; reserved_qty?: number }
type PriceType = "selling_price" | "consignment_cost";
type Currency = "toman" | "dinar";
type Scope = "all_branches" | "selected_branches";

const inputClass = "h-12 w-full rounded-xl border border-ink/10 bg-white/80 px-4 text-sm font-vazirmatn outline-none transition focus:border-primary/30 focus:ring-4 focus:ring-primary/[0.07]";

export default function PricesPage() {
    const { t, formatNumber, preferredCurrency } = useTranslation();
    const { user } = useAuth();
    const notify = useNotify();
    usePageReady(true);
    const isAdmin = user?.role === "admin" || user?.role === "super_admin";
    const canMutate = isAdmin || user?.role === "branch_manager";
    const [query, setQuery] = useState("");
    const [hits, setHits] = useState<BookHit[]>([]);
    const [book, setBook] = useState<BookHit | null>(null);
    const [type, setType] = useState<PriceType>("selling_price");
    const [currency, setCurrency] = useState<Currency>(preferredCurrency);
    const [scope, setScope] = useState<Scope>(isAdmin ? "all_branches" : "selected_branches");
    const [selected, setSelected] = useState<number[]>(user?.branch_id ? [user.branch_id] : []);
    const [supplierAccountId, setSupplierAccountId] = useState("");
    const [accounts, setAccounts] = useState<Array<{ id: number; display_name?: string; supplier?: { name?: string } }>>([]);
    const [amount, setAmount] = useState("");
    const [reason, setReason] = useState("");
    const [rows, setRows] = useState<BranchRow[]>([]);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [history, setHistory] = useState<Array<Record<string, unknown>>>([]);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [searching, setSearching] = useState(false);
    const [currentLoading, setCurrentLoading] = useState(false);
    const [sellingEnabled, setSellingEnabled] = useState(false);
    const [consignmentEnabled, setConsignmentEnabled] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [overrides, setOverrides] = useState<Record<number, string>>({});
    const flagOn = type === "selling_price" ? sellingEnabled : consignmentEnabled;

    useEffect(() => {
        apiRequest("/price-changes/flags").then((data) => {
            setSellingEnabled(Boolean(data?.selling_price_versioning_enabled));
            setConsignmentEnabled(Boolean(data?.consignment_cost_revision_enabled));
        }).catch(() => {});
    }, []);

    useEffect(() => { if (!isAdmin && user?.branch_id) { setScope("selected_branches"); setSelected([user.branch_id]); } }, [isAdmin, user?.branch_id]);

    const searchBooks = useCallback(async (term: string) => {
        if (term.trim().length < 2) return setHits([]);
        setSearching(true);
        try {
            const suffix = user?.branch_id ? `&branch_id=${user.branch_id}` : "&aggregate=1";
            const data = await apiRequest(`/books?search=${encodeURIComponent(term.trim())}${suffix}`);
            setHits((Array.isArray(data) ? data : data?.books ?? data?.data ?? []).slice(0, 10));
        } catch { setHits([]); } finally { setSearching(false); }
    }, [user?.branch_id]);

    useEffect(() => {
        if (book || query.trim().length < 2) return;
        const timer = window.setTimeout(() => searchBooks(query), 350);
        return () => window.clearTimeout(timer);
    }, [book, query, searchBooks]);

    const loadCurrent = useCallback(async (bookId: number) => {
        setCurrentLoading(true);
        try {
            const params = new URLSearchParams({ book_id: String(bookId), currency, type });
            if (supplierAccountId) params.set("supplier_account_id", supplierAccountId);
            const [current, past] = await Promise.all([
                apiRequest(`/price-changes/current?${params}`),
                apiRequest(`/books/${bookId}/price-history?currency=${currency}`),
            ]);
            setRows(current.branches ?? []);
            setHistory([...(past.selling ?? []), ...(past.consignment ?? [])].sort((a, b) => Number(b.id) - Number(a.id)));
        } catch (error) { notify.rawError(error instanceof Error ? error.message : t("common.error")); }
        finally { setCurrentLoading(false); }
    }, [currency, notify, supplierAccountId, t, type]);

    useEffect(() => { if (book) loadCurrent(book.id); }, [book, loadCurrent]);
    const accountBranchId = scope === "selected_branches" ? selected[0] : rows.find((row) => row.can_set_pricing)?.branch_id;
    useEffect(() => {
        if (type !== "consignment_cost" || !isAdmin || !accountBranchId) return setAccounts([]);
        apiRequest(`/supplier-accounts?branch_id=${accountBranchId}`).then((data) => setAccounts(Array.isArray(data) ? data : data?.data ?? [])).catch(() => setAccounts([]));
    }, [accountBranchId, isAdmin, type]);
    useEffect(() => { setOverrides({}); }, [book?.id, type, currency]);
    useEffect(() => { setPreview(null); }, [book?.id, type, currency, scope, selected, supplierAccountId, amount, reason]);

    const eligible = useCallback((row: BranchRow) => row.can_set_pricing && (currency === "dinar" ? row.supports_dinar !== false : row.supports_toman !== false), [currency]);
    const eligibleRows = useMemo(() => rows.filter(eligible), [eligible, rows]);
    const targetCount = scope === "all_branches" ? eligibleRows.length : selected.length;
    const currencyLabel = currency === "dinar" ? t("common.dinar") : t("common.toman");

    const buildPayload = () => {
        if (!book) return null;
        const body: Record<string, unknown> = { type, book_id: book.id, currency, scope, reason: reason.trim(), idempotency_key: crypto.randomUUID() };
        if (scope === "selected_branches") body.branch_ids = selected;
        if (type === "selling_price") body.new_price = Number(parsePriceDigits(amount));
        else { body.new_cost = Number(parsePriceDigits(amount)); body.supplier_account_id = Number(supplierAccountId); }
        const branchOverrides = Object.entries(overrides)
            .filter(([, value]) => Number(parsePriceDigits(value)) > 0)
            .filter(([id]) => scope === "all_branches" || selected.includes(Number(id)))
            .map(([id, value]) => {
                const n = Number(parsePriceDigits(value));
                return type === "selling_price"
                    ? { branch_id: Number(id), new_price: n }
                    : { branch_id: Number(id), new_cost: n };
            });
        if (branchOverrides.length) body.branch_overrides = branchOverrides;
        return body;
    };
    const setBranchOverride = (branchId: number, raw: string) => {
        const digits = parsePriceDigits(raw);
        setOverrides((old) => {
            const next = { ...old };
            if (!digits) delete next[branchId];
            else next[branchId] = digits;
            return next;
        });
        if (scope === "selected_branches" && digits && !selected.includes(branchId)) {
            setSelected((old) => [...old, branchId]);
        }
    };
    const previewDirty = Boolean(preview && preview.applied.some((item) => {
        const shown = Math.trunc(Number(item.new_price ?? item.new_cost ?? 0));
        const override = overrides[item.branch_id];
        const expected = override ? Number(parsePriceDigits(override)) : Number(parsePriceDigits(amount) || shown);
        return expected !== shown;
    }));
    const runPreview = async () => {
        if (!book) return notify.error("prices.noBook");
        if (Number(parsePriceDigits(amount)) <= 0) return notify.error("prices.amountRequired");
        if (!reason.trim()) return notify.error("prices.reasonRequired");
        if (scope === "selected_branches" && !selected.length) return notify.error("prices.branchRequired");
        if (type === "consignment_cost" && !supplierAccountId) return notify.error("prices.selectSupplier");
        setLoading(true);
        try { setPreview(await apiRequest("/price-changes/preview", { method: "POST", body: JSON.stringify(buildPayload()) })); notify.success("prices.previewReady"); }
        catch (error) { notify.rawError(error instanceof Error ? error.message : t("common.error")); }
        finally { setLoading(false); }
    };
    const runApply = async () => {
        const body = buildPayload(); if (!body || !preview) return;
        setLoading(true);
        try {
            await apiRequest("/price-changes", { method: "POST", body: JSON.stringify({ ...body, preview_hash: preview.preview_hash }) });
            notify.success("prices.applied"); setPreview(null); setAmount(""); setReason(""); setOverrides({}); if (book) await loadCurrent(book.id);
        } catch (error) {
            if (error instanceof ApiError && error.status === 409) { notify.error("prices.stale"); setPreview(null); }
            else notify.rawError(error instanceof Error ? error.message : t("common.error"));
        } finally { setLoading(false); }
    };
    const toggleFlag = async (enabled: boolean) => {
        setToggling(true);
        try {
            const data = await apiRequest("/price-changes/flags", {
                method: "PUT",
                body: JSON.stringify({ type, enabled }),
            });
            setSellingEnabled(Boolean(data?.selling_price_versioning_enabled));
            setConsignmentEnabled(Boolean(data?.consignment_cost_revision_enabled));
            notify.success(enabled ? (type === "selling_price" ? "prices.sellingEnabledOk" : "prices.consignmentEnabledOk") : "prices.flagDisabledOk");
        } catch (error) {
            notify.rawError(error instanceof Error ? error.message : t("common.error"));
        } finally {
            setToggling(false);
        }
    };

    return <RequireRole roles={["super_admin", "admin"]}>
        <div className="mx-auto max-w-[1400px] space-y-6 pb-16">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink flex items-center gap-2.5">
                        <span className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/10 flex items-center justify-center">
                            <Tag className="w-4 h-4 text-primary" />
                        </span>
                        {t("prices.title")}
                    </h1>
                    <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
                        {t("prices.subtitle")}
                    </p>
                </div>
            </div>
            {!flagOn && (
                <div className="flex flex-col gap-3 rounded-xl border border-ink/10 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-[11px] leading-6 text-ink/55 font-vazirmatn">
                        {type === "selling_price" ? t("prices.sellingFlagOff") : t("prices.consignmentFlagOff")}
                    </p>
                    {isAdmin && (
                        <Button onClick={() => void toggleFlag(true)} isLoading={toggling} className="h-10 shrink-0 text-xs">
                            {type === "selling_price" ? t("prices.enableSelling") : t("prices.enableConsignment")}
                        </Button>
                    )}
                </div>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <main className="space-y-5">
                    <Section step="۱" title={t("prices.chooseBook")} help={t("prices.chooseBookHelp")} done={!!book}>
                        <div className="relative mt-4"><Search className="absolute end-4 top-4 h-4 w-4 text-ink/30" /><input className={cn(inputClass, "pe-11 ps-11")} value={query} onChange={(e) => { setQuery(e.target.value); if (book) setBook(null); }} placeholder={t("prices.searchPlaceholder")} />{query && <button onClick={() => { setQuery(""); setBook(null); setRows([]); }} className="absolute start-3 top-3 rounded-lg p-1.5 text-ink/35 hover:bg-ink/5"><X className="h-4 w-4" /></button>}
                            {(searching || hits.length > 0) && !book && <div className="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-xl border border-ink/10 bg-white shadow-xl">{searching ? <p className="p-5 text-center text-xs text-ink/45">{t("prices.searching")}</p> : hits.map((hit) => <button key={hit.id} onClick={() => { setBook(hit); setQuery(hit.title); setHits([]); }} className="flex w-full items-center gap-3 border-b border-ink/5 p-3 text-start hover:bg-primary/[0.04]"><span className="rounded-lg bg-primary/[0.07] p-2 text-primary"><BookOpen className="h-4 w-4" /></span><span><b className="block text-xs font-vazirmatn">{hit.title}</b><small className="text-[9px] text-ink/40">{[hit.author, hit.isbn].filter(Boolean).join(" • ")}</small></span></button>)}</div>}
                        </div>
                        {book && <div className="mt-3 flex items-center justify-between rounded-xl border border-primary/15 bg-primary/[0.04] p-3"><div className="min-w-0"><b className="block truncate text-sm font-vazirmatn">{book.title}</b><small className="text-[9px] text-ink/40">{[book.author, book.isbn].filter(Boolean).join(" • ")}</small></div><Badge variant="success">{t("prices.selected")}</Badge></div>}
                    </Section>

                    {book && canMutate && <>
                        <Section step="۲" title={t("prices.chooseChangeType")} help={t("prices.independentFlows")} done>
                            <div className="mt-4 grid gap-3 sm:grid-cols-2"><Choice active={type === "selling_price"} icon={<Banknote />} title={t("prices.selling")} help={t("prices.sellingHelp")} onClick={() => { setType("selling_price"); setSupplierAccountId(""); }} />{isAdmin && <Choice active={type === "consignment_cost"} icon={<Landmark />} title={t("prices.consignment")} help={t("prices.consignmentHelp")} onClick={() => setType("consignment_cost")} />}</div>
                            <div className="mt-4 inline-flex rounded-xl border border-ink/10 bg-parchment/60 p-1">{(["toman", "dinar"] as Currency[]).map((item) => <button key={item} onClick={() => setCurrency(item)} className={cn("min-w-24 rounded-lg px-4 py-2 text-xs font-black font-vazirmatn", currency === item ? "bg-white text-primary shadow-sm" : "text-ink/40")}>{item === "toman" ? t("common.toman") : t("common.dinar")}</button>)}</div>
                        </Section>

                        <Section step="۳" title={t("prices.chooseBranches")} help={t("prices.chooseBranchesHelp")} done={scope === "all_branches" || !!selected.length}>
                            {isAdmin && <div className="mt-4 grid gap-3 sm:grid-cols-2"><Choice compact active={scope === "all_branches"} icon={<Building2 />} title={t("prices.allBranches")} help={t("prices.allBranchesHelp")} onClick={() => setScope("all_branches")} /><Choice compact active={scope === "selected_branches"} icon={<CheckCircle2 />} title={t("prices.selectedBranches")} help={t("prices.selectedBranchesHelp")} onClick={() => setScope("selected_branches")} /></div>}
                            <div className="mt-5 flex justify-between"><b className="text-xs font-vazirmatn">{t("prices.branchStatus")}</b>{scope === "selected_branches" && isAdmin && <button onClick={() => setSelected(selected.length === eligibleRows.length ? [] : eligibleRows.map((r) => r.branch_id))} className="text-[10px] font-black text-primary">{selected.length === eligibleRows.length ? t("prices.clearAll") : t("prices.selectAll")}</button>}</div>
                            {currentLoading ? <div className="mt-3 flex h-28 items-center justify-center gap-2 rounded-xl border border-dashed border-ink/10 text-xs text-ink/40"><RefreshCw className="h-4 w-4 animate-spin" />{t("prices.loadingPrices")}</div> : <div className="mt-3 grid gap-3 md:grid-cols-2">{rows.map((row) => {
                                const ok = eligible(row);
                                const checked = scope === "all_branches" ? ok : selected.includes(row.branch_id);
                                return (
                                    <div key={row.branch_id} className={cn("rounded-xl border p-4 text-start", checked ? "border-primary/30 bg-primary/[0.04] ring-2 ring-primary/[0.06]" : "border-ink/[0.08] bg-white", !ok && "opacity-45")}>
                                        <button type="button" disabled={scope === "all_branches" || !isAdmin || !ok} onClick={() => setSelected((old) => old.includes(row.branch_id) ? old.filter((id) => id !== row.branch_id) : [...old, row.branch_id])} className="flex w-full gap-3 text-start">
                                            <span className={cn("flex h-5 w-5 items-center justify-center rounded-md border", checked && "border-primary bg-primary text-white")}>{checked && <Check className="h-3 w-3" />}</span>
                                            <div className="flex-1">
                                                <div className="flex justify-between"><b className="text-xs font-vazirmatn">{row.branch_name}</b><small className="text-ink/30">v{row.price_version}</small></div>
                                                <div className="mt-3 flex justify-between"><span className="text-sm font-black text-primary">{formatNumber(Number(type === "selling_price" ? row.selling_price : row.consignment_cost || 0))} <small className="text-[8px] text-ink/35">{currencyLabel}</small></span><small className="text-[9px] text-ink/40">{t("prices.unsold")}: {formatNumber(row.unsold_qty)}</small></div>
                                                {row.reserved_qty > 0 && <p className="mt-2 text-[9px] text-amber-700">{t("prices.reserved")}: {formatNumber(row.reserved_qty)}</p>}
                                            </div>
                                        </button>
                                        {ok && canMutate && (
                                            <label className="mt-3 block">
                                                <span className="mb-1 block text-[9px] font-black text-ink/40">{t("prices.branchOwnPrice")}</span>
                                                <input
                                                    className={cn(inputClass, "h-10 text-sm")}
                                                    inputMode="numeric"
                                                    value={formatPriceDisplay(overrides[row.branch_id] ?? "")}
                                                    placeholder={amount ? formatPriceDisplay(amount) : "—"}
                                                    onChange={(e) => setBranchOverride(row.branch_id, e.target.value)}
                                                />
                                            </label>
                                        )}
                                    </div>
                                );
                            })}</div>}
                        </Section>

                        <Section step="۴" title={t("prices.enterNewAmount")} help={t("prices.previewBeforeApply")} done={!!amount && !!reason.trim()}>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">{type === "consignment_cost" && <div className="sm:col-span-2"><Label>{t("prices.supplier")}</Label><FilterSelect className="w-full" value={supplierAccountId} onChange={setSupplierAccountId} options={[{ value: "", label: t("prices.selectSupplier") }, ...accounts.map((a) => ({ value: String(a.id), label: a.display_name || a.supplier?.name || `#${a.id}` }))]} /></div>}<div><Label>{type === "selling_price" ? t("prices.newPrice") : t("prices.newCost")}</Label><div className="relative"><input className={cn(inputClass, "ps-20 text-lg font-black")} inputMode="numeric" value={formatPriceDisplay(amount)} onChange={(e) => setAmount(parsePriceDigits(e.target.value))} placeholder="۰" /><span className="absolute start-4 top-4 text-[9px] font-bold text-ink/35">{currencyLabel}</span></div></div><div><Label>{t("prices.reason")}</Label><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t("prices.reasonPlaceholder")} maxLength={255} /></div></div>
                            <div className="mt-5 flex flex-col items-center justify-between gap-3 rounded-xl bg-parchment/50 p-3 sm:flex-row"><p className="flex gap-2 text-[10px] font-vazirmatn text-ink/45"><ShieldCheck className="h-4 w-4 text-emerald-600" />{t("prices.noAutoApply")}</p><Button onClick={runPreview} isLoading={loading} disabled={!flagOn || !amount || !reason.trim()} className="w-full gap-2 sm:w-auto"><Search className="h-4 w-4" />{t("prices.preview")}</Button></div>
                        </Section>
                    </>}

                    {book && <Card className="border-ink/[0.07] bg-white/80"><button onClick={() => setHistoryOpen(!historyOpen)} className="flex w-full items-center justify-between p-5"><span className="flex items-center gap-3"><History className="h-4 w-4 text-primary" /><b className="text-sm font-vazirmatn">{t("prices.history")}</b></span><span className="flex items-center gap-2"><Badge variant="outline">{formatNumber(history.length)}</Badge><ChevronDown className={cn("h-4 w-4", historyOpen && "rotate-180")} /></span></button>{historyOpen && <CardContent className="space-y-2 border-t border-ink/5 p-5">{history.length ? history.slice(0, 30).map((row) => <div key={`${row.id}`} className="flex justify-between rounded-xl border border-ink/5 p-3 text-[10px]"><span><b>{formatNumber(Number(row.old_price ?? row.old_cost ?? 0))} ← <i className="not-italic text-primary">{formatNumber(Number(row.new_price ?? row.new_cost ?? 0))}</i></b><small className="mt-1 block text-ink/40">{String(row.reason ?? "")}</small></span><Badge variant="outline">{String(row.currency ?? currency)}</Badge></div>) : <p className="py-5 text-center text-xs text-ink/40">{t("prices.noHistory")}</p>}</CardContent>}</Card>}
                </main>

                <aside className="space-y-4 xl:sticky xl:top-5"><Card className="overflow-hidden border-primary/15 bg-white shadow-lg"><div className="flex items-center justify-between border-b border-ink/5 bg-primary/[0.05] p-4"><b className="text-sm font-vazirmatn">{preview ? t("prices.previewResult") : t("prices.changeSummary")}</b><Badge variant={preview ? "success" : "outline"}>{preview ? t("prices.readyToApply") : t("prices.notApplied")}</Badge></div><CardContent className="p-5">{!book ? <Empty icon={<BookOpen />} title={t("prices.startWithBook")} text={t("prices.startWithBookHelp")} /> : !preview ? <div className="space-y-3"><Line label={t("prices.book")} value={book.title} /><Line label={t("prices.type")} value={type === "selling_price" ? t("prices.selling") : t("prices.consignment")} /><Line label={t("prices.currency")} value={currencyLabel} /><Line label={t("prices.targetBranches")} value={formatNumber(targetCount)} />{amount && <Line label={t("prices.newPrice")} value={`${formatNumber(Number(amount))} ${currencyLabel}`} />}<p className="rounded-xl border border-dashed border-ink/10 p-4 text-center text-[10px] leading-5 text-ink/40">{t("prices.previewHint")}</p></div> : <div className="space-y-4"><div className="grid grid-cols-3 gap-2"><Metric n={preview.applied.length} label={t("prices.branches")} /><Metric n={preview.unsold_qty ?? 0} label={t("prices.unsold")} /><Metric n={preview.affected_lots ?? 0} label={t("prices.lots")} /></div><div className="max-h-72 space-y-2 overflow-auto">{preview.applied.map((item) => { const name = rows.find((r) => r.branch_id === item.branch_id)?.branch_name ?? `#${item.branch_id}`; const old = item.old_price ?? item.old_cost ?? 0; const next = item.new_price ?? item.new_cost ?? 0; const own = overrides[item.branch_id] ?? String(Math.trunc(Number(next))); return <div key={item.branch_id} className="rounded-xl border border-ink/5 bg-parchment/40 p-3"><div className="flex justify-between"><b className="text-[10px] font-vazirmatn">{name}</b>{item.margin_warning && <AlertTriangle className="h-4 w-4 text-amber-600" />}</div><div className="mt-2 flex items-center justify-between gap-2 text-[11px]"><del className="text-ink/35">{formatNumber(Number(old))}</del><input className="h-8 w-32 rounded-lg border border-ink/10 bg-white px-2 text-end text-xs font-black text-primary" inputMode="numeric" value={formatPriceDisplay(own)} onChange={(e) => setBranchOverride(item.branch_id, e.target.value)} aria-label={name} /></div></div>; })}</div>{(preview.reserved_qty ?? 0) > 0 && <p className="flex gap-2 rounded-xl bg-amber-50 p-3 text-[9px] leading-5 text-amber-800"><AlertTriangle className="h-4 w-4 shrink-0" />{t("prices.reservedWarning")}</p>}<Button onClick={previewDirty ? runPreview : runApply} isLoading={loading} disabled={!previewDirty && (preview.reserved_qty ?? 0) > 0} className={cn("w-full gap-2", !previewDirty && "bg-emerald-700")}>{previewDirty ? <><Search className="h-4 w-4" />{t("prices.preview")}</> : <><CheckCircle2 className="h-4 w-4" />{t("prices.confirmApply")}</>}</Button><button onClick={() => setPreview(null)} className="w-full text-[10px] font-black text-ink/40">{t("prices.editChange")}</button></div>}</CardContent></Card>{book && <Card variant="ink"><CardContent className="p-5"><b className="flex gap-2 text-xs font-vazirmatn"><ShieldCheck className="h-4 w-4 text-accent" />{t("prices.safetyTitle")}</b><p className="mt-2 text-[10px] leading-5 text-parchment/60">{type === "selling_price" ? t("prices.sellingSafety") : t("prices.consignmentSafety")}</p></CardContent></Card>}</aside>
            </div>
        </div>
    </RequireRole>;
}

function Section({ step, title, help, done, children }: { step: string; title: string; help: string; done?: boolean; children: React.ReactNode }) { return <Card className="overflow-visible border-ink/[0.07] bg-white/80 shadow-sm"><CardContent className="p-5 sm:p-6"><div className="flex gap-3"><span className={cn("flex h-8 w-8 items-center justify-center rounded-lg text-xs font-black", done ? "bg-primary text-white" : "bg-ink/5 text-ink/40")}>{done ? <Check className="h-4 w-4" /> : step}</span><div><h2 className="text-sm font-black font-vazirmatn">{title}</h2><p className="mt-1 text-[10px] font-vazirmatn text-ink/40">{help}</p></div></div>{children}</CardContent></Card>; }
function Choice({ active, icon, title, help, onClick, compact }: { active: boolean; icon: React.ReactNode; title: string; help: string; onClick: () => void; compact?: boolean }) { return <button onClick={onClick} className={cn("flex gap-3 rounded-xl border text-start", compact ? "p-3" : "p-4", active ? "border-primary/30 bg-primary/[0.04] ring-2 ring-primary/[0.06]" : "border-ink/[0.08]")}><span className={cn("flex h-10 w-10 items-center justify-center rounded-lg [&>svg]:h-5 [&>svg]:w-5", active ? "bg-primary text-white" : "bg-ink/5 text-ink/40")}>{icon}</span><span className="flex-1"><b className="block text-xs font-vazirmatn">{title}</b><small className="mt-1 block text-[9px] leading-4 text-ink/40">{help}</small></span><span className={cn("flex h-5 w-5 items-center justify-center rounded-full border", active && "border-primary bg-primary text-white")}>{active && <Check className="h-3 w-3" />}</span></button>; }
function Label({ children }: { children: React.ReactNode }) { return <label className="mb-2 block text-[10px] font-black font-vazirmatn text-ink/55">{children}</label>; }
function Line({ label, value }: { label: string; value: React.ReactNode }) { return <div className="flex justify-between gap-3 border-b border-ink/5 pb-3 text-[10px]"><span className="text-ink/40">{label}</span><b className="text-end font-vazirmatn">{value}</b></div>; }
function Metric({ n, label }: { n: number; label: string }) { return <div className="rounded-xl bg-primary/[0.05] p-2 text-center"><b className="block text-lg text-primary">{n.toLocaleString()}</b><small className="text-[7px] text-ink/40">{label}</small></div>; }
function Empty({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) { return <div className="flex min-h-52 flex-col items-center justify-center text-center"><span className="rounded-2xl bg-primary/[0.06] p-4 text-primary [&>svg]:h-6 [&>svg]:w-6">{icon}</span><b className="mt-4 text-sm font-vazirmatn">{title}</b><p className="mt-2 max-w-56 text-[10px] leading-5 text-ink/40">{text}</p></div>; }
