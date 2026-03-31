<?php

namespace App\Enums;

enum ForecastGroup: string
{
    case RideActivity = 'ride_activity';
    case GettingStarted = 'getting_started';
    case ChainWear = 'chain_wear';
    case Companion = 'companion';

    public function label(): string
    {
        return match ($this) {
            self::RideActivity => 'Ride Activity',
            self::GettingStarted => 'Getting Started',
            self::ChainWear => 'Chain Wear',
            self::Companion => 'Companion',
        };
    }

    /**
     * @return array<int, ProductCategory>
     */
    public function categories(): array
    {
        return match ($this) {
            self::RideActivity => [
                ProductCategory::WaxTablet,
                ProductCategory::PocketWax,
            ],
            self::GettingStarted => [
                ProductCategory::StarterKit,
                ProductCategory::WaxKit,
                ProductCategory::Bundle,
            ],
            self::ChainWear => [
                ProductCategory::Chain,
                ProductCategory::ChainConsumable,
                ProductCategory::ChainTool,
            ],
            self::Companion => [
                ProductCategory::Heater,
                ProductCategory::HeaterAccessory,
                ProductCategory::Cleaning,
                ProductCategory::MultiTool,
                ProductCategory::Accessory,
            ],
        };
    }
}
