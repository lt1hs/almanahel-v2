"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import { motion, Variants } from "framer-motion";
import {
    HandCoins, AlertTriangle, CheckCircle2, Clock, Search,
    CalendarDays, Phone, User, Store, Receipt,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { Link } from "@/i18n/routing";

const stagger: Variants = {
    hidden: { opacity: 0 },
    show: { opacity: 1, transition: { staggerChildren: 0.06 } },
};
const fadeUp: Variants = {
    hidden: { opacity: 0, y: 12 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 380, damping: 28 } },
};

type CreditStatus = "pending" | "paid" | "overdue";

function formatDueDate(value: string | null | undefined): string {
    if (!value) return "—";
    return String(value).slice(0, 10);
}

export default function CreditsPage() {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [statusFilter, setStatusFilter] = useState<CreditStatus | "all">("all");
    const [search, setSearch] = useState("");
    const [credits, setCredits] = useState<any[]>([]);
    const [dueSoon, setDueSoon] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [updatingId, setUpdatingId] = useState<number | null>(null);

    const statusCfg = useMemo(() => ({
        pending: { label: t("credits.status.pending"), icon: Clock, color: "text-amber-500", bg: "bg-amber-50", border: "border-amber-100" },
        paid: { label: t("credits.status.paid"), icon: CheckCircle2, color: "text-emerald-500", bg: "bg-emerald-50", border: "border-emerald-100" },
        overdue: { label: t("credits.status.overdue"), icon: AlertTriangle, color: "text-rose-500", bg: "bg-rose-50", border: "border-rose-100" },
    }), [t]);

    const fetchCredits = useCallback(async () => {
        setIsLoading(true);
        try {
            const params = new URLSearchParams();
            if (statusFilter !== "all") params.set("payment_status", statusFilter);
            const qs = params.toString();
            const data = await apiRequest(`/credits${qs ? `?${qs}` : ""}`);
            setCredits(data.credits || []);
            setDueSoon(data.due_soon || []);
        } catch (error) {
            console.error("Failed to fetch credits:", error);
        } finally {
            setIsLoading(false);
        }
    }, [statusFilter]);

    useEffect(() => { fetchCredits(); }, [fetchCredits]);

    const updateStatus = async (id: number, payment_status: CreditStatus) => {
        setUpdatingId(id);
        try {
            await apiRequest(`/credits/${id}`, {
                method: "PUT",
                body: JSON.stringify({ payment_status }),
            });
            notify.success("toast.creditsUpdated");
            fetchCredits();
        } catch (error) {
            console.error("Failed to update credit:", error);
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("toast.creditsUpdateError");
        } finally {
            setUpdatingId(null);
        }
    };

    const filtered = credits.filter((c) => {
        if (!search.trim()) return true;
        const q = search.trim().toLowerCase();
        return (
            (c.customer_name || "").toLowerCase().includes(q) ||
            (c.customer_phone || "").includes(q) ||
            (c.invoice_number || "").toLowerCase().includes(q)
        );
    });

    const unpaid = credits.filter((c) => c.payment_status === "pending" || c.payment_status === "overdue");
    const totalUnpaid = unpaid.reduce((a, c) => a + Number(c.total || 0), 0);
    const tomanSymbol = t("common.currency.tomanSymbol");
    const dinarSymbol = t("common.currency.dinarSymbol");

    return (
        <motion.div variants={stagger} initial="hidden" animate="show" className="space-y-5 pb-10">
            <motion.div variants={fadeUp} className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-black font-vazirmatn text-ink">{t("credits.title")}</h1>
                    <p className="text-[10px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">
                        {t("credits.subtitle")}
                    </p>
                </div>
            </motion.div>

            {dueSoon.length > 0 && (
                <motion.div variants={fadeUp} className="flex items-start gap-3 px-4 py-3.5 rounded-xl bg-amber-50/80 border border-amber-200">
                    <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                    <div>
                        <p className="text-[11px] font-black font-vazirmatn text-amber-800">
                            {t("credits.dueSoon", { count: dueSoon.length })}
                        </p>
                        <p className="text-[9px] text-amber-600 mt-0.5">
                            {t("credits.totalUnpaid")}: {formatNumber(totalUnpaid)} {tomanSymbol}
                        </p>
                    </div>
                </motion.div>
            )}

            <motion.div variants={fadeUp} className="grid grid-cols-3 gap-3">
                {[
                    { label: t("credits.status.pending"), value: credits.filter((c) => c.payment_status === "pending").length, color: "text-amber-500" },
                    { label: t("credits.status.paid"), value: credits.filter((c) => c.payment_status === "paid").length, color: "text-emerald-500" },
                    { label: t("credits.status.overdue"), value: credits.filter((c) => c.payment_status === "overdue").length, color: "text-rose-500" },
                ].map((kpi, i) => (
                    <motion.div key={i} whileHover={{ y: -2 }}>
                        <Card className="border border-white/70 bg-white/70 backdrop-blur-xl shadow-sm rounded-[14px]">
                            <CardContent className="p-4">
                                <p className="text-[9px] font-bold text-ink/35 uppercase tracking-widest mb-1">{kpi.label}</p>
                                <p className={cn("text-2xl font-black font-vazirmatn tabular-nums", kpi.color)}>{kpi.value}</p>
                            </CardContent>
                        </Card>
                    </motion.div>
                ))}
            </motion.div>

            <motion.div variants={fadeUp} className="flex gap-2 flex-wrap">
                <div className="flex-1 relative min-w-[200px]">
                    <Search className="absolute inset-y-0 end-3 my-auto w-3.5 h-3.5 text-ink/20 pointer-events-none" />
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("credits.searchPlaceholder")}
                        className="w-full h-10 bg-white/70 border border-white focus:border-primary/30 rounded-xl pe-9 ps-3 text-[12px] font-vazirmatn placeholder:text-ink/20 outline-none shadow-sm"
                    />
                </div>
                {(["all", "pending", "paid", "overdue"] as const).map((s) => (
                    <button
                        key={s}
                        type="button"
                        onClick={() => setStatusFilter(s)}
                        className={cn(
                            "h-10 px-4 rounded-xl text-[10px] font-black uppercase tracking-wide transition-all border",
                            statusFilter === s
                                ? "bg-primary text-white border-primary shadow-lg shadow-primary/20"
                                : "bg-white/70 text-ink/40 border-white hover:border-primary/20 hover:text-ink/70"
                        )}
                    >
                        {s === "all" ? t("common.all") : statusCfg[s as CreditStatus].label}
                    </button>
                ))}
            </motion.div>

            <motion.div variants={stagger} className="space-y-3">
                {isLoading ? (
                    Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="h-24 bg-parchment/20 rounded-2xl animate-pulse" />
                    ))
                ) : filtered.length === 0 ? (
                    <Card className="border border-white/70 bg-white/70 rounded-2xl">
                        <CardContent className="p-10 text-center text-ink/30 text-[12px] font-black">{t("credits.empty")}</CardContent>
                    </Card>
                ) : filtered.map((inv) => {
                    const status = (inv.payment_status || "pending") as CreditStatus;
                    const cfg = statusCfg[status] ?? statusCfg.pending;
                    const isUnpaid = status === "pending" || status === "overdue";
                    return (
                        <motion.div key={inv.id} variants={fadeUp} whileHover={{ y: -1 }}>
                            <Card className={cn(
                                "border bg-white/70 backdrop-blur-xl shadow-sm hover:shadow-lg rounded-2xl overflow-hidden transition-all",
                                status === "overdue" ? "border-rose-100" : "border-white/70"
                            )}>
                                <CardContent className="p-4">
                                    <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                                        <div className={cn("w-11 h-11 rounded-xl flex items-center justify-center border shrink-0", cfg.bg, cfg.border)}>
                                            <cfg.icon className={cn("w-5 h-5", cfg.color)} />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="text-[13px] font-black text-ink font-vazirmatn flex items-center gap-1">
                                                    <User className="w-3.5 h-3.5 text-ink/25" />
                                                    {inv.customer_name || t("credits.unknownCustomer")}
                                                </span>
                                                <Badge className={cn("text-[8px] font-black border", cfg.bg, cfg.color, cfg.border)}>{cfg.label}</Badge>
                                                <Link
                                                    href={`/dashboard/invoices?id=${inv.id}`}
                                                    className="text-[9px] text-ink/25 font-mono flex items-center gap-1 hover:text-primary transition-colors"
                                                >
                                                    <Receipt className="w-3 h-3" />
                                                    {inv.invoice_number}
                                                </Link>
                                            </div>
                                            <div className="flex items-center gap-4 mt-1 flex-wrap text-[9px] text-ink/35">
                                                <span className="flex items-center gap-1">
                                                    <CalendarDays className="w-3 h-3" />
                                                    {t("credits.dueDate")}: {formatDueDate(inv.due_date)}
                                                </span>
                                                {inv.customer_phone && (
                                                    <span className="flex items-center gap-1">
                                                        <Phone className="w-3 h-3" />
                                                        {inv.customer_phone}
                                                    </span>
                                                )}
                                                {inv.branch?.name && (
                                                    <span className="flex items-center gap-1">
                                                        <Store className="w-3 h-3" />
                                                        {t("credits.branch")} {inv.branch.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="text-end shrink-0">
                                            <p className="text-[8px] text-ink/25 uppercase tracking-widest">{t("credits.amount")}</p>
                                            <p className="text-[16px] font-black text-ink font-vazirmatn tabular-nums">
                                                {formatNumber(Number(inv.total || 0))}
                                                <span className="text-[10px] text-ink/25 ms-0.5">
                                                    {inv.currency === "dinar" ? dinarSymbol : tomanSymbol}
                                                </span>
                                            </p>
                                        </div>
                                        <div className="flex gap-1.5 shrink-0">
                                            {isUnpaid && (
                                                <Button
                                                    size="sm"
                                                    disabled={updatingId === inv.id}
                                                    className="h-8 px-3 rounded-lg text-[10px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white"
                                                    onClick={() => updateStatus(inv.id, "paid")}
                                                >
                                                    {updatingId === inv.id ? t("common.submitting") : t("credits.markPaid")}
                                                </Button>
                                            )}
                                            {status === "pending" && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={updatingId === inv.id}
                                                    className="h-8 px-3 rounded-lg text-[10px] font-bold text-rose-500 hover:bg-rose-50 border border-rose-100"
                                                    onClick={() => updateStatus(inv.id, "overdue")}
                                                >
                                                    {t("credits.markOverdue")}
                                                </Button>
                                            )}
                                            {status === "paid" && (
                                                <span className="text-[9px] font-black text-emerald-600 px-2 py-1 bg-emerald-50 rounded-lg border border-emerald-100 flex items-center gap-1">
                                                    <HandCoins className="w-3 h-3" />
                                                    {t("credits.status.paid")}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    );
                })}
            </motion.div>
        </motion.div>
    );
}
