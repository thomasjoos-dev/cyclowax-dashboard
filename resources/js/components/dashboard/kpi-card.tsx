import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, Minus } from 'lucide-react';

interface KpiCardProps {
    title: string;
    value: string;
    change?: number;
    changeLabel?: string;
}

export function KpiCard({ title, value, change, changeLabel }: KpiCardProps) {
    return (
        <Card>
            <CardContent className="py-0">
                <p className="text-muted-foreground text-sm font-medium">{title}</p>
                <p className="mt-1 text-2xl font-bold tracking-tight">{value}</p>
                {change !== undefined && (
                    <div className="mt-1 flex items-center gap-1 text-sm">
                        <ChangeIndicator change={change} />
                        {changeLabel && <span className="text-muted-foreground">{changeLabel}</span>}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ChangeIndicator({ change }: { change: number }) {
    const isPositive = change > 0;
    const isZero = change === 0;
    const Icon = isZero ? Minus : isPositive ? ArrowUp : ArrowDown;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-medium',
                isPositive && 'text-emerald-600 dark:text-emerald-400',
                !isPositive && !isZero && 'text-red-600 dark:text-red-400',
                isZero && 'text-muted-foreground',
            )}
        >
            <Icon className="size-3.5" />
            {Math.abs(change).toFixed(1)}%
        </span>
    );
}
