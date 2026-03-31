<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\SupplyConfig;
use Illuminate\Support\Facades\DB;

class StockPlanningService
{
    public function __construct(
        private DemandForecastService $demandForecastService,
        private StockForecastService $stockForecastService,
    ) {}

    /**
     * Generate a purchase schedule: when to order, how much, per category.
     *
     * @return array<string, array<int, array{order_date: string, order_quantity: int, reason: string, stock_at_order: int, demand_until_next: int}>>
     */
    public function purchaseSchedule(Scenario $scenario, int $year): array
    {
        $forecast = $this->demandForecastService->forecastYear($scenario, $year);
        $currentStock = $this->getCurrentStockByCategory();
        $supplyConfigs = SupplyConfig::all()->keyBy(fn ($c) => $c->product_category->value);

        $schedule = [];

        foreach ($supplyConfigs as $categoryValue => $config) {
            $stock = $currentStock[$categoryValue] ?? 0;
            $orders = [];

            for ($month = 1; $month <= 12; $month++) {
                $monthData = $forecast[$month][$categoryValue] ?? null;
                $demand = $monthData ? $monthData['units'] : 0;

                $stock -= $demand;

                // Look ahead: how much demand in the next lead_time + buffer days?
                $lookaheadMonths = (int) ceil(($config->lead_time_days + $config->buffer_days) / 30);
                $futureDemand = 0;
                for ($ahead = 1; $ahead <= $lookaheadMonths && ($month + $ahead) <= 12; $ahead++) {
                    $futureMonth = $month + $ahead;
                    $futureData = $forecast[$futureMonth][$categoryValue] ?? null;
                    $futureDemand += $futureData ? $futureData['units'] : 0;
                }

                if ($stock < $futureDemand) {
                    // Need to order
                    $orderQuantity = max($futureDemand - $stock, $config->moq);
                    // Round up to MOQ multiples
                    if ($orderQuantity > $config->moq) {
                        $orderQuantity = (int) ceil($orderQuantity / $config->moq) * $config->moq;
                    }

                    $orderDate = now()->setYear($year)->setMonth($month)->setDay(1)
                        ->subDays($config->lead_time_days)
                        ->toDateString();

                    $orders[] = [
                        'order_date' => $orderDate,
                        'delivery_month' => $month,
                        'order_quantity' => $orderQuantity,
                        'reason' => "Stock ({$stock}) below lookahead demand ({$futureDemand}) for {$lookaheadMonths} months",
                        'stock_at_order' => max(0, (int) $stock),
                        'demand_until_next' => $futureDemand,
                    ];

                    $stock += $orderQuantity;
                }
            }

            if (! empty($orders)) {
                $schedule[$categoryValue] = $orders;
            }
        }

        return $schedule;
    }

    /**
     * Forward-looking runway for a category based on forecast demand.
     *
     * @return array{current_stock: int, monthly_demand: array<int, int>, depletion_month: int|null, runway_days: int|null}
     */
    public function categoryRunway(ProductCategory $category, Scenario $scenario, int $year): array
    {
        $forecast = $this->demandForecastService->forecastYear($scenario, $year);
        $currentStock = $this->getCurrentStockByCategory();
        $stock = $currentStock[$category->value] ?? 0;

        $monthlyDemand = [];
        $depletionMonth = null;
        $remainingDays = 0;

        for ($month = 1; $month <= 12; $month++) {
            $monthData = $forecast[$month][$category->value] ?? null;
            $demand = $monthData ? $monthData['units'] : 0;
            $monthlyDemand[$month] = $demand;

            if ($depletionMonth === null) {
                $stock -= $demand;
                if ($stock <= 0 && $demand > 0) {
                    $depletionMonth = $month;
                    // Calculate partial month
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                    $dailyDemand = $demand / $daysInMonth;
                    $remainingDays = $dailyDemand > 0
                        ? (int) floor(($stock + $demand) / $dailyDemand)
                        : $daysInMonth;
                }
            }
        }

        $runwayDays = null;
        if ($depletionMonth !== null) {
            // Sum days of full months before depletion + partial month
            $fullMonthDays = 0;
            for ($m = 1; $m < $depletionMonth; $m++) {
                $fullMonthDays += cal_days_in_month(CAL_GREGORIAN, $m, $year);
            }
            $runwayDays = $fullMonthDays + $remainingDays;
        }

        return [
            'current_stock' => $currentStock[$category->value] ?? 0,
            'monthly_demand' => $monthlyDemand,
            'depletion_month' => $depletionMonth,
            'runway_days' => $runwayDays,
        ];
    }

    /**
     * Chronological timeline of all purchase orders across categories.
     *
     * @return array<int, array{order_date: string, delivery_month: int, category: string, quantity: int, reason: string}>
     */
    public function reorderTimeline(Scenario $scenario, int $year): array
    {
        $schedule = $this->purchaseSchedule($scenario, $year);
        $timeline = [];

        foreach ($schedule as $categoryValue => $orders) {
            foreach ($orders as $order) {
                $timeline[] = [
                    'order_date' => $order['order_date'],
                    'delivery_month' => $order['delivery_month'],
                    'category' => $categoryValue,
                    'quantity' => $order['order_quantity'],
                    'reason' => $order['reason'],
                ];
            }
        }

        usort($timeline, fn ($a, $b) => $a['order_date'] <=> $b['order_date']);

        return $timeline;
    }

    /**
     * Get current total stock per product category from latest snapshots.
     *
     * @return array<string, int>
     */
    private function getCurrentStockByCategory(): array
    {
        $rows = DB::select('
            SELECT
                p.product_category,
                SUM(pss.qty_free) as total_free
            FROM product_stock_snapshots pss
            JOIN products p ON p.id = pss.product_id
            INNER JOIN (
                SELECT product_id, MAX(recorded_at) as max_date
                FROM product_stock_snapshots
                GROUP BY product_id
            ) latest ON latest.product_id = pss.product_id AND latest.max_date = pss.recorded_at
            WHERE p.product_category IS NOT NULL
            GROUP BY p.product_category
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[$row->product_category] = (int) $row->total_free;
        }

        return $result;
    }
}
