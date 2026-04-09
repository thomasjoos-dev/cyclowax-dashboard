<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Exceptions\InvalidProductMixException;
use App\Models\DemandEvent;
use App\Models\ScenarioProductMix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Validates forecast inputs and detects anomalies.
 *
 * Checks product mix consistency, baseline anomalies, AOV divergence,
 * and event uplift realism. All methods accept data as parameters —
 * no dependency on the forecast engine itself.
 */
class ForecastValidationService
{
    public function __construct(
        private SalesBaselineService $baselineService,
    ) {}

    /**
     * Validate that product mix shares are within acceptable ranges.
     *
     * @param  Collection<string, ScenarioProductMix>  $mixes
     *
     * @throws InvalidProductMixException
     */
    public function validateProductMixes(Collection $mixes): void
    {
        $violations = [];

        foreach ($mixes as $categoryValue => $mix) {
            $acq = (float) $mix->acq_share;
            $rep = (float) $mix->repeat_share;

            if ($acq < 0 || $acq > 1) {
                $violations["{$categoryValue}.acq_share"] = "value {$acq} not in [0, 1]";
            }
            if ($rep < 0 || $rep > 1) {
                $violations["{$categoryValue}.repeat_share"] = "value {$rep} not in [0, 1]";
            }
        }

        if (count($violations) > 0) {
            throw InvalidProductMixException::sharesOutOfRange($violations);
        }

        $acqSum = $mixes->sum(fn (ScenarioProductMix $m) => (float) $m->acq_share);
        $repSum = $mixes->sum(fn (ScenarioProductMix $m) => (float) $m->repeat_share);

        if ($acqSum < 0.95 || $acqSum > 1.05) {
            throw InvalidProductMixException::sumOutOfTolerance('acq_share', $acqSum);
        }

        if ($repSum < 0.95 || $repSum > 1.05) {
            throw InvalidProductMixException::sumOutOfTolerance('repeat_share', $repSum);
        }
    }

    /**
     * Detect Q1 baseline anomalies by comparing current year actuals
     * against the previous year's same months.
     *
     * @param  array<string, array{acq_rev: float, rep_rev: float, new_customers: int}>  $baselineByMonth
     * @return array<int, array{month: string, metric: string, current: float, previous: float, deviation_pct: float}>
     */
    public function detectBaselineAnomalies(array $baselineByMonth, int $year, ?ForecastRegion $region = null): array
    {
        $threshold = config('forecast.baseline_anomaly_threshold', 0.30);
        $warnings = [];

        for ($m = 1; $m <= 3; $m++) {
            $currKey = sprintf('%d-%02d', $year, $m);
            $prevKey = sprintf('%d-%02d', $year - 1, $m);

            $current = $baselineByMonth[$currKey] ?? null;
            if ($current === null) {
                continue;
            }

            $prevActuals = $this->baselineService->monthlyActuals(
                sprintf('%d-%02d-01', $year - 1, $m),
                sprintf('%d-%02d-01', $m < 12 ? $year - 1 : $year, $m < 12 ? $m + 1 : 1),
                $region,
            );
            $previous = $prevActuals[0] ?? null;

            if ($previous === null) {
                continue;
            }

            foreach (['acq_rev', 'rep_rev', 'new_customers'] as $metric) {
                $prevValue = (float) ($previous[$metric] ?? 0);
                $currValue = (float) ($current[$metric] ?? 0);

                if ($prevValue <= 0) {
                    continue;
                }

                $deviation = abs($currValue - $prevValue) / $prevValue;
                if ($deviation > $threshold) {
                    $warnings[] = [
                        'month' => $currKey,
                        'metric' => $metric,
                        'current' => $currValue,
                        'previous' => $prevValue,
                        'deviation_pct' => round($deviation * 100, 1),
                    ];

                    Log::warning('Q1 baseline anomaly detected', [
                        'month' => $currKey,
                        'metric' => $metric,
                        'region' => $region?->value ?? 'global',
                        'current' => $currValue,
                        'previous' => $prevValue,
                        'deviation_pct' => round($deviation * 100, 1),
                    ]);
                }
            }
        }

        return $warnings;
    }

    /**
     * Validate that repeat AOV is consistent with the product mix.
     *
     * @param  array<string, array{actual: float, normalized: float}>  $dynamicAov
     * @param  array<string, array{repeat_aov: float}>  $assumptions
     * @param  array<string, ScenarioProductMix>  $mixes
     */
    public function validateAovConsistency(array $dynamicAov, array $assumptions, array $mixes, ?ForecastRegion $region = null): void
    {
        if (empty($mixes)) {
            return;
        }

        $impliedAov = 0.0;
        foreach ($mixes as $mix) {
            $impliedAov += (float) $mix->repeat_share * (float) $mix->avg_unit_price;
        }

        foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
            $quarterAov = $dynamicAov[$quarter] ?? null;
            $actualAov = $quarterAov['normalized'] ?? ($assumptions[$quarter]['repeat_aov'] ?? null);
            if ($actualAov === null || $actualAov <= 0 || $impliedAov <= 0) {
                continue;
            }

            $delta = abs($actualAov - $impliedAov) / $actualAov;
            if ($delta > 0.25) {
                Log::warning('AOV consistency warning: repeat_aov and product mix diverge', [
                    'quarter' => $quarter,
                    'region' => $region?->value ?? 'global',
                    'repeat_aov' => $actualAov,
                    'implied_aov_from_mix' => round($impliedAov, 2),
                    'delta_pct' => round($delta * 100, 1),
                ]);
            }
        }
    }

    /**
     * Validate that acquisition AOV is consistent with the product mix.
     *
     * @param  array<string, array{actual: float, normalized: float}>  $dynamicAcqAov
     * @param  array<string, ScenarioProductMix>  $mixes
     */
    public function validateAcqAovConsistency(array $dynamicAcqAov, array $mixes, ?ForecastRegion $region = null): void
    {
        if (empty($mixes)) {
            return;
        }

        $impliedAov = 0.0;
        foreach ($mixes as $mix) {
            $impliedAov += (float) $mix->acq_share * (float) $mix->avg_unit_price;
        }

        foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
            $quarterAov = $dynamicAcqAov[$quarter] ?? null;
            $actualAov = $quarterAov['normalized'] ?? null;
            if ($actualAov === null || $actualAov <= 0 || $impliedAov <= 0) {
                continue;
            }

            $delta = abs($actualAov - $impliedAov) / $actualAov;
            if ($delta > 0.25) {
                Log::warning('Acquisition AOV consistency warning: acq_aov and product mix diverge', [
                    'quarter' => $quarter,
                    'region' => $region?->value ?? 'global',
                    'acq_aov' => $actualAov,
                    'implied_aov_from_mix' => round($impliedAov, 2),
                    'delta_pct' => round($delta * 100, 1),
                ]);
            }
        }
    }

    /**
     * Validate that event uplift values are incremental (not total volume).
     *
     * @param  Collection<int, DemandEvent>  $events
     * @param  array<int, array<string, array{revenue: float}>>  $forecast
     * @param  array<string, ScenarioProductMix>  $mixes
     */
    public function validateEventUplift(Collection $events, array $forecast, array $mixes, ?ForecastRegion $region = null): void
    {
        foreach ($events as $event) {
            foreach ($event->categories as $eventCategory) {
                if (! $eventCategory->expected_uplift_units || $eventCategory->expected_uplift_units <= 0) {
                    continue;
                }

                $catValue = $eventCategory->product_category->value;
                $mix = $mixes[$catValue] ?? null;
                if ($mix === null) {
                    continue;
                }

                $avgPrice = (float) $mix->avg_unit_price;
                if ($avgPrice <= 0) {
                    continue;
                }

                $eventMonths = max(1, $event->start_date->diffInMonths($event->end_date) + 1);
                $monthlyUpliftRevenue = ($eventCategory->expected_uplift_units / $eventMonths) * $avgPrice;

                $eventMonth = (int) $event->start_date->format('m');
                $monthForecast = $forecast[$eventMonth][$catValue] ?? null;

                if ($monthForecast === null) {
                    continue;
                }

                $seasonalBaseline = $monthForecast['revenue'] - ($monthForecast['event_boost'] ?? 0);
                if ($seasonalBaseline <= 0) {
                    continue;
                }

                $upliftRatio = $monthlyUpliftRevenue / $seasonalBaseline;

                if ($upliftRatio > 0.50) {
                    Log::warning('Event uplift may include organic volume', [
                        'event' => $event->name,
                        'category' => $catValue,
                        'region' => $region?->value ?? 'global',
                        'monthly_uplift_revenue' => round($monthlyUpliftRevenue, 2),
                        'seasonal_baseline' => round($seasonalBaseline, 2),
                        'uplift_ratio_pct' => round($upliftRatio * 100, 1),
                        'is_incremental' => $eventCategory->is_incremental,
                    ]);
                }
            }
        }
    }

    /**
     * Validate that forecast-implied LTV is consistent with historical and predicted LTV.
     *
     * @param  float  $yearRevenue  Total forecast revenue for the year
     * @param  int  $totalNewCustomers  Total new customers across all months
     * @param  float  $historicalAvgLtv  Average historical LTV from cohorts
     * @param  float  $predictedLtvValue  Predicted LTV from retention curve
     * @return array{forecast_implied_ltv: float, historical_avg_ltv: float, predicted_ltv: float, delta_pct: float, warning: string|null}
     */
    public function validateLtvConsistency(
        float $yearRevenue,
        int $totalNewCustomers,
        float $historicalAvgLtv,
        float $predictedLtvValue,
        ?ForecastRegion $region = null,
    ): array {
        $forecastImpliedLtv = $totalNewCustomers > 0 ? round($yearRevenue / $totalNewCustomers, 2) : 0;

        $deltaPct = $historicalAvgLtv > 0
            ? round(abs($forecastImpliedLtv - $historicalAvgLtv) / $historicalAvgLtv * 100, 1)
            : 0;

        $warning = null;
        if ($historicalAvgLtv > 0 && $deltaPct > 25) {
            $direction = $forecastImpliedLtv > $historicalAvgLtv ? 'higher' : 'lower';
            $warning = "Forecast-implied LTV is {$deltaPct}% {$direction} than historical average";

            Log::warning('LTV consistency warning: forecast-implied LTV diverges from historical', [
                'region' => $region?->value ?? 'global',
                'forecast_implied_ltv' => $forecastImpliedLtv,
                'historical_avg_ltv' => $historicalAvgLtv,
                'predicted_ltv' => $predictedLtvValue,
                'delta_pct' => $deltaPct,
            ]);
        }

        return [
            'forecast_implied_ltv' => $forecastImpliedLtv,
            'historical_avg_ltv' => $historicalAvgLtv,
            'predicted_ltv' => $predictedLtvValue,
            'delta_pct' => $deltaPct,
            'warning' => $warning,
        ];
    }
}
