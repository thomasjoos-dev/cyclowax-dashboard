import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { formatCurrency } from '@/lib/formatters';
import type { AovTrendItem } from '@/types/dashboard';

export function AovTrendChart({ data }: { data: AovTrendItem[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                <YAxis tick={{ fontSize: 12 }} tickFormatter={(v) => `\u20AC${v}`} />
                <Tooltip formatter={(value: number) => formatCurrency(value)} />
                <Legend />
                <Line type="monotone" dataKey="first_aov" name="First order" stroke="var(--color-chart-1)" strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="returning_aov" name="Returning" stroke="var(--color-chart-2)" strokeWidth={2} dot={false} />
            </LineChart>
        </ResponsiveContainer>
    );
}
