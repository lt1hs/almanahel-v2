"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import { motion, Variants } from "framer-motion";
import {
    Globe, ArrowRight, ArrowLeft, Save, RefreshCw, Coins,
    Building2, Calculator, Info,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";

const fadeUp: Variants = {
    hidden: { opacity: 0, y: 10 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 400, damping: 30 } },
};

const PREVIEW_AMOUNTS = [1000, 10000, 100000, 1000000];

export default function CurrencySettingsPage() {
    const { t, formatNumber, formatDate, isArabic } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const { user } = useAuth();

    const [rate, setRate] = useState("50");
    const [notes, setNotes] = useState("");
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const fetchSettings = useCallback(async () => {
        setIsLoading(true);
        try {
            const data = await apiRequest("/settings");
            setRate(String(data.toman_to_dinar_rate ?? 50));
            setNotes(data.rate_notes ?? "");
            setUpdatedAt(data.rate_updated_at ?? null);
        } catch (error) {
            console.error("Currency settings fetch failed:", error);
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchSettings();
    }, [fetchSettings]);

    const numericRate = useMemo(() => {
        const n = parseFloat(rate);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }, [rate]);

    const handleSave = async () => {
        if (numericRate <= 0) {
            notify.error("currencySettings.invalidRate");
            return;
        }
        setIsSaving(true);
        try {
            const data = await apiRequest("/settings", {
                method: "PUT",
                body: JSON.stringify({
                    toman_to_dinar_rate: numericRate,
                    rate_notes: notes.trim() || null,
                }),
            });
            setUpdatedAt(data.rate_updated_at ?? new Date().toISOString());
            notify.success("toast.settingsSaved");
        } catch (error) {
            console.error("Save currency settings failed:", error);
            notify.error("toast.settingsSaveError");
        } finally {
            setIsSaving(false);
        }
    };

    if (!isAdmin) {
        return (
            <div className="p-8 text-center">
                <p className="text-sm font-bold text-ink/40">{t("currencySettings.accessDenied")}</p>
                <Button variant="ghost" className="mt-4" onClick={() => router.push("/dashboard")}>
                    {t("common.back")}
                </Button>
            </div>
        );
    }

    return (
        <motion.div
            initial="hidden"
            animate="show"
            variants={{ show: { transition: { staggerChildren: 0.06 } } }}
            className="space-y-6 pb-12 max-w-3xl"
        >
            <motion.div variants={fadeUp} className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 w-9 p-0 rounded-[7px]"
                        onClick={() => router.push("/dashboard/admin")}
                    >
                        {isArabic ? (
                            <ArrowRight className="w-4 h-4 text-ink/40" />
                        ) : (
                            <ArrowLeft className="w-4 h-4 text-ink/40" />
                        )}
                    </Button>
                    <div>
                        <h1 className="text-2xl font-black font-vazirmatn text-ink flex items-center gap-2">
                            <Globe className="w-6 h-6 text-accent" />
                            {t("currencySettings.title")}
                        </h1>
                        <p className="text-[11px] text-ink/40 font-rubik mt-0.5 uppercase tracking-wider">
                            {t("currencySettings.subtitle")}
                        </p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 rounded-[7px] text-[11px]"
                        onClick={fetchSettings}
                        disabled={isLoading}
                    >
                        <RefreshCw className={cn("w-3.5 h-3.5", isLoading && "animate-spin")} />
                        {t("common.refresh")}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        className="h-9 px-4 rounded-[7px] text-[11px] font-black"
                        onClick={handleSave}
                        disabled={isSaving || isLoading}
                    >
                        <Save className="w-3.5 h-3.5 ml-1" />
                        {isSaving ? t("common.saving") : t("common.save")}
                    </Button>
                </div>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="border border-ink/5 bg-white shadow-sm rounded-[7px] overflow-hidden">
                    <CardHeader className="p-5 border-b border-ink/5 bg-parchment/10">
                        <CardTitle className="text-xs font-black uppercase tracking-widest text-ink/80 flex items-center gap-2">
                            <Coins className="w-4 h-4 text-primary" />
                            {t("currencySettings.exchangeRate")}
                        </CardTitle>
                        <CardDescription className="text-[10px] mt-1">
                            {t("currencySettings.rateDescription")}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-5 space-y-5">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div className="p-4 rounded-[7px] border border-primary/10 bg-primary/[0.03]">
                                <p className="text-[9px] font-black uppercase tracking-widest text-ink/30 mb-2">
                                    {t("common.currency.tomanSymbol")}
                                </p>
                                <p className="text-lg font-black font-vazirmatn text-ink">1 {t("common.toman")}</p>
                            </div>
                            <div className="p-4 rounded-[7px] border border-accent/10 bg-accent/[0.03]">
                                <p className="text-[9px] font-black uppercase tracking-widest text-ink/30 mb-2">
                                    {t("common.currency.dinarSymbol")}
                                </p>
                                <label className="block">
                                    <input
                                        type="number"
                                        min="0.0001"
                                        step="0.01"
                                        value={rate}
                                        onChange={(e) => setRate(e.target.value)}
                                        disabled={isLoading}
                                        className="w-full h-10 bg-white border border-ink/10 rounded-[7px] px-3 text-lg font-black font-vazirmatn text-ink outline-none focus:ring-2 focus:ring-primary/20"
                                    />
                                </label>
                                <p className="text-[9px] text-ink/35 mt-1.5">{t("currencySettings.dinarPerToman")}</p>
                            </div>
                        </div>

                        {updatedAt && (
                            <p className="text-[10px] text-ink/35 font-bold">
                                {t("currencySettings.lastUpdated")}: {formatDate(updatedAt)}
                            </p>
                        )}

                        <div>
                            <label className="text-[10px] font-black uppercase tracking-widest text-ink/40 block mb-2">
                                {t("currencySettings.notes")}
                            </label>
                            <textarea
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={2}
                                placeholder={t("currencySettings.notesPlaceholder")}
                                className="w-full rounded-[7px] border border-ink/10 px-3 py-2 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/20 resize-none"
                            />
                        </div>
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="border border-ink/5 bg-white shadow-sm rounded-[7px] overflow-hidden">
                    <CardHeader className="p-5 border-b border-ink/5">
                        <CardTitle className="text-xs font-black uppercase tracking-widest text-ink/80 flex items-center gap-2">
                            <Calculator className="w-4 h-4 text-emerald-500" />
                            {t("currencySettings.preview")}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-2">
                        <div className="divide-y divide-ink/5">
                            {PREVIEW_AMOUNTS.map((amount) => (
                                <div key={amount} className="flex items-center justify-between px-4 py-3">
                                    <span className="text-[12px] font-bold font-vazirmatn text-ink/70">
                                        {formatNumber(amount)} {t("common.toman")}
                                    </span>
                                    <span className="text-[8px] text-ink/20">≈</span>
                                    <span className="text-[12px] font-black font-vazirmatn text-accent">
                                        {formatNumber(Math.round(amount * numericRate))} {t("common.dinar")}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="border border-ink/5 bg-parchment/20 rounded-[7px]">
                    <CardContent className="p-5 space-y-4">
                        <div className="flex items-start gap-3">
                            <Info className="w-4 h-4 text-primary shrink-0 mt-0.5" />
                            <p className="text-[11px] text-ink/50 font-vazirmatn leading-relaxed">
                                {t("currencySettings.info")}
                            </p>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {[
                                { label: t("countries.iran"), currency: t("common.toman"), icon: Building2, color: "text-primary" },
                                { label: t("countries.iraq"), currency: t("common.dinar"), icon: Globe, color: "text-accent" },
                            ].map((row) => (
                                <div key={row.label} className="flex items-center gap-3 p-3 rounded-[7px] bg-white border border-ink/5">
                                    <row.icon className={cn("w-4 h-4 opacity-50", row.color)} />
                                    <div>
                                        <p className="text-[11px] font-black font-vazirmatn">{row.label}</p>
                                        <Badge className="text-[8px] mt-1">{row.currency}</Badge>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </motion.div>
        </motion.div>
    );
}
