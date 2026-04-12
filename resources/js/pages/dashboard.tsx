import { Head } from '@inertiajs/react';
import { AcquisitionByRegionChart } from '@/components/dashboard/acquisition-by-region-chart';
import { AcquisitionTrendChart } from '@/components/dashboard/acquisition-trend-chart';
import { AovTrendChart } from '@/components/dashboard/aov-trend-chart';
import { ChartCard } from '@/components/dashboard/chart-card';
import { CohortHeatmap } from '@/components/dashboard/cohort-heatmap';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { OrderTypeSplitChart } from '@/components/dashboard/order-type-split-chart';
import { PeriodSelector } from '@/components/dashboard/period-selector';
import { RegionPerformance } from '@/components/dashboard/region-performance';
import { RetentionByRegionTable } from '@/components/dashboard/retention-by-region-table';
import { RevenueSplitChart } from '@/components/dashboard/revenue-split-chart';
import { TimeToSecondOrderChart } from '@/components/dashboard/time-to-second-order';
import { TopProductsChart } from '@/components/dashboard/top-products-chart';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, DashboardProps } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: dashboard() }];

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
                    <KpiCard title="Omzet" value={formatCurrency(kpi.revenue)} change={kpi.revenue_change} changeLabel="vs vorige periode" />
                    <KpiCard title="Orders" value={formatNumber(kpi.orders)} change={kpi.orders_change} changeLabel="vs vorige periode" />
                    <KpiCard title="Nieuwe klanten" value={formatNumber(kpi.new_customers)} change={kpi.new_customers_change} changeLabel="vs vorige periode" />
                    <KpiCard title="Returning order rate" value={`${kpi.returning_rate}%`} />
                </div>

                {/* Zone 2: Acquisitie */}
                <h2 className="text-lg font-semibold">Acquisitie</h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Nieuwe klanten per maand" loading={!acquisitionTrend}>
                        {acquisitionTrend && <AcquisitionTrendChart data={acquisitionTrend} />}
                    </ChartCard>
                    <ChartCard title="Nieuwe klanten per regio" description="Top 10 regio's" loading={!acquisitionByRegion}>
                        {acquisitionByRegion && <AcquisitionByRegionChart data={acquisitionByRegion} />}
                    </ChartCard>
                </div>
                <ChartCard title="Regio performance" description="Top regio's met 6-maanden trend" loading={!regionGrowthRates}>
                    {regionGrowthRates && <RegionPerformance data={regionGrowthRates} />}
                </ChartCard>

                {/* Zone 3: Retentie & Herhaalaankoop */}
                <h2 className="text-lg font-semibold">Retentie & Herhaalaankoop</h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="First vs Returning orders" description="Procentuele verhouding per maand" loading={!orderTypeSplit}>
                        {orderTypeSplit && <OrderTypeSplitChart data={orderTypeSplit} />}
                    </ChartCard>
                    <ChartCard title="Revenue split" description="New vs returning omzet per maand" loading={!revenueSplit}>
                        {revenueSplit && <RevenueSplitChart data={revenueSplit} />}
                    </ChartCard>
                </div>
                <ChartCard title="Cohort retentie (cumulatief)" description="% klanten dat herbestelt binnen X maanden na eerste aankoop" loading={!cohortRetention}>
                    {cohortRetention && <CohortHeatmap data={cohortRetention} />}
                </ChartCard>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Time to second order" description="Cumulatief % klanten dat herbestelt binnen X dagen" loading={!timeToSecondOrder}>
                        {timeToSecondOrder && <TimeToSecondOrderChart data={timeToSecondOrder} />}
                    </ChartCard>
                    <ChartCard title="Retentie per regio" description="% returning customers (min. 10 klanten)" loading={!retentionByRegion}>
                        {retentionByRegion && <RetentionByRegionTable data={retentionByRegion} />}
                    </ChartCard>
                </div>

                {/* Zone 4: Product & AOV */}
                <h2 className="text-lg font-semibold">Product & Order Value</h2>
                <ChartCard title="AOV trend" description="Gemiddelde orderwaarde: first order vs returning" loading={!aovTrend}>
                    {aovTrend && <AovTrendChart data={aovTrend} />}
                </ChartCard>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Top producten — First purchase" description="% verdeling in eerste aankopen" loading={!topProductsFirst}>
                        {topProductsFirst && <TopProductsChart data={topProductsFirst} />}
                    </ChartCard>
                    <ChartCard title="Top producten — Returning orders" description="% verdeling in herhaalaankopen" loading={!topProductsReturning}>
                        {topProductsReturning && <TopProductsChart data={topProductsReturning} color="var(--color-chart-2)" />}
                    </ChartCard>
                </div>
            </div>
        </AppLayout>
    );
}
