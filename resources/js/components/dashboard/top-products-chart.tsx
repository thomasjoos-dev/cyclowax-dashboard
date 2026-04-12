import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { ProductItem } from '@/types/dashboard';

interface TopProductsChartProps {
    data: ProductItem[];
    color?: string;
}

export function TopProductsChart({ data, color = 'var(--color-chart-1)' }: TopProductsChartProps) {
    return (
        <ResponsiveContainer width="100%" height={Math.max(280, data.length * 36)}>
            <BarChart data={data} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis type="number" tick={{ fontSize: 12 }} unit="%" />
                <YAxis dataKey="product_title" type="category" tick={{ fontSize: 11 }} width={160} />
                <Tooltip formatter={(value: number) => `${value}%`} />
                <Bar dataKey="percentage" name="Aandeel" fill={color} radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
