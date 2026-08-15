"use client";

import React, { useEffect, useState, useMemo } from "react";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Tooltip,
    Filler,
    type ChartOptions,
} from "chart.js";
import { Line, Bar } from "react-chartjs-2";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useTranslation } from "@/hooks/useTranslation";

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Tooltip, Filler);

const PRIMARY = "#007A7A";
const ACCENT  = "#D4AF37";
const INK     = "#0D0D0D";

interface ProfitChartsProps {
    currency?: "toman" | "dinar";
}

const baseOptions: Partial<ChartOptions<"line">> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: `${INK}f2`,
            titleColor: "#ffffff55",
            bodyColor: "#ffffff",
            titleFont: { size: 10, weight: "bold" },
            bodyFont: { size: 13, weight: "bold" },
            padding: 12,
            cornerRadius: 10,
            displayColors: false,
            callbacks: {
                label: (ctx) => `  ${ctx.parsed.y.toLocaleString()}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: `${INK}55`, font: { size: 10, weight: "bold" } },
        },
        y: {
            beginAtZero: true,
            grid: { color: `${INK}08` },
            border: { display: false },
            ticks: {
                color: `${INK}40`,
                font: { size: 9 },
                maxTicksLimit: 5,
                callback: (v) => {
                    const n = Number(v);
                    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
                    if (n >= 1_000) return `${(n / 1_000).toFixed(0)}K`;
                    return String(n);
                },
            },
        },
    },
};

export function ProfitCharts({ currency = "toman" }: ProfitChartsProps) {
    const { t } = useTranslation();
    const [period, setPeriod] = useState(6);
    const [trends, setTrends] = useState<{ label: string; month: number; sales: number; profit: number }[]>([]);
    const [isLoading, setIsLoading] = useState(true);

    const PERIODS = useMemo(() => [
        { key: 3, label: t("finance.charts.period3m") },
        { key: 6, label: t("finance.charts.period6m") },
        { key: 12, label: t("finance.charts.periodYear") },
    ] as const, [t]);

    useEffect(() => {
        setIsLoading(true);
        apiRequest(`/reports/monthly-trends?months=${period}&currency=${currency}`)
            .then((data) => setTrends(Array.isArray(data) ? data : []))
            .catch(() => setTrends([]))
            .finally(() => setIsLoading(false));
    }, [period, currency]);

    const labels = useMemo(
        () => trends.map((item) => t(`finance.charts.months.${item.month}`) || item.label),
        [trends, t]
    );

    const salesData = useMemo(() => ({
        labels,
        datasets: [{
            data: trends.map((item) => item.sales),
            borderColor: PRIMARY,
            backgroundColor: (ctx: { chart: { ctx: CanvasRenderingContext2D; chartArea?: { top: number; bottom: number } } }) => {
                const { chart } = ctx;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return `${PRIMARY}10`;
                const grad = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                grad.addColorStop(0, `${PRIMARY}30`);
                grad.addColorStop(1, `${PRIMARY}00`);
                return grad;
            },
            pointBackgroundColor: PRIMARY,
            pointBorderColor: "#fff",
            pointBorderWidth: 2,
            pointRadius: 4,
            fill: true,
            tension: 0.42,
            borderWidth: 2,
        }],
    }), [trends, labels]);

    const profitData = useMemo(() => ({
        labels,
        datasets: [{
            data: trends.map((item) => item.profit),
            backgroundColor: (ctx: { chart: { ctx: CanvasRenderingContext2D; chartArea?: { top: number; bottom: number } } }) => {
                const { chart } = ctx;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return `${ACCENT}bb`;
                const grad = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                grad.addColorStop(0, `${ACCENT}ee`);
                grad.addColorStop(1, `${ACCENT}66`);
                return grad;
            },
            hoverBackgroundColor: ACCENT,
            borderRadius: 7,
            borderSkipped: false,
        }],
    }), [trends, labels]);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <ChartCard title={t("finance.charts.salesTrend")} accentClass="border-t-primary/60" period={period} onPeriodChange={setPeriod} isLoading={isLoading} periods={PERIODS}>
                {trends.length > 0 ? (
                    <Line data={salesData} options={baseOptions as ChartOptions<"line">} />
                ) : (
                    <EmptyChart isLoading={isLoading} loadingLabel={t("common.loading")} emptyLabel={t("finance.charts.noData")} />
                )}
            </ChartCard>

            <ChartCard title={t("finance.charts.netProfitMonthly")} accentClass="border-t-accent/60" period={period} onPeriodChange={setPeriod} isLoading={isLoading} periods={PERIODS}>
                {trends.length > 0 ? (
                    <Bar data={profitData} options={baseOptions as ChartOptions<"bar">} />
                ) : (
                    <EmptyChart isLoading={isLoading} loadingLabel={t("common.loading")} emptyLabel={t("finance.charts.noData")} />
                )}
            </ChartCard>
        </div>
    );
}

function EmptyChart({ isLoading, loadingLabel, emptyLabel }: { isLoading: boolean; loadingLabel: string; emptyLabel: string }) {
    return (
        <div className="h-full flex items-center justify-center text-ink/25 text-[10px] font-black uppercase tracking-widest">
            {isLoading ? loadingLabel : emptyLabel}
        </div>
    );
}

function ChartCard({ title, accentClass, period, onPeriodChange, isLoading, periods, children }: {
    title: string;
    accentClass: string;
    period: number;
    onPeriodChange: (p: number) => void;
    isLoading?: boolean;
    periods: readonly { key: number; label: string }[];
    children: React.ReactNode;
}) {
    return (
        <Card className={cn(
            "border border-white/70 bg-white/50 backdrop-blur-md shadow-sm hover:shadow-lg transition-all duration-300 rounded-2xl overflow-hidden border-t-2",
            accentClass
        )}>
            <CardHeader className="px-5 py-3.5 border-b border-ink/[0.05] bg-white/30 flex flex-row items-center justify-between gap-3">
                <CardTitle className="text-[13px] font-black font-vazirmatn text-ink">{title}</CardTitle>
                <div className="flex gap-0.5 bg-parchment/60 border border-ink/6 rounded-lg p-0.5">
                    {periods.map((p) => (
                        <button key={p.key} type="button" onClick={() => onPeriodChange(p.key)} disabled={isLoading}
                            className={cn(
                                "px-2.5 py-1 text-[9px] font-black rounded-md transition-all duration-150",
                                period === p.key ? "bg-white shadow-sm text-primary" : "text-ink/30 hover:text-ink/60"
                            )}>
                            {p.label}
                        </button>
                    ))}
                </div>
            </CardHeader>
            <CardContent className="p-5">
                <div className="h-[200px] w-full">{children}</div>
            </CardContent>
        </Card>
    );
}
