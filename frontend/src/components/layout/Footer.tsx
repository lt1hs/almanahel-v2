"use client";

import React from "react";
import { RefreshCw, Coins, Database, Server, Clock } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";
import { cn } from "@/lib/utils";

export function Footer() {
    const { t, formatNumber } = useTranslation();

    return (
        <footer className="h-9 border-t border-white/40 bg-white/80 backdrop-blur-2xl fixed bottom-0 md:relative z-30 px-6 flex items-center justify-between pointer-events-none md:pointer-events-auto shadow-[0_-2px_10px_rgba(0,0,0,0.02)]">
            <div className="flex items-center gap-5">
                {/* System Status - Slim */}
                <div className="flex items-center gap-1.5 group cursor-default">
                    <div className="relative">
                        <div className="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse" />
                        <div className="absolute inset-0 w-1.5 h-1.5 bg-emerald-500 rounded-full animate-ping opacity-20" />
                    </div>
                    <span className="text-[9px] font-vazirmatn font-bold text-ink/30 group-hover:text-emerald-600 transition-colors uppercase tracking-tight">
                        {t("common.dbConnected")}
                    </span>
                </div>

                {/* System Meta - Slimmer */}
                <div className="hidden lg:flex items-center gap-4">
                    <div className="flex items-center gap-1.5 text-[9px] text-ink/20 font-bold tracking-tighter uppercase">
                        <Server className="w-2.5 h-2.5 opacity-30" />
                        <span>v1.0.2</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-[9px] text-ink/20 font-medium lowercase italic">
                        <Clock className="w-2.5 h-2.5 opacity-30" />
                        <span>{t("common.lastSync")}: 12:45:02</span>
                    </div>
                </div>
            </div>

            <div className="flex items-center gap-2">
                {/* Exchange Rate Mini-Badge - Slim */}
                <div className="flex items-center gap-1.5 px-2 py-0.5 bg-accent/5 rounded-lg border border-accent/10 hover:bg-accent/10 transition-colors cursor-help group shadow-tiny">
                    <Coins className="w-2.5 h-2.5 text-accent/60 group-hover:rotate-12 transition-transform" />
                    <span className="text-[9px] font-bold text-accent font-vazirmatn tabular-nums leading-none">
                        1 IQD = 45 IRR
                    </span>
                </div>

                {/* Realtime Action - Slim */}
                <button className="flex items-center gap-1.5 px-2.5 py-0.5 rounded-lg text-[9px] font-vazirmatn font-bold text-primary/60 hover:text-primary hover:bg-primary/5 active:scale-95 transition-all pointer-events-auto group border border-transparent hover:border-primary/10">
                    <RefreshCw className="w-2.5 h-2.5 group-hover:rotate-180 transition-transform duration-700" />
                    <span className="leading-none">{t("common.realtimeUpdate")}</span>
                </button>
            </div>
        </footer>
    );
}
