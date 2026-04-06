<?php

namespace App\Services\Forecast\Tracking;

use App\Enums\ForecastRegion;
use App\Models\ForecastSnapshot;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Support\DbDialect;
use Illuminate\Database\Eloquent\Builder;
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
     * @return int Number of snapshot rows created/updated
     */
    public function recordSnapshot(Scenario $scenario, int $year, ?ForecastRegion $region = null): int
    {
        $forecast = $this->demandForecastService->forecastYear($scenario, $year, $region);
        $regionValue = $region?->value;
        $count = 0;

        for ($month = 1; $month <= 12; $month++) {
            $yearMonth = sprintf('%d-%02d', $year, $month);
            $monthData = $forecast[$month] ?? [];

            $totalUnits = 0;
            $totalRevenue = 0;

            foreach ($monthData as $categoryValue => $data) {
                $this->upsertSnapshot(
                    $scenario->id, $yearMonth, $categoryValue, $regionValue,
                    $data['units'], $data['revenue'],
                );

                $totalUnits += $data['units'];
                $totalRevenue += $data['revenue'];
                $count++;
            }

            // Store totals row (null product_category)
            $this->upsertSnapshot(
                $scenario->id, $yearMonth, null, $regionValue,
                $totalUnits, round($totalRevenue, 2),
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
    public function updateActuals(string $yearMonth, ?ForecastRegion $region = null): int
    {
        $actuals = $this->getActualsByCategory($yearMonth, $region);
        $regionValue = $region?->value;
        $totalActualUnits = 0;
        $totalActualRevenue = 0;

        $updated = 0;

        foreach ($actuals as $row) {
            $affected = $this->snapshotQuery($yearMonth, $row->product_category, $regionValue)
                ->update([
                    'actual_units' => (int) $row->units,
                    'actual_revenue' => (float) $row->revenue,
                ]);

            $totalActualUnits += (int) $row->units;
            $totalActualRevenue += (float) $row->revenue;
            $updated += $affected;
        }

        // Update totals rows
        $updated += $this->snapshotQuery($yearMonth, null, $regionValue)
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
    public function monthlyVariance(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $query = ForecastSnapshot::where('scenario_id', $scenario->id)
            ->whereNull('product_category')
            ->where('year_month', 'like', "{$year}-%")
            ->orderBy('year_month');

        if ($region !== null) {
            $query->where('region', $region->value);
        } else {
            $query->whereNull('region');
        }

        $snapshots = $query->get();
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
    public function paceProjection(Scenario $scenario, string $asOfMonth, ?ForecastRegion $region = null): array
    {
        $year = (int) substr($asOfMonth, 0, 4);

        $baseQuery = ForecastSnapshot::where('scenario_id', $scenario->id)
            ->whereNull('product_category')
            ->where('year_month', 'like', "{$year}-%");

        if ($region !== null) {
            $baseQuery->where('region', $region->value);
        } else {
            $baseQuery->whereNull('region');
        }

        $ytdSnapshots = (clone $baseQuery)
            ->where('year_month', '<=', $asOfMonth)
            ->whereNotNull('actual_revenue')
            ->get();

        $ytdForecasted = $ytdSnapshots->sum(fn ($s) => (float) $s->forecasted_revenue);
        $ytdActual = $ytdSnapshots->sum(fn ($s) => (float) $s->actual_revenue);

        $allSnapshots = (clone $baseQuery)->get();
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
     * NULL-safe upsert for snapshot rows.
     * Works on both SQLite (NULL != NULL in unique) and PostgreSQL (NULL = NULL in unique v15+).
     */
    private function upsertSnapshot(
        int $scenarioId,
        string $yearMonth,
        ?string $productCategory,
        ?string $region,
        int $forecastedUnits,
        float $forecastedRevenue,
    ): void {
        $existing = $this->snapshotQuery($yearMonth, $productCategory, $region)
            ->where('scenario_id', $scenarioId)
            ->first();

        if ($existing) {
            $existing->update([
                'forecasted_units' => $forecastedUnits,
                'forecasted_revenue' => $forecastedRevenue,
                'created_at' => now(),
            ]);
        } else {
            ForecastSnapshot::create([
                'scenario_id' => $scenarioId,
                'year_month' => $yearMonth,
                'product_category' => $productCategory,
                'region' => $region,
                'forecasted_units' => $forecastedUnits,
                'forecasted_revenue' => $forecastedRevenue,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Build a query that correctly matches NULL values for product_category and region.
     *
     * @return Builder<ForecastSnapshot>
     */
    private function snapshotQuery(string $yearMonth, ?string $productCategory, ?string $region): Builder
    {
        $query = ForecastSnapshot::where('year_month', $yearMonth);

        if ($productCategory !== null) {
            $query->where('product_category', $productCategory);
        } else {
            $query->whereNull('product_category');
        }

        if ($region !== null) {
            $query->where('region', $region);
        } else {
            $query->whereNull('region');
        }

        return $query;
    }

    /**
     * Get actual units and revenue per product category for a month.
     *
     * @return Collection<int, object{product_category: string, units: int, revenue: float}>
     */
    private function getActualsByCategory(string $yearMonth, ?ForecastRegion $region = null): Collection
    {
        $query = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->whereNotNull('products.product_category')
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereRaw(DbDialect::yearMonthExpr('shopify_orders.ordered_at').' = ?', [$yearMonth]);

        if ($region !== null) {
            $countries = $region->countries();
            if ($countries === []) {
                $allMapped = collect(ForecastRegion::cases())
                    ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                    ->flatMap(fn (ForecastRegion $r) => $r->countries())
                    ->all();
                $query->whereNotIn('shopify_orders.shipping_country_code', $allMapped);
            } else {
                $query->whereIn('shopify_orders.shipping_country_code', $countries);
            }
        }

        return $query
            ->selectRaw('products.product_category, SUM(shopify_line_items.quantity) as units, SUM(shopify_line_items.quantity * shopify_line_items.price) as revenue')
            ->groupBy('products.product_category')
            ->get();
    }
}
