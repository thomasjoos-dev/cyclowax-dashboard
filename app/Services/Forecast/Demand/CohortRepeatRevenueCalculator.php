<?php

namespace App\Services\Forecast\Demand;

class CohortRepeatRevenueCalculator
{
    public function __construct(
        private CohortProjectionService $cohortProjectionService,
    ) {}

    /**
     * Calculate repeat revenue for a forecast month using cohort-based retention.
     * Uses age-aware AOV: young cohorts (age 1-3) use 2nd-order AOV (kits/heaters),
     * older cohorts use 3rd+ AOV (consumables shift).
     *
     * @param  array<int, int>  $cohorts  Month number → new customer count
     * @param  int  $forecastMonth  The month (1-12) to calculate repeat for
     * @param  float  $repeatAov  Average repeat order value (seasonal, used as fallback)
     * @param  array<int, float>  $retentionCurve  Month → cumulative retention %
     * @param  float  $curveAdjustment  Scalar to shift the curve (1.0 = no change)
     * @param  array{second_order: float, third_plus: float, overall: float}|null  $aovByOrderNumber  Age-aware AOV split
     */
    public function calculate(
        array $cohorts,
        int $forecastMonth,
        float $repeatAov,
        array $retentionCurve,
        float $curveAdjustment = 1.0,
        ?array $aovByOrderNumber = null,
    ): float {
        $totalRepeatRevenue = 0.0;

        foreach ($cohorts as $cohortMonth => $cohortSize) {
            if ($cohortMonth >= $forecastMonth || $cohortSize <= 0) {
                continue;
            }

            $age = $forecastMonth - $cohortMonth;
            $prevAge = $age - 1;

            $currentRetention = $this->cohortProjectionService->monthlyRetentionRate($age, $retentionCurve) * $curveAdjustment;
            $previousRetention = $prevAge > 0
                ? $this->cohortProjectionService->monthlyRetentionRate($prevAge, $retentionCurve) * $curveAdjustment
                : 0.0;

            $incrementalPct = max(0, $currentRetention - $previousRetention);
            $incrementalRepeaters = $cohortSize * $incrementalPct / 100;

            $effectiveAov = $repeatAov;
            if ($aovByOrderNumber !== null) {
                $effectiveAov = $age <= 3
                    ? $aovByOrderNumber['second_order']
                    : $aovByOrderNumber['third_plus'];
            }

            $totalRepeatRevenue += $incrementalRepeaters * $effectiveAov;
        }

        return round($totalRepeatRevenue, 2);
    }
}
