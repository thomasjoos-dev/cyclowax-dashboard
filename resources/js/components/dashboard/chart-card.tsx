import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

interface ChartCardProps {
    title: string;
    description?: string;
    children: React.ReactNode;
    loading?: boolean;
    className?: string;
}

export function ChartCard({ title, description, children, loading, className }: ChartCardProps) {
    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent>{loading ? <Skeleton className="h-64 w-full" /> : children}</CardContent>
        </Card>
    );
}
