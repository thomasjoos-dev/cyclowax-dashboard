import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { formatCurrency } from '@/lib/formatters';
import type { RevenueSplitItem } from '@/types/dashboard';

export function RevenueSplitChart({ data }: { data: RevenueSplitItem[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                <YAxis tick={{ fontSize: 12 }} tickFormatter={(v) => `\u20AC${(v / 1000).toFixed(0)}k`} />
                <Tooltip formatter={(value: number) => formatCurrency(value)} />
                <Legend />
                <Bar dataKey="new_revenue" name="New" fill="var(--color-chart-1)" stackId="a" />
                <Bar dataKey="returning_revenue" name="Returning" fill="var(--color-chart-2)" stackId="a" radius={[4, 4, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
