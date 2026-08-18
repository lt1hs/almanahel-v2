"use client";

import React, { useState, useMemo } from "react";
import { motion } from "framer-motion";
import {
    Truck, CheckCircle2, X, ArrowLeftRight, Filter, Package, Loader2,
} from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

type FilterKey = "all" | "sending" | "receiving";
type TransferStatus = "pending" | "shipped" | "received" | "cancelled";

function isSendingStatus(status: string): boolean {
    return status === "pending" || status === "shipped";
}

interface TimelineUser {
    id?: string | number;
    role?: string;
    branch_id?: number | null;
}

interface TransferTimelineProps {
    transfers: any[];
    branches: any[];
    isLoading?: boolean;
    onNewTransfer?: () => void;
    hasMore?: boolean;
    loadingMore?: boolean;
    onLoadMore?: () => void;
    user?: TimelineUser | null;
    onUpdateStatus?: (id: number, status: Exclude<TransferStatus, "pending">) => Promise<void> | void;
    updatingId?: number | null;
}

function transferTitle(transfer: any, fallback: string): string {
    const items = Array.isArray(transfer.items) ? transfer.items : [];
    if (!items.length) return fallback;
    const first = items[0]?.book?.title || fallback;
    if (items.length === 1) return first;
    return `${first} (+${items.length - 1})`;
}

function transferQty(transfer: any): number {
    const items = Array.isArray(transfer.items) ? transfer.items : [];
    return items.reduce((sum: number, it: any) => sum + (Number(it?.quantity) || 0), 0);
}

function isAdmin(user?: TimelineUser | null) {
    return user?.role === "admin" || user?.role === "super_admin";
}

function canShip(user: TimelineUser | null | undefined, transfer: any, branches: any[] = []) {
    if (!user) return false;
    if (isAdmin(user)) return true;

    const from = branches.find((b) => Number(b.id) === Number(transfer.from_branch_id));
    if (from?.type === "warehouse") {
        return user.role === "warehouse_staff";
    }
    if (user.role === "warehouse_staff") return true;
    return Number(user.branch_id) === Number(transfer.from_branch_id);
}

function senderId(transfer: any): number | null {
    const id = transfer?.user_id ?? transfer?.user?.id;
    if (id == null || id === "") return null;
    const n = Number(id);
    return Number.isFinite(n) ? n : null;
}

function canReceive(user: TimelineUser | null | undefined, transfer: any, branches: any[] = []) {
    if (!user) return false;
    if (isAdmin(user)) return true;

    const to = branches.find((b) => Number(b.id) === Number(transfer.to_branch_id))
        || transfer.to_branch;
    if (to?.type === "warehouse" && user.role === "warehouse_staff") {
        return true;
    }

    if (!user.branch_id) return false;
    const fromUser = senderId(transfer);
    if (fromUser != null && Number(user.id) === fromUser) return false;
    return Number(user.branch_id) === Number(transfer.to_branch_id);
}

export function TransferTimeline({
    transfers,
    branches,
    isLoading,
    onNewTransfer,
    hasMore,
    loadingMore,
    onLoadMore,
    user,
    onUpdateStatus,
    updatingId,
}: TransferTimelineProps) {
    const { t, formatNumber, language } = useTranslation();
    const [filter, setFilter] = useState<FilterKey>("all");

    const statusCfg = useMemo(() => ({
        pending:   { label: t("distribution.status.sending"), color: "text-sky-700", bg: "bg-sky-50 border-sky-100", dot: "bg-sky-400", icon: Truck },
        shipped:   { label: t("distribution.status.sending"), color: "text-sky-700", bg: "bg-sky-50 border-sky-100", dot: "bg-sky-400", icon: Truck },
        sending:   { label: t("distribution.status.sending"), color: "text-sky-700", bg: "bg-sky-50 border-sky-100", dot: "bg-sky-400", icon: Truck },
        received:  { label: t("distribution.status.receiving"), color: "text-emerald-700", bg: "bg-emerald-50 border-emerald-100", dot: "bg-emerald-400", icon: CheckCircle2 },
        receiving: { label: t("distribution.status.receiving"), color: "text-emerald-700", bg: "bg-emerald-50 border-emerald-100", dot: "bg-emerald-400", icon: CheckCircle2 },
        cancelled: { label: t("distribution.status.cancelled"), color: "text-rose-700", bg: "bg-rose-50 border-rose-100", dot: "bg-rose-400", icon: X },
    }), [t]);

    const stepLabel = (status: string) => {
        if (status === "pending" || status === "shipped") return t("distribution.timeline.stepSending");
        if (status === "received") return t("distribution.timeline.stepReceiving");
        if (status === "cancelled") return t("distribution.timeline.stepCancelled");
        return status;
    };

    const branchName = (id: string | number) =>
        branches.find((b) => String(b.id) === String(id))?.name ?? "—";

    const filtered = useMemo(() => {
        if (filter === "all") return transfers;
        if (filter === "sending") return transfers.filter((tr) => isSendingStatus(tr.status));
        return transfers.filter((tr) => tr.status === "received");
    }, [transfers, filter]);

    const counts = useMemo(() => ({
        all: transfers.length,
        sending: transfers.filter((tr) => isSendingStatus(tr.status)).length,
        receiving: transfers.filter((tr) => tr.status === "received").length,
    }), [transfers]);

    const dateLocale = language === "ar" ? "ar-IQ" : "fa-IR";

    const formatWhen = (value?: string) => {
        if (!value) return "";
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return "";
        return d.toLocaleString(dateLocale, { dateStyle: "short", timeStyle: "short" });
    };

    const handleAction = async (transfer: any, status: Exclude<TransferStatus, "pending">) => {
        if (!onUpdateStatus) return;
        const message =
            status === "shipped" ? t("distribution.timeline.confirmShip") :
            status === "received" ? t("distribution.timeline.confirmReceive") :
            t("distribution.timeline.confirmCancel");
        if (!window.confirm(message)) return;
        await onUpdateStatus(transfer.id, status);
    };

    return (
        <div className="rounded-2xl border border-white/70 bg-white/70 overflow-hidden">
            <div className="px-5 py-4 border-b border-ink/[0.04] flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 className="text-[15px] font-black font-vazirmatn text-ink">{t("distribution.timeline.title")}</h2>
                    <p className="text-[10px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">
                        {formatNumber(filtered.length)} {t("distribution.timeline.records")}
                    </p>
                </div>
                <div className="flex items-center gap-1 p-1 bg-ink/[0.03] rounded-xl overflow-x-auto">
                    {(["all", "sending", "receiving"] as FilterKey[]).map((key) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setFilter(key)}
                            className={cn(
                                "flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[10px] font-black whitespace-nowrap transition-all",
                                filter === key ? "bg-white shadow-sm text-primary" : "text-ink/40 hover:text-ink/60"
                            )}
                        >
                            {key !== "all" && <Filter className="w-3 h-3 opacity-40" />}
                            {key === "all" ? t("common.all") : statusCfg[key]?.label}
                            <span className={cn(
                                "min-w-[18px] h-[18px] rounded-md flex items-center justify-center text-[8px] tabular-nums",
                                filter === key ? "bg-primary/10 text-primary" : "bg-ink/5 text-ink/30"
                            )}>
                                {formatNumber(counts[key])}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="p-5">
                {isLoading ? (
                    <div className="space-y-3">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <div key={i} className="flex gap-4">
                                <div className="w-3 h-3 rounded-full bg-parchment animate-pulse mt-2" />
                                <div className="flex-1 h-14 bg-parchment/40 rounded-2xl animate-pulse" />
                            </div>
                        ))}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-14 gap-3 text-center">
                        <div className="w-14 h-14 rounded-2xl bg-ink/[0.03] border border-ink/5 flex items-center justify-center">
                            <ArrowLeftRight className="w-6 h-6 text-ink/15" />
                        </div>
                        <div>
                            <p className="text-[13px] font-black font-vazirmatn text-ink/50">{t("distribution.timeline.emptyTitle")}</p>
                            <p className="text-[10px] text-ink/30 mt-1">{t("distribution.timeline.emptyDetail")}</p>
                        </div>
                        {onNewTransfer && (
                            <Button variant="primary" size="sm" className="rounded-xl text-[11px] font-black" onClick={onNewTransfer}>
                                {t("distribution.timeline.startNew")}
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="relative space-y-0">
                        <div className="absolute top-3 bottom-3 w-px bg-gradient-to-b from-primary/30 via-ink/10 to-transparent start-[11px]" />
                        {filtered.map((transfer, i) => {
                            const cfg = statusCfg[transfer.status as keyof typeof statusCfg] || statusCfg.shipped;
                            const qty = transferQty(transfer);
                            const title = transferTitle(transfer, t("distribution.bookFallback"));
                            const items = Array.isArray(transfer.items) ? transfer.items : [];
                            const steps = Array.isArray(transfer.status_log) ? transfer.status_log : [];
                            const busy = updatingId === transfer.id;
                            const sending = isSendingStatus(transfer.status);
                            const showReceive = sending && canReceive(user, transfer, branches);
                            const showCancel = sending && canShip(user, transfer, branches);
                            const destName = transfer.to_branch?.name || branchName(transfer.to_branch_id);

                            return (
                                <motion.div
                                    key={transfer.id}
                                    initial={{ opacity: 0, y: 6 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: Math.min(i, 8) * 0.03 }}
                                    className="relative flex gap-4 pb-5 last:pb-0 group"
                                >
                                    <div className={cn("relative z-10 w-6 h-6 rounded-full border-2 border-white shadow-sm shrink-0 mt-1", cfg.dot)} />
                                    <div className="flex-1 min-w-0 p-3.5 rounded-2xl border border-ink/[0.05] bg-white/80 hover:bg-white hover:shadow-md hover:border-primary/10 transition-all">
                                        <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className="text-[12px] font-black font-vazirmatn text-ink group-hover:text-primary transition-colors truncate">
                                                        {title}
                                                    </span>
                                                    <Badge className={cn("text-[8px] font-black border", cfg.bg, cfg.color)}>{cfg.label}</Badge>
                                                    {items.length > 1 && (
                                                        <span className="inline-flex items-center gap-1 text-[8px] font-bold text-ink/35">
                                                            <Package className="w-3 h-3" />
                                                            {formatNumber(items.length)}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex items-center flex-wrap gap-2 mt-2">
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-ink/45 px-2 py-1 rounded-lg bg-parchment/60">
                                                        {transfer.from_branch?.name || branchName(transfer.from_branch_id)}
                                                    </span>
                                                    <ArrowLeftRight className="w-3 h-3 text-primary/35" />
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-primary/70 px-2 py-1 rounded-lg bg-primary/5 border border-primary/10">
                                                        {transfer.to_branch?.name || branchName(transfer.to_branch_id)}
                                                    </span>
                                                </div>
                                                {transfer.created_at && (
                                                    <p className="text-[9px] text-ink/25 font-mono mt-2">
                                                        {formatWhen(transfer.created_at)}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-3 shrink-0">
                                                <div className="text-end">
                                                    <p className="text-xl font-black font-vazirmatn text-ink leading-none tabular-nums">
                                                        {formatNumber(qty)}
                                                    </p>
                                                    <p className="text-[8px] text-ink/25 font-bold uppercase mt-0.5">
                                                        {t("distribution.volumeUnit")}
                                                    </p>
                                                </div>
                                                <div className={cn("w-9 h-9 rounded-xl border flex items-center justify-center", cfg.bg)}>
                                                    <cfg.icon className={cn("w-4 h-4", cfg.color)} />
                                                </div>
                                            </div>
                                        </div>

                                        {items.length > 0 && (
                                            <div className="mt-3 rounded-xl border border-ink/[0.06] bg-parchment/30 overflow-hidden">
                                                <p className="px-3 py-2 text-[9px] font-black text-ink/35 uppercase tracking-widest">
                                                    {t("distribution.timeline.itemsToCheck")}
                                                </p>
                                                <div className="divide-y divide-ink/[0.04]">
                                                    {items.map((item: any, idx: number) => (
                                                        <div key={`${item.book_id ?? idx}-${idx}`} className="px-3 py-2 flex items-start justify-between gap-3">
                                                            <div className="min-w-0">
                                                                <p className="text-[11px] font-black font-vazirmatn text-ink truncate">
                                                                    {item.book?.title || t("distribution.bookFallback")}
                                                                </p>
                                                                <p className="text-[9px] text-ink/40 mt-0.5 truncate">
                                                                    {[item.book?.author, item.book?.isbn].filter(Boolean).join(" · ") || "—"}
                                                                </p>
                                                            </div>
                                                            <span className="text-[12px] font-black font-vazirmatn tabular-nums text-ink shrink-0">
                                                                {formatNumber(Number(item.quantity) || 0)}
                                                                <span className="text-[8px] text-ink/30 ms-1 font-bold">{t("distribution.volumeUnit")}</span>
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {steps.length > 0 && (
                                            <div className="mt-3 space-y-1.5">
                                                <p className="text-[9px] font-black text-ink/35 uppercase tracking-widest">
                                                    {t("distribution.timeline.steps")}
                                                </p>
                                                {steps.map((step: any, idx: number) => (
                                                    <div key={`${step.status}-${step.at ?? idx}`} className="flex items-center gap-2 text-[10px]">
                                                        <span className={cn(
                                                            "w-1.5 h-1.5 rounded-full shrink-0",
                                                            step.status === "received" ? "bg-emerald-500" :
                                                            step.status === "cancelled" ? "bg-rose-500" : "bg-sky-500"
                                                        )} />
                                                        <span className="font-black text-ink/70">{stepLabel(step.status)}</span>
                                                        {step.user_name && (
                                                            <span className="text-ink/35">
                                                                {t("distribution.timeline.byUser", { name: step.user_name })}
                                                            </span>
                                                        )}
                                                        {step.at && (
                                                            <span className="text-ink/25 font-mono ms-auto">{formatWhen(step.at)}</span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {sending && !showReceive && (
                                            <p className="mt-3 text-[10px] font-bold text-sky-700 bg-sky-50 border border-sky-100 rounded-xl px-3 py-2">
                                                {t("distribution.timeline.waitingDestApprove", { branch: destName })}
                                            </p>
                                        )}

                                        {(showReceive || showCancel) && (
                                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                                {showReceive && (
                                                    <Button
                                                        type="button"
                                                        variant="primary"
                                                        size="sm"
                                                        disabled={busy}
                                                        className="rounded-xl text-[11px] font-black h-8 px-3 bg-emerald-600 hover:bg-emerald-700"
                                                        onClick={() => handleAction(transfer, "received")}
                                                    >
                                                        {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin ms-1" /> : <CheckCircle2 className="w-3.5 h-3.5 ms-1" />}
                                                        {t("distribution.timeline.receive")}
                                                    </Button>
                                                )}
                                                {showCancel && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        className="rounded-xl text-[11px] font-black h-8 px-3 text-rose-600 hover:bg-rose-50"
                                                        onClick={() => handleAction(transfer, "cancelled")}
                                                    >
                                                        {t("distribution.timeline.cancel")}
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </motion.div>
                            );
                        })}

                        {hasMore && filter === "all" && onLoadMore && (
                            <div className="pt-4 flex justify-center relative z-10">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={loadingMore}
                                    onClick={onLoadMore}
                                    className="rounded-xl text-[11px] font-black h-9 px-4"
                                >
                                    {loadingMore ? (
                                        <Loader2 className="w-3.5 h-3.5 animate-spin ms-1.5" />
                                    ) : null}
                                    {t("distribution.timeline.loadMore")}
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
