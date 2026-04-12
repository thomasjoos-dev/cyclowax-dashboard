import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { OrderTypeSplitItem } from '@/types/dashboard';

export function OrderTypeSplitChart({ data }: { data: OrderTypeSplitItem[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                <YAxis tick={{ fontSize: 12 }} unit="%" />
                <Tooltip formatter={(value: number) => `${value}%`} />
                <Legend />
                <Bar dataKey="first_pct" name="First order" fill="var(--color-chart-1)" stackId="a" />
                <Bar dataKey="returning_pct" name="Returning" fill="var(--color-chart-2)" stackId="a" radius={[4, 4, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
