"use client";

import React, { useCallback, useEffect, useState } from "react";
import { RefreshCw, Coins, Server, Clock } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";
import { apiRequest } from "@/lib/api";
import {
    DEFAULT_TOMAN_PER_1000_DINAR,
    DINAR_BASE,
    parsePositiveRate,
} from "@/lib/currencyRate";
import { cn } from "@/lib/utils";

export function Footer() {
    const { t, formatNumber, formatDate } = useTranslation();
    const [rate, setRate] = useState(DEFAULT_TOMAN_PER_1000_DINAR);
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    const fetchRate = useCallback(async () => {
        setIsLoading(true);
        try {
            const data = await apiRequest("/settings");
            const next = parsePositiveRate(
                data?.toman_per_1000_dinar ?? data?.toman_to_dinar_rate
            );
            if (next >= 1000) {
                setRate(next);
            }
            setUpdatedAt(data?.rate_updated_at ?? null);
        } catch {
            /* keep last known / default */
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchRate();
    }, [fetchRate]);

    return (
        <footer className="pointer-events-none fixed bottom-0 z-30 flex h-9 items-center justify-between border-t border-white/40 bg-white/80 px-6 shadow-[0_-2px_10px_rgba(0,0,0,0.02)] backdrop-blur-2xl md:relative md:pointer-events-auto">
            <div className="flex items-center gap-5">
                <div className="group flex cursor-default items-center gap-1.5">
                    <div className="relative">
                        <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
                        <div className="absolute inset-0 h-1.5 w-1.5 animate-ping rounded-full bg-emerald-500 opacity-20" />
                    </div>
                    <span className="text-[9px] font-bold font-ibm-plex-arabic uppercase tracking-tight text-ink/30 transition-colors group-hover:text-emerald-600">
                        {t("common.dbConnected")}
                    </span>
                </div>

                <div className="hidden items-center gap-4 lg:flex">
                    <div className="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-tighter text-ink/20">
                        <Server className="h-2.5 w-2.5 opacity-30" />
                        <span>v1.0.2</span>
                    </div>
                    {updatedAt && (
                        <div className="flex items-center gap-1.5 text-[9px] font-medium text-ink/20">
                            <Clock className="h-2.5 w-2.5 opacity-30" />
                            <span>
                                {t("currencySettings.lastUpdated")}: {formatDate(updatedAt)}
                            </span>
                        </div>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2">
                <div
                    className="group flex cursor-help items-center gap-1.5 rounded-lg border border-accent/10 bg-accent/5 px-2 py-0.5 shadow-tiny transition-colors hover:bg-accent/10"
                    title={t("currencySettings.tomanPerThousandDinar")}
                >
                    <Coins className="h-2.5 w-2.5 text-accent/60 transition-transform group-hover:rotate-12" />
                    <span className="text-[9px] font-bold font-ibm-plex-arabic tabular-nums leading-none text-accent">
                        {formatNumber(DINAR_BASE)} {t("common.dinar")} = {formatNumber(rate)}{" "}
                        {t("common.toman")}
                    </span>
                </div>

                <button
                    type="button"
                    onClick={fetchRate}
                    disabled={isLoading}
                    className="pointer-events-auto group flex items-center gap-1.5 rounded-lg border border-transparent px-2.5 py-0.5 text-[9px] font-bold font-ibm-plex-arabic text-primary/60 transition-all hover:border-primary/10 hover:bg-primary/5 hover:text-primary active:scale-95 disabled:opacity-50"
                >
                    <RefreshCw
                        className={cn(
                            "h-2.5 w-2.5 transition-transform duration-700",
                            isLoading ? "animate-spin" : "group-hover:rotate-180"
                        )}
                    />
                    <span className="leading-none">{t("common.realtimeUpdate")}</span>
                </button>
            </div>
        </footer>
    );
}
