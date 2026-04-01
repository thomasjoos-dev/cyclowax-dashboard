<?php

namespace App\Services\Forecast\Tracking;

use App\Models\ForecastSnapshot;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Support\DbDialect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ForecastTrackingService
{
    public function __construct(
        private DemandForecastService $demandForecastService,
    ) {}

    /**
     * Generate a forecast and save it as snapshot rows.
     *
     * @return int Number of snapshot rows created
     */
    public function recordSnapshot(Scenario $scenario, int $year): int
    {
        $forecast = $this->demandForecastService->forecastYear($scenario, $year);
        $count = 0;

        for ($month = 1; $month <= 12; $month++) {
            $yearMonth = sprintf('%d-%02d', $year, $month);
            $monthData = $forecast[$month] ?? [];

            $totalUnits = 0;
            $totalRevenue = 0;

            foreach ($monthData as $categoryValue => $data) {
                ForecastSnapshot::updateOrCreate(
                    [
                        'scenario_id' => $scenario->id,
                        'year_month' => $yearMonth,
                        'product_category' => $categoryValue,
                    ],
                    [
                        'forecasted_units' => $data['units'],
                        'forecasted_revenue' => $data['revenue'],
                        'created_at' => now(),
                    ],
                );

                $totalUnits += $data['units'];
                $totalRevenue += $data['revenue'];
                $count++;
            }

            // Store totals row (null product_category)
            ForecastSnapshot::updateOrCreate(
                [
                    'scenario_id' => $scenario->id,
                    'year_month' => $yearMonth,
                    'product_category' => null,
                ],
                [
                    'forecasted_units' => $totalUnits,
                    'forecasted_revenue' => round($totalRevenue, 2),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Fill in actual units and revenue for a completed month.
     *
     * @return int Number of snapshots updated
     */
    public function updateActuals(string $yearMonth): int
    {
        $actuals = $this->getActualsByCategory($yearMonth);
        $totalActualUnits = 0;
        $totalActualRevenue = 0;

        $updated = 0;

        foreach ($actuals as $row) {
            $affected = ForecastSnapshot::where('year_month', $yearMonth)
                ->where('product_category', $row->product_category)
                ->update([
                    'actual_units' => (int) $row->units,
                    'actual_revenue' => (float) $row->revenue,
                ]);

            $totalActualUnits += (int) $row->units;
            $totalActualRevenue += (float) $row->revenue;
            $updated += $affected;
        }

        // Update totals rows
        $updated += ForecastSnapshot::where('year_month', $yearMonth)
            ->whereNull('product_category')
            ->update([
                'actual_units' => $totalActualUnits,
                'actual_revenue' => round($totalActualRevenue, 2),
            ]);

        return $updated;
    }

    /**
     * Monthly variance report: forecast vs actuals per month.
     *
     * @return array<string, array{forecasted_units: int, actual_units: int|null, forecasted_revenue: float, actual_revenue: float|null, variance_pct: float|null}>
     */
    public function monthlyVariance(Scenario $scenario, int $year): array
    {
        $snapshots = ForecastSnapshot::where('scenario_id', $scenario->id)
            ->totals()
            ->where('year_month', 'like', "{$year}-%")
            ->orderBy('year_month')
            ->get();

        $result = [];

        foreach ($snapshots as $snapshot) {
            $variancePct = null;
            if ($snapshot->actual_revenue !== null && (float) $snapshot->forecasted_revenue > 0) {
                $variancePct = round(
                    ((float) $snapshot->actual_revenue - (float) $snapshot->forecasted_revenue) / (float) $snapshot->forecasted_revenue * 100,
                    1,
                );
            }

            $result[$snapshot->year_month] = [
                'forecasted_units' => $snapshot->forecasted_units,
                'actual_units' => $snapshot->actual_units,
                'forecasted_revenue' => (float) $snapshot->forecasted_revenue,
                'actual_revenue' => $snapshot->actual_revenue !== null ? (float) $snapshot->actual_revenue : null,
                'variance_pct' => $variancePct,
            ];
        }

        return $result;
    }

    /**
     * Pace projection: based on YTD actuals vs forecast, project full year.
     *
     * @return array{ytd_forecasted: float, ytd_actual: float, pace_factor: float, projected_year: float, original_year: float}
     */
    public function paceProjection(Scenario $scenario, string $asOfMonth): array
    {
        $year = (int) substr($asOfMonth, 0, 4);

        $ytdSnapshots = ForecastSnapshot::where('scenario_id', $scenario->id)
            ->totals()
            ->where('year_month', '<=', $asOfMonth)
            ->where('year_month', 'like', "{$year}-%")
            ->whereNotNull('actual_revenue')
            ->get();

        $ytdForecasted = $ytdSnapshots->sum(fn ($s) => (float) $s->forecasted_revenue);
        $ytdActual = $ytdSnapshots->sum(fn ($s) => (float) $s->actual_revenue);

        $allSnapshots = ForecastSnapshot::where('scenario_id', $scenario->id)
            ->totals()
            ->where('year_month', 'like', "{$year}-%")
            ->get();

        $originalYear = $allSnapshots->sum(fn ($s) => (float) $s->forecasted_revenue);
        $paceFactor = $ytdForecasted > 0 ? $ytdActual / $ytdForecasted : 1.0;

        return [
            'ytd_forecasted' => round($ytdForecasted, 2),
            'ytd_actual' => round($ytdActual, 2),
            'pace_factor' => round($paceFactor, 4),
            'projected_year' => round($originalYear * $paceFactor, 2),
            'original_year' => round($originalYear, 2),
        ];
    }

    /**
     * Get actual units and revenue per product category for a month.
     *
     * @return Collection<int, object{product_category: string, units: int, revenue: float}>
     */
    private function getActualsByCategory(string $yearMonth): Collection
    {
        return DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->whereNotNull('products.product_category')
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereRaw(DbDialect::yearMonthExpr('shopify_orders.ordered_at').' = ?', [$yearMonth])
            ->selectRaw('products.product_category, SUM(shopify_line_items.quantity) as units, SUM(shopify_line_items.quantity * shopify_line_items.price) as revenue')
            ->groupBy('products.product_category')
            ->get();
    }
}
