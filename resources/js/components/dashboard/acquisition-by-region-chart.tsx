import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { RegionItem } from '@/types/dashboard';

export function AcquisitionByRegionChart({ data }: { data: RegionItem[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
            <BarChart data={data} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis type="number" tick={{ fontSize: 12 }} />
                <YAxis dataKey="country_code" type="category" tick={{ fontSize: 12 }} width={40} />
                <Tooltip
                    formatter={(value: number, name: string) => [
                        name === 'percentage' ? `${value}%` : value,
                        name === 'percentage' ? 'Aandeel' : 'Aantal',
                    ]}
                />
                <Bar dataKey="count" name="Klanten" fill="var(--color-chart-1)" radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
