import {
    Area,
    AreaChart,
    CartesianGrid,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type { TimeToSecondOrder } from '@/types';

interface TimeToSecondOrderChartProps {
    data: TimeToSecondOrder;
}

export function TimeToSecondOrderChart({ data }: TimeToSecondOrderChartProps) {
    return (
        <div className="space-y-4">
            {/* Milestone badges */}
            <div className="flex flex-wrap gap-3">
                {Object.entries(data.milestones).map(([label, pct]) => (
                    <div key={label} className="bg-muted rounded-lg px-3 py-2 text-center">
                        <div className="text-lg font-bold tabular-nums">{pct}%</div>
                        <div className="text-muted-foreground text-xs">binnen {label}</div>
                    </div>
                ))}
            </div>

            {/* Cumulative curve */}
            <ResponsiveContainer width="100%" height={280}>
                <AreaChart data={data.curve}>
                    <defs>
                        <linearGradient id="fillCumulative" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="var(--color-chart-3)" stopOpacity={0.3} />
                            <stop offset="100%" stopColor="var(--color-chart-3)" stopOpacity={0.02} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                    <XAxis
                        dataKey="days"
                        tick={{ fontSize: 12 }}
                        tickFormatter={(v) => `${v}d`}
                        label={{ value: 'Dagen na eerste order', position: 'insideBottom', offset: -5, fontSize: 12, className: 'fill-muted-foreground' }}
                    />
                    <YAxis
                        tick={{ fontSize: 12 }}
                        domain={[0, 100]}
                        tickFormatter={(v) => `${v}%`}
                    />
                    <Tooltip
                        formatter={(value: number) => [`${value}%`, 'Herbesteld']}
                        labelFormatter={(label) => `Binnen ${label} dagen`}
                    />
                    {/* Reference lines at 25%, 50%, 75% */}
                    <ReferenceLine y={25} stroke="var(--color-border)" strokeDasharray="3 3" />
                    <ReferenceLine y={50} stroke="var(--color-border)" strokeDasharray="3 3" />
                    <ReferenceLine y={75} stroke="var(--color-border)" strokeDasharray="3 3" />
                    {/* Median line */}
                    <ReferenceLine
                        x={data.median_days}
                        stroke="var(--color-chart-1)"
                        strokeDasharray="6 3"
                        strokeWidth={1.5}
                        label={{
                            value: `Mediaan: ${data.median_days}d`,
                            position: 'top',
                            fontSize: 11,
                            className: 'fill-foreground',
                        }}
                    />
                    <Area
                        type="monotone"
                        dataKey="cumulative_pct"
                        stroke="var(--color-chart-3)"
                        strokeWidth={2}
                        fill="url(#fillCumulative)"
                        dot={{ r: 3, fill: 'var(--color-chart-3)' }}
                    />
                </AreaChart>
            </ResponsiveContainer>

            <p className="text-muted-foreground text-xs">
                Gebaseerd op {data.total_returning.toLocaleString('nl-NL')} klanten met een herhaalaankoop
            </p>
        </div>
    );
}
