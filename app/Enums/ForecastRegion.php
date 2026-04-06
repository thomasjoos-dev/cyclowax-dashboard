<?php

namespace App\Enums;

enum ForecastRegion: string
{
    case De = 'de';
    case Be = 'be';
    case Us = 'us';
    case Gb = 'gb';
    case Nl = 'nl';
    case EuAlpine = 'eu_alpine';
    case EuNordics = 'eu_nordics';
    case EuLongTail = 'eu_long_tail';
    case Row = 'row';

    public function label(): string
    {
        return match ($this) {
            self::De => 'Germany',
            self::Be => 'Belgium',
            self::Us => 'United States',
            self::Gb => 'United Kingdom',
            self::Nl => 'Netherlands',
            self::EuAlpine => 'EU Alpine',
            self::EuNordics => 'EU Nordics',
            self::EuLongTail => 'EU Long Tail',
            self::Row => 'Rest of World',
        };
    }

    /**
     * @return array<int, string> ISO 3166-1 alpha-2 country codes
     */
    public function countries(): array
    {
        return match ($this) {
            self::De => ['DE'],
            self::Be => ['BE'],
            self::Us => ['US', 'CA'],
            self::Gb => ['GB'],
            self::Nl => ['NL'],
            self::EuAlpine => ['AT', 'CH', 'LI'],
            self::EuNordics => ['DK', 'SE', 'NO', 'FI', 'IS'],
            self::EuLongTail => ['FR', 'IT', 'ES', 'PT', 'LU', 'IE', 'PL', 'CZ', 'HU', 'SI', 'SK', 'HR', 'RO', 'BG', 'GR', 'CY', 'MT', 'EE', 'LT', 'LV'],
            self::Row => [],
        };
    }

    public function warehouse(): Warehouse
    {
        return match ($this) {
            self::Us => Warehouse::Us,
            default => Warehouse::Be,
        };
    }

    /**
     * Resolve the forecast region for a given country code.
     * Falls back to ROW for unmapped countries.
     */
    public static function forCountry(string $countryCode): self
    {
        return collect(self::cases())
            ->first(fn (self $region) => in_array($countryCode, $region->countries()))
            ?? self::Row;
    }

    /**
     * Whether this region has enough historical data for its own retention curve.
     * Standalone regions use their own curve; grouped regions fall back to global.
     */
    public function hasOwnRetentionCurve(): bool
    {
        return in_array($this, [
            self::De,
            self::Be,
            self::Us,
            self::Gb,
            self::Nl,
        ]);
    }
}
