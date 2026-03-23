import type { CohortRetention } from '@/types';
import { cn } from '@/lib/utils';

interface CohortHeatmapProps {
    data: CohortRetention;
}

function retentionColor(value: number): string {
    if (value >= 30) return 'bg-emerald-600 text-white dark:bg-emerald-500';
    if (value >= 20) return 'bg-emerald-500 text-white dark:bg-emerald-400 dark:text-emerald-950';
    if (value >= 15) return 'bg-emerald-400 text-emerald-950 dark:bg-emerald-300';
    if (value >= 10) return 'bg-emerald-300 text-emerald-950 dark:bg-emerald-200';
    if (value >= 5) return 'bg-emerald-200 text-emerald-900 dark:bg-emerald-100';
    if (value > 0) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-50';
    return 'bg-muted text-muted-foreground';
}

export function CohortHeatmap({ data }: CohortHeatmapProps) {
    const months = Array.from({ length: data.max_months }, (_, i) => i + 1);

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        <th className="text-muted-foreground px-2 py-1.5 text-left font-medium">Cohort</th>
                        <th className="text-muted-foreground px-2 py-1.5 text-right font-medium">Size</th>
                        {months.map((m) => (
                            <th key={m} className="text-muted-foreground px-2 py-1.5 text-center font-medium">
                                M+{m}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {data.cohorts.map((cohort) => (
                        <tr key={cohort.cohort}>
                            <td className="px-2 py-1.5 font-medium">{cohort.cohort}</td>
                            <td className="text-muted-foreground px-2 py-1.5 text-right">{cohort.size}</td>
                            {months.map((m) => {
                                const value = cohort.retention[m];
                                return (
                                    <td key={m} className="px-1 py-1">
                                        {value !== undefined ? (
                                            <div
                                                className={cn(
                                                    'rounded px-2 py-1 text-center text-xs font-medium',
                                                    retentionColor(value),
                                                )}
                                            >
                                                {value}%
                                            </div>
                                        ) : (
                                            <div className="px-2 py-1 text-center text-xs text-muted-foreground">—</div>
                                        )}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
