import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { AcquisitionTrendItem } from '@/types/dashboard';

export function AcquisitionTrendChart({ data }: { data: AcquisitionTrendItem[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                <YAxis tick={{ fontSize: 12 }} />
                <Tooltip />
                <Line type="monotone" dataKey="count" name="Nieuwe klanten" stroke="var(--color-chart-1)" strokeWidth={2} dot={false} />
            </LineChart>
        </ResponsiveContainer>
    );
}
