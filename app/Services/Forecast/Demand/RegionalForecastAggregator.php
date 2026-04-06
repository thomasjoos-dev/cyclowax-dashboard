<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Enums\Warehouse;
use App\Models\Scenario;

class RegionalForecastAggregator
{
    public function __construct(
        private DemandForecastService $demandForecast,
        private RegionalCostService $costService,
    ) {}

    /**
     * Generate forecasts for all regions and aggregate into a total.
     *
     * @return array{
     *     total: array<int, array<string, array{units: int, revenue: float}>>,
     *     regions: array<string, array<int, array<string, array{units: int, revenue: float}>>>,
     *     year_total: array{units: int, revenue: float},
     *     region_totals: array<string, array{units: int, revenue: float, cm1: array}>,
     *     cm1_total: array{net_revenue: float, cogs: float, shipping_cost: float, payment_fee: float, cm1: float, cm1_pct: float}
     * }
     */
    public function forecastAllRegions(Scenario $scenario, int $year): array
    {
        $regionForecasts = [];
        $regionTotals = [];

        foreach (ForecastRegion::cases() as $region) {
            $forecast = $this->demandForecast->forecastYear($scenario, $year, $region);
            $regionForecasts[$region->value] = $forecast;

            // Collect yearly category totals for CM1 calculation
            $categoryTotals = [];
            $units = 0;
            $revenue = 0.0;

            for ($m = 1; $m <= 12; $m++) {
                foreach ($forecast[$m] ?? [] as $catValue => $cat) {
                    $units += $cat['units'];
                    $revenue += $cat['revenue'];

                    if (! isset($categoryTotals[$catValue])) {
                        $categoryTotals[$catValue] = ['units' => 0, 'revenue' => 0.0];
                    }
                    $categoryTotals[$catValue]['units'] += $cat['units'];
                    $categoryTotals[$catValue]['revenue'] += $cat['revenue'];
                }
            }

            $cm1 = $this->costService->calculateCm1($categoryTotals, $region);

            $regionTotals[$region->value] = [
                'units' => $units,
                'revenue' => round($revenue, 2),
                'cm1' => $cm1,
            ];
        }

        // Aggregate: total = sum of all regions
        $total = [];
        $yearUnits = 0;
        $yearRevenue = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $monthTotal = [];

            foreach ($regionForecasts as $forecast) {
                foreach ($forecast[$month] ?? [] as $catValue => $data) {
                    if (! isset($monthTotal[$catValue])) {
                        $monthTotal[$catValue] = [
                            'units' => 0,
                            'revenue' => 0.0,
                            'seasonal_index' => $data['seasonal_index'],
                            'event_boost' => $data['event_boost'],
                            'pull_forward' => $data['pull_forward'],
                        ];
                    }

                    $monthTotal[$catValue]['units'] += $data['units'];
                    $monthTotal[$catValue]['revenue'] += $data['revenue'];
                    $monthTotal[$catValue]['event_boost'] += $data['event_boost'];
                    $monthTotal[$catValue]['pull_forward'] += $data['pull_forward'];
                }
            }

            // Round aggregated values
            foreach ($monthTotal as $catValue => $data) {
                $monthTotal[$catValue]['revenue'] = round($data['revenue'], 2);
                $monthTotal[$catValue]['event_boost'] = round($data['event_boost'], 2);
                $monthTotal[$catValue]['pull_forward'] = round($data['pull_forward'], 2);
            }

            $total[$month] = $monthTotal;

            $yearUnits += collect($monthTotal)->sum('units');
            $yearRevenue += collect($monthTotal)->sum('revenue');
        }

        // Aggregate CM1 across all regions
        $cm1Total = [
            'net_revenue' => 0.0,
            'cogs' => 0.0,
            'shipping_cost' => 0.0,
            'payment_fee' => 0.0,
            'cm1' => 0.0,
        ];
        foreach ($regionTotals as $rt) {
            $cm1Total['net_revenue'] += $rt['cm1']['net_revenue'];
            $cm1Total['cogs'] += $rt['cm1']['cogs'];
            $cm1Total['shipping_cost'] += $rt['cm1']['shipping_cost'];
            $cm1Total['payment_fee'] += $rt['cm1']['payment_fee'];
            $cm1Total['cm1'] += $rt['cm1']['cm1'];
        }
        $cm1Total['net_revenue'] = round($cm1Total['net_revenue'], 2);
        $cm1Total['cogs'] = round($cm1Total['cogs'], 2);
        $cm1Total['shipping_cost'] = round($cm1Total['shipping_cost'], 2);
        $cm1Total['payment_fee'] = round($cm1Total['payment_fee'], 2);
        $cm1Total['cm1'] = round($cm1Total['cm1'], 2);
        $cm1Total['cm1_pct'] = $cm1Total['net_revenue'] > 0
            ? round($cm1Total['cm1'] / $cm1Total['net_revenue'] * 100, 1)
            : 0.0;

        return [
            'total' => $total,
            'regions' => $regionForecasts,
            'year_total' => [
                'units' => $yearUnits,
                'revenue' => round($yearRevenue, 2),
            ],
            'region_totals' => $regionTotals,
            'cm1_total' => $cm1Total,
        ];
    }

    /**
     * Aggregate regional forecasts per warehouse.
     *
     * @return array<string, array{units: int, revenue: float, cm1: float, cm1_pct: float, regions: array}>
     */
    public function forecastByWarehouse(Scenario $scenario, int $year): array
    {
        $allRegions = $this->forecastAllRegions($scenario, $year);
        $warehouseTotals = [];

        foreach (Warehouse::cases() as $warehouse) {
            $units = 0;
            $revenue = 0.0;
            $cm1 = 0.0;
            $warehouseRegions = [];

            foreach ($warehouse->regions() as $region) {
                $regionTotal = $allRegions['region_totals'][$region->value] ?? ['units' => 0, 'revenue' => 0.0, 'cm1' => ['cm1' => 0.0]];
                $units += $regionTotal['units'];
                $revenue += $regionTotal['revenue'];
                $cm1 += $regionTotal['cm1']['cm1'] ?? 0.0;
                $warehouseRegions[$region->value] = $regionTotal;
            }

            $warehouseTotals[$warehouse->value] = [
                'units' => $units,
                'revenue' => round($revenue, 2),
                'cm1' => round($cm1, 2),
                'cm1_pct' => $revenue > 0 ? round($cm1 / $revenue * 100, 1) : 0.0,
                'regions' => $warehouseRegions,
            ];
        }

        return $warehouseTotals;
    }
}
