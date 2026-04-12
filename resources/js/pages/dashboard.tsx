import { Head } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ChartCard } from '@/components/dashboard/chart-card';
import { CohortHeatmap } from '@/components/dashboard/cohort-heatmap';
import { RegionPerformance } from '@/components/dashboard/region-performance';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { PeriodSelector } from '@/components/dashboard/period-selector';
import { TimeToSecondOrderChart } from '@/components/dashboard/time-to-second-order';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, DashboardProps } from '@/types';
import { cn } from '@/lib/utils';
import { formatCurrency, formatNumber } from '@/lib/formatters';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: dashboard() }];

const chartColors = {
    primary: 'var(--color-chart-1)',
    secondary: 'var(--color-chart-2)',
    tertiary: 'var(--color-chart-3)',
};

function DeferredSkeleton() {
    return <Skeleton className="h-64 w-full" />;
}

export default function Dashboard({
    period,
    kpi,
    acquisitionTrend,
    acquisitionByRegion,
    regionGrowthRates,
    orderTypeSplit,
    revenueSplit,
    cohortRetention,
    timeToSecondOrder,
    retentionByRegion,
    aovTrend,
    topProductsFirst,
    topProductsReturning,
}: DashboardProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
                    <PeriodSelector value={period} />
                </div>

                {/* Zone 1: KPI Header */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        title="Omzet"
                        value={formatCurrency(kpi.revenue)}
                        change={kpi.revenue_change}
                        changeLabel="vs vorige periode"
                    />
                    <KpiCard
                        title="Orders"
                        value={formatNumber(kpi.orders)}
                        change={kpi.orders_change}
                        changeLabel="vs vorige periode"
                    />
                    <KpiCard
                        title="Nieuwe klanten"
                        value={formatNumber(kpi.new_customers)}
                        change={kpi.new_customers_change}
                        changeLabel="vs vorige periode"
                    />
                    <KpiCard title="Returning order rate" value={`${kpi.returning_rate}%`} />
                </div>

                {/* Zone 2: Acquisitie */}
                <h2 className="text-lg font-semibold">Acquisitie</h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Nieuwe klanten per maand" loading={!acquisitionTrend}>
                        {acquisitionTrend && (
                            <ResponsiveContainer width="100%" height={280}>
                                <LineChart data={acquisitionTrend}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                    <YAxis tick={{ fontSize: 12 }} />
                                    <Tooltip />
                                    <Line
                                        type="monotone"
                                        dataKey="count"
                                        name="Nieuwe klanten"
                                        stroke={chartColors.primary}
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>

                    <ChartCard title="Nieuwe klanten per regio" description="Top 10 regio's" loading={!acquisitionByRegion}>
                        {acquisitionByRegion && (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={acquisitionByRegion} layout="vertical">
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis type="number" tick={{ fontSize: 12 }} />
                                    <YAxis dataKey="country_code" type="category" tick={{ fontSize: 12 }} width={40} />
                                    <Tooltip formatter={(value: number, name: string) => [name === 'percentage' ? `${value}%` : value, name === 'percentage' ? 'Aandeel' : 'Aantal']} />
                                    <Bar dataKey="count" name="Klanten" fill={chartColors.primary} radius={[0, 4, 4, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                <ChartCard title="Regio performance" description="Top regio's met 6-maanden trend" loading={!regionGrowthRates}>
                    {regionGrowthRates && <RegionPerformance data={regionGrowthRates} />}
                </ChartCard>

                {/* Zone 3: Retentie & Herhaalaankoop */}
                <h2 className="text-lg font-semibold">Retentie & Herhaalaankoop</h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="First vs Returning orders" description="Procentuele verhouding per maand" loading={!orderTypeSplit}>
                        {orderTypeSplit && (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={orderTypeSplit}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                    <YAxis tick={{ fontSize: 12 }} unit="%" />
                                    <Tooltip formatter={(value: number) => `${value}%`} />
                                    <Legend />
                                    <Bar dataKey="first_pct" name="First order" fill={chartColors.primary} stackId="a" />
                                    <Bar dataKey="returning_pct" name="Returning" fill={chartColors.secondary} stackId="a" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>

                    <ChartCard title="Revenue split" description="New vs returning omzet per maand" loading={!revenueSplit}>
                        {revenueSplit && (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={revenueSplit}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                    <YAxis tick={{ fontSize: 12 }} tickFormatter={(v) => `€${(v / 1000).toFixed(0)}k`} />
                                    <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                    <Legend />
                                    <Bar dataKey="new_revenue" name="New" fill={chartColors.primary} stackId="a" />
                                    <Bar dataKey="returning_revenue" name="Returning" fill={chartColors.secondary} stackId="a" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                <ChartCard title="Cohort retentie (cumulatief)" description="% klanten dat herbestelt binnen X maanden na eerste aankoop" loading={!cohortRetention}>
                    {cohortRetention && <CohortHeatmap data={cohortRetention} />}
                </ChartCard>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard
                        title="Time to second order"
                        description="Cumulatief % klanten dat herbestelt binnen X dagen"
                        loading={!timeToSecondOrder}
                    >
                        {timeToSecondOrder && <TimeToSecondOrderChart data={timeToSecondOrder} />}
                    </ChartCard>

                    <ChartCard title="Retentie per regio" description="% returning customers (min. 10 klanten)" loading={!retentionByRegion}>
                        {retentionByRegion && (
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
                                        {retentionByRegion.map((row) => (
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
                        )}
                    </ChartCard>
                </div>

                {/* Zone 4: Product & AOV */}
                <h2 className="text-lg font-semibold">Product & Order Value</h2>
                <ChartCard title="AOV trend" description="Gemiddelde orderwaarde: first order vs returning" loading={!aovTrend}>
                    {aovTrend && (
                        <ResponsiveContainer width="100%" height={280}>
                            <LineChart data={aovTrend}>
                                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                <YAxis tick={{ fontSize: 12 }} tickFormatter={(v) => `€${v}`} />
                                <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                <Legend />
                                <Line type="monotone" dataKey="first_aov" name="First order" stroke={chartColors.primary} strokeWidth={2} dot={false} />
                                <Line type="monotone" dataKey="returning_aov" name="Returning" stroke={chartColors.secondary} strokeWidth={2} dot={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    )}
                </ChartCard>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Top producten — First purchase" description="% verdeling in eerste aankopen" loading={!topProductsFirst}>
                        {topProductsFirst && (
                            <ResponsiveContainer width="100%" height={Math.max(280, topProductsFirst.length * 36)}>
                                <BarChart data={topProductsFirst} layout="vertical">
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis type="number" tick={{ fontSize: 12 }} unit="%" />
                                    <YAxis dataKey="product_title" type="category" tick={{ fontSize: 11 }} width={160} />
                                    <Tooltip formatter={(value: number) => `${value}%`} />
                                    <Bar dataKey="percentage" name="Aandeel" fill={chartColors.primary} radius={[0, 4, 4, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>

                    <ChartCard title="Top producten — Returning orders" description="% verdeling in herhaalaankopen" loading={!topProductsReturning}>
                        {topProductsReturning && (
                            <ResponsiveContainer width="100%" height={Math.max(280, topProductsReturning.length * 36)}>
                                <BarChart data={topProductsReturning} layout="vertical">
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis type="number" tick={{ fontSize: 12 }} unit="%" />
                                    <YAxis dataKey="product_title" type="category" tick={{ fontSize: 11 }} width={160} />
                                    <Tooltip formatter={(value: number) => `${value}%`} />
                                    <Bar dataKey="percentage" name="Aandeel" fill={chartColors.secondary} radius={[0, 4, 4, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>
            </div>
        </AppLayout>
    );
}
