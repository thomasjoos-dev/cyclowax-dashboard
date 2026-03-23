import { Head } from '@inertiajs/react';
import { DocsBadge, DocsCode, DocsList, DocsSection, DocsTable, DocsText } from '@/components/docs/docs-layout';
import AppLayout from '@/layouts/app-layout';

import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documentation', href: '/docs/api' },
    { title: 'Styleguide', href: '/docs/styleguide' },
];

export default function DocsStyleguide() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Styleguide" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">Styleguide</h1>

                <DocsSection title="Theme">
                    <DocsTable
                        headers={['Setting', 'Value']}
                        rows={[
                            ['Base', 'ShadCN UI (New York style)'],
                            ['Theme', 'tweakcn Lara'],
                            ['Color system', 'OKLCH via CSS custom properties'],
                            ['Dark mode', 'Supported via @custom-variant dark'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Chart colors">
                    <DocsText>Gebruik de chart CSS variabelen voor data visualisatie:</DocsText>
                    <DocsCode>
                        {`const chartColors = {
    primary: 'var(--color-chart-1)',    // Primaire lijn/bar
    secondary: 'var(--color-chart-2)',  // Secundaire lijn/bar
    tertiary: 'var(--color-chart-3)',   // Area charts, tertiaire data
};`}
                    </DocsCode>
                    <div className="flex gap-3">
                        <div className="flex items-center gap-2">
                            <div className="size-4 rounded" style={{ background: 'var(--color-chart-1)' }} />
                            <span className="text-sm">chart-1</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="size-4 rounded" style={{ background: 'var(--color-chart-2)' }} />
                            <span className="text-sm">chart-2</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="size-4 rounded" style={{ background: 'var(--color-chart-3)' }} />
                            <span className="text-sm">chart-3</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="size-4 rounded" style={{ background: 'var(--color-chart-4)' }} />
                            <span className="text-sm">chart-4</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="size-4 rounded" style={{ background: 'var(--color-chart-5)' }} />
                            <span className="text-sm">chart-5</span>
                        </div>
                    </div>
                </DocsSection>

                <DocsSection title="Status colors">
                    <DocsTable
                        headers={['Status', 'Classes']}
                        rows={[
                            ['Groei / positief', 'text-emerald-600 dark:text-emerald-400'],
                            ['Krimp / negatief', 'text-red-600 dark:text-red-400'],
                            ['Neutraal', 'text-muted-foreground'],
                        ]}
                    />
                    <div className="flex gap-4 text-sm font-medium">
                        <span className="text-emerald-600 dark:text-emerald-400">+12.5%</span>
                        <span className="text-red-600 dark:text-red-400">-8.3%</span>
                        <span className="text-muted-foreground">0.0%</span>
                    </div>
                </DocsSection>

                <DocsSection title="Dashboard componenten">
                    <div className="space-y-3">
                        <div>
                            <p className="font-medium">KpiCard</p>
                            <DocsText>KPI metric met waarde en delta indicator.</DocsText>
                            <DocsCode>{'<KpiCard title="Omzet" value="€ 242.525" change={12.5} changeLabel="vs vorige periode" />'}</DocsCode>
                        </div>
                        <div>
                            <p className="font-medium">ChartCard</p>
                            <DocsText>Wrapper voor grafieken met titel, beschrijving en loading state.</DocsText>
                        </div>
                        <div>
                            <p className="font-medium">PeriodSelector</p>
                            <DocsText>MTD / QTD / YTD toggle. Navigeert via Inertia router met preserveState.</DocsText>
                        </div>
                        <div>
                            <p className="font-medium">CohortHeatmap</p>
                            <DocsText>
                                Tabel met kleurgecodeerde retentiepercentages. Kleurschaal van{' '}
                                <DocsBadge>bg-emerald-100</DocsBadge> (laag) tot <DocsBadge>bg-emerald-600</DocsBadge> (hoog).
                            </DocsText>
                        </div>
                        <div>
                            <p className="font-medium">RegionPerformance</p>
                            <DocsText>
                                Regio tabel met sparklines, 6-maanden absolute aantallen, en gemiddelde MoM growth badge.
                            </DocsText>
                        </div>
                        <div>
                            <p className="font-medium">TimeToSecondOrderChart</p>
                            <DocsText>
                                Cumulatieve area chart met milestone badges, mediaan referentielijn, en 25/50/75% referentielijnen.
                            </DocsText>
                        </div>
                    </div>
                </DocsSection>

                <DocsSection title="Formatting conventies">
                    <DocsTable
                        headers={['Type', 'Format', 'Voorbeeld']}
                        rows={[
                            ['Valuta', "Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' })", '€ 242.525'],
                            ['Getallen', "Intl.NumberFormat('nl-NL')", '1.386'],
                            ['Maandnamen', 'Nederlandse afkortingen in capitals', 'JAN, FEB, MRT, APR, MEI, JUN...'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Layout">
                    <DocsList
                        items={[
                            'Sidebar links (ShadCN collapsible, icon variant)',
                            'Content area rechts met p-4 md:p-6 padding',
                            'Zones gescheiden door h2 section headers',
                            'Grid: lg:grid-cols-2 voor naast-elkaar charts, full width voor tabellen',
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Responsive">
                    <DocsTable
                        headers={['Component', 'Breakpoint']}
                        rows={[
                            ['KPI header', 'sm:grid-cols-2 lg:grid-cols-4'],
                            ['Chart grids', 'lg:grid-cols-2'],
                            ['Tabellen', 'overflow-x-auto wrapper'],
                            ['Approach', 'Mobile-first: single column op small screens'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Iconen">
                    <DocsTable
                        headers={['Gebruik', 'Detail']}
                        rows={[
                            ['Library', 'Lucide React'],
                            ['Sidebar logo', 'Custom SVG wiel-icoon'],
                            ['App naam', 'Cyclowax Dashboard'],
                        ]}
                    />
                </DocsSection>
            </div>
        </AppLayout>
    );
}
