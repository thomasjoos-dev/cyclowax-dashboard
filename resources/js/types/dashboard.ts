export interface KpiMetrics {
    revenue: number;
    revenue_change: number;
    orders: number;
    orders_change: number;
    new_customers: number;
    new_customers_change: number;
    returning_rate: number;
}

export interface AcquisitionTrendItem {
    month: string;
    count: number;
}

export interface RegionItem {
    country_code: string;
    count: number;
    percentage: number;
}

export interface RegionGrowthTopItem {
    country_code: string;
    trend: { month: string; count: number }[];
    current: number;
    growth: number;
}

export interface RegionGrowthOtherItem {
    country_code: string;
    current: number;
    growth: number;
}

export interface RegionGrowthData {
    top: RegionGrowthTopItem[];
    other: RegionGrowthOtherItem[];
}

export interface OrderTypeSplitItem {
    month: string;
    first_pct: number;
    returning_pct: number;
    first_count: number;
    returning_count: number;
}

export interface RevenueSplitItem {
    month: string;
    new_revenue: number;
    returning_revenue: number;
}

export interface CohortRow {
    cohort: string;
    size: number;
    retention: Record<number, number>;
}

export interface CohortRetention {
    cohorts: CohortRow[];
    max_months: number;
}

export interface TimeToSecondOrder {
    median_days: number;
    total_returning: number;
    curve: { days: number; cumulative_pct: number }[];
    milestones: Record<string, number>;
}

export interface RetentionByRegionItem {
    country_code: string;
    total_customers: number;
    returning_customers: number;
    retention_pct: number;
}

export interface AovTrendItem {
    month: string;
    first_aov: number;
    returning_aov: number;
}

export interface ProductItem {
    product_title: string;
    count: number;
    percentage: number;
}

export interface DashboardProps {
    period: string;
    kpi: KpiMetrics;
    acquisitionTrend: AcquisitionTrendItem[] | null;
    acquisitionByRegion: RegionItem[] | null;
    regionGrowthRates: RegionGrowthData | null;
    orderTypeSplit: OrderTypeSplitItem[] | null;
    revenueSplit: RevenueSplitItem[] | null;
    cohortRetention: CohortRetention | null;
    timeToSecondOrder: TimeToSecondOrder | null;
    retentionByRegion: RetentionByRegionItem[] | null;
    aovTrend: AovTrendItem[] | null;
    topProductsFirst: ProductItem[] | null;
    topProductsReturning: ProductItem[] | null;
}
