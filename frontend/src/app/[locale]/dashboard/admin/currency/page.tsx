"use client";

import { usePageReady } from "@/components/NavigationProgress";

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
import {
    DEFAULT_TOMAN_PER_1000_DINAR,
    DINAR_BASE,
    dinarToToman,
    parsePositiveRate,
    tomanToDinar,
} from "@/lib/currencyRate";
import { cn } from "@/lib/utils";

const fadeUp: Variants = {
    hidden: { opacity: 0, y: 10 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 400, damping: 30 } },
};

/** Preview rows: dinar amounts → toman (matches market quote style). */
const PREVIEW_DINAR_AMOUNTS = [1000, 5000, 10000, 50000];

export default function CurrencySettingsPage() {
    const { t, formatNumber, formatDate, isArabic } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const { user } = useAuth();

    const [rate, setRate] = useState(String(DEFAULT_TOMAN_PER_1000_DINAR));
    const [notes, setNotes] = useState("");
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [isSaving, setIsSaving] = useState(false);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const fetchSettings = useCallback(async () => {
        setIsLoading(true);
        try {
            const data = await apiRequest("/settings");
            const saved =
                data.toman_per_1000_dinar ??
                data.toman_to_dinar_rate ??
                DEFAULT_TOMAN_PER_1000_DINAR;
            setRate(String(saved));
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

    const numericRate = useMemo(() => parsePositiveRate(rate), [rate]);

    const handleSave = async () => {
        if (numericRate < 1000) {
            notify.error("currencySettings.invalidRate");
            return;
        }
        setIsSaving(true);
        try {
            const data = await apiRequest("/settings", {
                method: "PUT",
                body: JSON.stringify({
                    toman_per_1000_dinar: numericRate,
                    rate_notes: notes.trim() || null,
                }),
            });
            setUpdatedAt(data.rate_updated_at ?? new Date().toISOString());
            setRate(String(data.toman_per_1000_dinar ?? numericRate));
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
                <p className="text-sm font-bold font-ibm-plex-arabic text-ink/40">
                    {t("currencySettings.accessDenied")}
                </p>
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
            className="space-y-6 pb-12 max-w-3xl font-ibm-plex-arabic"
        >
            <motion.div variants={fadeUp} className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-10 w-10 rounded-xl p-0"
                        onClick={() => router.push("/dashboard/admin")}
                    >
                        {isArabic ? (
                            <ArrowRight className="h-4 w-4 text-ink/40" />
                        ) : (
                            <ArrowLeft className="h-4 w-4 text-ink/40" />
                        )}
                    </Button>
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold font-ibm-plex-arabic tracking-tight text-ink">
                            <span className="flex h-10 w-10 items-center justify-center rounded-xl border border-accent/20 bg-accent/10">
                                <Globe className="h-5 w-5 text-accent" />
                            </span>
                            {t("currencySettings.title")}
                        </h1>
                        <p className="mt-1 text-[12px] font-medium text-ink/40">
                            {t("currencySettings.subtitle")}
                        </p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-10 rounded-xl text-[12px] font-bold"
                        onClick={fetchSettings}
                        disabled={isLoading}
                    >
                        <RefreshCw className={cn("h-3.5 w-3.5", isLoading && "animate-spin")} />
                        {t("common.refresh")}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        className="h-10 rounded-xl px-4 text-[12px] font-bold shadow-lg shadow-primary/15"
                        onClick={handleSave}
                        disabled={isSaving || isLoading}
                    >
                        <Save className="h-3.5 w-3.5" />
                        {isSaving ? t("common.saving") : t("common.save")}
                    </Button>
                </div>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="overflow-hidden rounded-3xl border border-white/70 bg-white/95 shadow-[0_16px_48px_rgba(13,13,13,0.06)] backdrop-blur-xl">
                    <CardHeader className="border-b border-ink/5 bg-parchment/30 px-5 py-4 sm:px-6">
                        <CardTitle className="flex items-center gap-2 text-[13px] font-bold font-ibm-plex-arabic text-ink">
                            <Coins className="h-4 w-4 text-primary" />
                            {t("currencySettings.exchangeRate")}
                        </CardTitle>
                        <CardDescription className="mt-1 text-[11px] font-medium leading-5 text-ink/40">
                            {t("currencySettings.rateDescription")}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5 px-5 py-5 sm:px-6">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="rounded-2xl border border-accent/10 bg-accent/[0.03] p-4">
                                <p className="mb-2 text-[10px] font-bold text-ink/35">
                                    {t("common.currency.dinarSymbol")}
                                </p>
                                <p className="text-lg font-bold font-ibm-plex-arabic text-ink">
                                    {formatNumber(DINAR_BASE)} {t("common.dinar")}
                                </p>
                                <p className="mt-1.5 text-[10px] font-medium text-ink/35">
                                    {t("currencySettings.fixedDinarBase")}
                                </p>
                            </div>
                            <div className="rounded-2xl border border-primary/10 bg-primary/[0.03] p-4">
                                <p className="mb-2 text-[10px] font-bold text-ink/35">
                                    {t("common.currency.tomanSymbol")}
                                </p>
                                <label className="block">
                                    <input
                                        type="number"
                                        min="1000"
                                        step="1000"
                                        value={rate}
                                        onChange={(e) => setRate(e.target.value)}
                                        disabled={isLoading}
                                        className="h-11 w-full rounded-xl border border-ink/8 bg-white px-3 text-lg font-bold font-ibm-plex-arabic text-ink outline-none transition-all focus:border-primary/30 focus:ring-2 focus:ring-primary/10"
                                    />
                                </label>
                                <p className="mt-1.5 text-[10px] font-medium text-ink/35">
                                    {t("currencySettings.tomanPerThousandDinar")}
                                </p>
                            </div>
                        </div>

                        {updatedAt && (
                            <p className="text-[11px] font-medium text-ink/35">
                                {t("currencySettings.lastUpdated")}: {formatDate(updatedAt)}
                            </p>
                        )}

                        <div>
                            <label className="mb-2 block text-[11px] font-bold text-ink/45">
                                {t("currencySettings.notes")}
                            </label>
                            <textarea
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={2}
                                placeholder={t("currencySettings.notesPlaceholder")}
                                className="w-full resize-none rounded-xl border border-ink/8 bg-parchment/25 px-3 py-2.5 text-[12px] font-ibm-plex-arabic outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                            />
                        </div>
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="overflow-hidden rounded-3xl border border-white/70 bg-white/95 shadow-[0_16px_48px_rgba(13,13,13,0.06)]">
                    <CardHeader className="border-b border-ink/5 bg-parchment/20 px-5 py-4 sm:px-6">
                        <CardTitle className="flex items-center gap-2 text-[13px] font-bold font-ibm-plex-arabic text-ink">
                            <Calculator className="h-4 w-4 text-emerald-500" />
                            {t("currencySettings.preview")}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-2">
                        <div className="divide-y divide-ink/5">
                            {PREVIEW_DINAR_AMOUNTS.map((dinarAmount) => (
                                <div key={dinarAmount} className="flex items-center justify-between px-4 py-3.5">
                                    <span className="text-[12px] font-bold font-ibm-plex-arabic text-ink/70">
                                        {formatNumber(dinarAmount)} {t("common.dinar")}
                                    </span>
                                    <span className="text-[10px] text-ink/20">≈</span>
                                    <span className="text-[12px] font-bold font-ibm-plex-arabic text-primary">
                                        {formatNumber(dinarToToman(dinarAmount, numericRate))} {t("common.toman")}
                                    </span>
                                </div>
                            ))}
                        </div>
                        {numericRate > 0 && (
                            <div className="mx-2 mb-2 mt-1 rounded-xl border border-ink/5 bg-parchment/30 px-4 py-3 text-[11px] font-medium text-ink/45">
                                {formatNumber(120000)} {t("common.toman")} ≈{" "}
                                <span className="font-bold text-accent">
                                    {formatNumber(tomanToDinar(120000, numericRate))} {t("common.dinar")}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="rounded-3xl border border-ink/5 bg-parchment/25">
                    <CardContent className="space-y-4 p-5 sm:p-6">
                        <div className="flex items-start gap-3">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                            <p className="text-[12px] font-medium font-ibm-plex-arabic leading-6 text-ink/50">
                                {t("currencySettings.info")}
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {[
                                { label: t("countries.iran"), currency: t("common.toman"), icon: Building2, color: "text-primary" },
                                { label: t("countries.iraq"), currency: t("common.dinar"), icon: Globe, color: "text-accent" },
                            ].map((row) => (
                                <div
                                    key={row.label}
                                    className="flex items-center gap-3 rounded-2xl border border-ink/5 bg-white p-3.5"
                                >
                                    <row.icon className={cn("h-4 w-4 opacity-50", row.color)} />
                                    <div>
                                        <p className="text-[12px] font-bold font-ibm-plex-arabic">{row.label}</p>
                                        <Badge className="mt-1 text-[9px]">{row.currency}</Badge>
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
