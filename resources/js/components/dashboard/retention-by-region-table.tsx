import type { RetentionByRegionItem } from '@/types/dashboard';

export function RetentionByRegionTable({ data }: { data: RetentionByRegionItem[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        <th className="text-muted-foreground px-3 py-2 text-left font-medium">Regio</th>
                        <th className="text-muted-foreground px-3 py-2 text-right font-medium">Klanten</th>
                        <th className="text-muted-foreground px-3 py-2 text-right font-medium">Returning</th>
                        <th className="text-muted-foreground px-3 py-2 text-right font-medium">Retentie</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((row) => (
                        <tr key={row.country_code} className="border-t">
                            <td className="px-3 py-2 font-medium">{row.country_code}</td>
                            <td className="px-3 py-2 text-right">{row.total_customers}</td>
                            <td className="px-3 py-2 text-right">{row.returning_customers}</td>
                            <td className="px-3 py-2 text-right font-medium">{row.retention_pct}%</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
