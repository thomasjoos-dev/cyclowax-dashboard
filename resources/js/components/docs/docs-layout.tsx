import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export function DocsSection({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-lg">{title}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

export function DocsTable({ headers, rows }: { headers: string[]; rows: string[][] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        {headers.map((h) => (
                            <th key={h} className="text-muted-foreground border-b px-3 py-2 text-left font-bold">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b last:border-0">
                            {row.map((cell, j) => (
                                <td key={j} className="px-3 py-2">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function DocsCode({ children }: { children: string }) {
    return <pre className="bg-muted overflow-x-auto rounded-lg p-4 font-mono text-sm">{children}</pre>;
}

export function DocsBadge({ children }: { children: React.ReactNode }) {
    return (
        <code className="bg-muted rounded px-1.5 py-0.5 font-mono text-sm">{children}</code>
    );
}

export function DocsList({ items }: { items: string[] }) {
    return (
        <ul className="text-muted-foreground list-inside list-disc space-y-1 text-sm">
            {items.map((item, i) => (
                <li key={i}>{item}</li>
            ))}
        </ul>
    );
}

export function DocsText({ children }: { children: React.ReactNode }) {
    return <p className="text-muted-foreground text-sm leading-relaxed">{children}</p>;
}
