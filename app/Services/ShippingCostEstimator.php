<?php

namespace App\Services;

class ShippingCostEstimator
{
    /**
     * Estimate the shipping cost based on carrier name and country.
     * Returns null if the carrier is explicitly free (cost = 0 is still a valid estimate).
     */
    public function estimate(?string $carrier, ?string $countryCode): ?float
    {
        // Try exact carrier match first
        if ($carrier) {
            $normalized = mb_strtolower(trim($carrier));
            $rate = config("shipping-rates.rates.{$normalized}");

            if ($rate !== null) {
                return (float) $rate;
            }

            // Try partial match (carrier name contains key)
            foreach (config('shipping-rates.rates', []) as $key => $value) {
                if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                    return (float) $value;
                }
            }
        }

        // Fallback to country-based estimate
        if ($countryCode) {
            return (float) (config("shipping-rates.fallback.{$countryCode}")
                ?? config('shipping-rates.fallback.default', 15.00));
        }

        return (float) config('shipping-rates.fallback.default', 15.00);
    }
}
