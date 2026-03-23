import { useState } from 'react';
import { Line, LineChart, ResponsiveContainer } from 'recharts';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RegionGrowthData } from '@/types';

interface RegionPerformanceProps {
    data: RegionGrowthData;
}

function GrowthBadge({ growth }: { growth: number }) {
    const isPositive = growth > 0;
    const isZero = growth === 0;

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                isPositive && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                !isPositive && !isZero && 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                isZero && 'bg-muted text-muted-foreground',
            )}
        >
            {isPositive ? '+' : ''}
            {growth.toFixed(0)}%
        </span>
    );
}

function Sparkline({ data }: { data: { month: string; count: number }[] }) {
    return (
        <ResponsiveContainer width={100} height={28}>
            <LineChart data={data}>
                <Line
                    type="monotone"
                    dataKey="count"
                    stroke="var(--color-chart-1)"
                    strokeWidth={1.5}
                    dot={false}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

export function RegionPerformance({ data }: RegionPerformanceProps) {
    const [showOther, setShowOther] = useState(false);

    const monthNames = ['JAN', 'FEB', 'MRT', 'APR', 'MEI', 'JUN', 'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEC'];
    const monthLabels = data.top[0]?.trend.map((t) => monthNames[parseInt(t.month.slice(5), 10) - 1]) ?? [];

    return (
        <div className="space-y-4">
            {/* Top regions with monthly counts + sparkline */}
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className="text-muted-foreground px-3 py-2 text-left font-medium">Regio</th>
                            <th className="text-muted-foreground px-3 py-2 text-left font-medium">Trend</th>
                            {monthLabels.map((label) => (
                                <th key={label} className="text-muted-foreground px-2 py-2 text-right font-bold">
                                    {label}
                                </th>
                            ))}
                            <th className="text-muted-foreground px-3 py-2 text-right font-medium">Gem. MoM</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.top.map((region) => (
                            <tr key={region.country_code} className="border-t">
                                <td className="px-3 py-2 font-medium">{region.country_code}</td>
                                <td className="px-2 py-1.5">
                                    <Sparkline data={region.trend} />
                                </td>
                                {region.trend.map((t) => (
                                    <td
                                        key={t.month}
                                        className={cn(
                                            'px-2 py-2 text-right tabular-nums',
                                            t.month === region.trend[region.trend.length - 1].month
                                                ? 'font-medium'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {t.count}
                                    </td>
                                ))}
                                <td className="px-3 py-2 text-right">
                                    <GrowthBadge growth={region.growth} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Other regions (expandable) */}
            {data.other.length > 0 && (
                <div>
                    <button
                        onClick={() => setShowOther(!showOther)}
                        className="text-muted-foreground hover:text-foreground flex w-full items-center gap-1.5 px-3 py-2 text-sm transition-colors"
                    >
                        {showOther ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                        {data.other.length} overige regio's
                        <span className="text-muted-foreground/60">(&lt;20 nieuwe klanten)</span>
                    </button>

                    {showOther && (
                        <div className="grid grid-cols-2 gap-x-6 gap-y-1 px-3 pt-1 sm:grid-cols-3 md:grid-cols-4">
                            {data.other.map((region) => (
                                <div key={region.country_code} className="flex items-center justify-between py-1 text-sm">
                                    <span className="text-muted-foreground">{region.country_code}</span>
                                    <span className="tabular-nums">{region.current}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
