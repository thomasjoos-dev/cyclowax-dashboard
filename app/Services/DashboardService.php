<?php

namespace App\Services;

class DashboardService
{
    public function __construct(
        private RevenueAnalyticsService $revenue,
        private AcquisitionAnalyticsService $acquisition,
        private RetentionAnalyticsService $retention,
        private ProductAnalyticsService $product,
    ) {}

    public function kpiMetrics(string $period = 'mtd'): array
    {
        return $this->revenue->kpiMetrics($period);
    }

    public function acquisitionTrend(int $months = 12): array
    {
        return $this->acquisition->acquisitionTrend($months);
    }

    public function acquisitionByRegion(int $limit = 10): array
    {
        return $this->acquisition->acquisitionByRegion($limit);
    }

    public function regionGrowthRates(): array
    {
        return $this->acquisition->regionGrowthRates();
    }

    public function orderTypeSplit(int $months = 12): array
    {
        return $this->retention->orderTypeSplit($months);
    }

    public function revenueSplit(int $months = 12): array
    {
        return $this->revenue->revenueSplit($months);
    }

    public function cohortRetention(int $cohortMonths = 12): array
    {
        return $this->retention->cohortRetention($cohortMonths);
    }

    public function timeToSecondOrder(): array
    {
        return $this->retention->timeToSecondOrder();
    }

    public function retentionByRegion(int $limit = 15): array
    {
        return $this->retention->retentionByRegion($limit);
    }

    public function aovTrend(int $months = 12): array
    {
        return $this->revenue->aovTrend($months);
    }

    public function topProductsFirstOrder(int $limit = 10): array
    {
        return $this->product->topProductsFirstOrder($limit);
    }

    public function topProductsReturning(int $limit = 10): array
    {
        return $this->product->topProductsReturning($limit);
    }

    public function flushCache(): void
    {
        $this->revenue->flushCache();
        $this->acquisition->flushCache();
        $this->retention->flushCache();
        $this->product->flushCache();
    }
}
