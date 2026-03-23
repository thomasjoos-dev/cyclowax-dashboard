import { router } from '@inertiajs/react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

interface PeriodSelectorProps {
    value: string;
}

const periods = [
    { value: 'mtd', label: 'MTD' },
    { value: 'qtd', label: 'QTD' },
    { value: 'ytd', label: 'YTD' },
];

export function PeriodSelector({ value }: PeriodSelectorProps) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(period) => {
                if (period) {
                    router.get(window.location.pathname, { period }, { preserveState: true, preserveScroll: true });
                }
            }}
        >
            {periods.map((p) => (
                <ToggleGroupItem key={p.value} value={p.value} size="sm">
                    {p.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
