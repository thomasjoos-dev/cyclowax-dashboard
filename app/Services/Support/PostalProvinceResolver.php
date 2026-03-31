<?php

namespace App\Services\Support;

class PostalProvinceResolver
{
    /**
     * Resolve a province code from a country code and postal code.
     *
     * Uses prefix-based mapping tables defined in config/postal-provinces/.
     * Returns null if the country is not supported or the prefix is not found.
     */
    public function resolve(string $countryCode, string $postalCode): ?string
    {
        $country = config("postal-provinces.countries.{$countryCode}");

        if (! $country) {
            return null;
        }

        $postalCode = preg_replace('/\s+/', '', $postalCode);

        if ($postalCode === '') {
            return null;
        }

        $prefix = substr($postalCode, 0, $country['prefix_length']);

        $map = config("postal-provinces.{$country['map']}");

        if (! $map) {
            return null;
        }

        return $map[$prefix] ?? null;
    }
}
