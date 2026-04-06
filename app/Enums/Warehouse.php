<?php

namespace App\Enums;

enum Warehouse: string
{
    case Be = 'be';
    case Us = 'us';

    public function label(): string
    {
        return match ($this) {
            self::Be => 'Belgium (EU)',
            self::Us => 'United States',
        };
    }

    /**
     * @return array<int, ForecastRegion>
     */
    public function regions(): array
    {
        return match ($this) {
            self::Be => [
                ForecastRegion::De,
                ForecastRegion::Be,
                ForecastRegion::Gb,
                ForecastRegion::Nl,
                ForecastRegion::EuAlpine,
                ForecastRegion::EuNordics,
                ForecastRegion::EuLongTail,
                ForecastRegion::Row,
            ],
            self::Us => [
                ForecastRegion::Us,
            ],
        };
    }
}
