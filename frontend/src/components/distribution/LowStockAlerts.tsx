"use client";

import React, { useState } from "react";
import { AlertTriangle, ArrowRight, Package, MapPin } from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

interface AlertItem {
    id: string;
    title: string;
    branch: string;
    currentStock: number;
    threshold: number;
    priority: "high" | "medium" | "low";
}

interface LowStockAlertsProps {
    alerts: AlertItem[];
    isLoading?: boolean;
    onSelect?: (alert: AlertItem) => void;
}

export function LowStockAlerts({ alerts, isLoading, onSelect }: LowStockAlertsProps) {
    const { t, formatNumber, isArabic } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const highCount = alerts.filter((a) => a.priority === "high").length;
    const visible = expanded ? alerts : alerts.slice(0, 5);

    return (
        <div className="rounded-[15px] border border-rose-100/80 bg-gradient-to-b from-rose-50/40 to-white/80 backdrop-blur-xl shadow-sm overflow-hidden">
            <div className="px-5 py-4 border-b border-rose-100/60 flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-rose-500/10 border border-rose-200/60 flex items-center justify-center">
                        <AlertTriangle className="w-4 h-4 text-rose-500" />
                    </div>
                    <div>
                        <h3 className="text-[13px] font-black font-vazirmatn text-ink">{t("distribution.alerts.title")}</h3>
                        <p className="text-[9px] text-ink/35 font-bold uppercase tracking-widest mt-0.5">{t("distribution.alerts.subtitle")}</p>
                    </div>
                </div>
                {alerts.length > 0 && (
                    <Badge className="text-[9px] font-black border-rose-200 bg-rose-50 text-rose-600 shrink-0">
                        {highCount > 0
                            ? `${formatNumber(highCount)} ${t("distribution.alerts.critical")}`
                            : `${formatNumber(alerts.length)} ${t("distribution.alerts.items")}`}
                    </Badge>
                )}
            </div>

            <div className="divide-y divide-rose-100/40">
                {isLoading ? (
                    Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="p-4 flex gap-3 animate-pulse">
                            <div className="w-9 h-9 bg-rose-100/50 rounded-xl" />
                            <div className="flex-1 space-y-2">
                                <div className="h-3 bg-rose-100/40 rounded w-3/4" />
                                <div className="h-2 bg-rose-100/30 rounded w-1/2" />
                            </div>
                        </div>
                    ))
                ) : alerts.length > 0 ? (
                    visible.map((alert) => (
                        <button
                            key={alert.id}
                            type="button"
                            onClick={() => onSelect?.(alert)}
                            className="group w-full text-start px-5 py-4 flex items-center gap-3 hover:bg-rose-50/30 transition-colors"
                        >
                            <div className={cn(
                                "w-9 h-9 rounded-xl flex items-center justify-center border shrink-0 transition-colors",
                                alert.priority === "high"
                                    ? "bg-rose-100 border-rose-200 text-rose-600"
                                    : "bg-amber-50 border-amber-100 text-amber-600"
                            )}>
                                <Package className="w-4 h-4" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-[11px] font-black font-vazirmatn text-ink truncate group-hover:text-rose-700 transition-colors">
                                    {alert.title}
                                </p>
                                <div className="flex items-center gap-2 mt-1 text-[9px] text-ink/35">
                                    <MapPin className="w-3 h-3 shrink-0" />
                                    <span className="truncate">{alert.branch}</span>
                                </div>
                                <div className="mt-2 h-1.5 bg-ink/5 rounded-full overflow-hidden max-w-[120px]">
                                    <div
                                        className={cn("h-full rounded-full", alert.priority === "high" ? "bg-rose-500" : "bg-amber-400")}
                                        style={{ width: `${Math.min(100, (alert.currentStock / Math.max(alert.threshold, 1)) * 100)}%` }}
                                    />
                                </div>
                            </div>
                            <div className="text-end shrink-0">
                                <p className="text-[15px] font-black font-vazirmatn text-rose-600 tabular-nums">{formatNumber(alert.currentStock)}</p>
                                <p className="text-[8px] text-ink/25">{t("distribution.alerts.threshold")} {formatNumber(alert.threshold)}</p>
                            </div>
                        </button>
                    ))
                ) : (
                    <div className="py-12 text-center">
                        <Package className="w-8 h-8 text-ink/10 mx-auto mb-2" />
                        <p className="text-[10px] font-black text-ink/25 uppercase tracking-widest">{t("distribution.alerts.healthy")}</p>
                    </div>
                )}
            </div>

            {alerts.length > 5 && (
                <button
                    type="button"
                    onClick={() => setExpanded((v) => !v)}
                    className="w-full h-10 text-[10px] font-black text-ink/35 hover:text-rose-600 border-t border-rose-100/50 flex items-center justify-center gap-2 transition-colors"
                >
                    {expanded
                        ? t("distribution.alerts.showLess")
                        : t("distribution.alerts.moreItems", { count: formatNumber(alerts.length - 5) })}
                    <ArrowRight className={cn("w-3 h-3 transition-transform", isArabic && "rotate-180", expanded && "rotate-90")} />
                </button>
            )}
        </div>
    );
}
