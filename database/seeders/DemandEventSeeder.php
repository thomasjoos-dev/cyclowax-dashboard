<?php

namespace Database\Seeders;

use App\Enums\DemandEventType;
use App\Enums\ProductCategory;
use App\Services\Forecast\DemandEventService;
use Illuminate\Database\Seeder;

/**
 * Seed historical demand events for seasonal index calculation data cleaning.
 */
class DemandEventSeeder extends Seeder
{
    public function run(DemandEventService $service): void
    {
        $allForecastable = [
            ProductCategory::StarterKit,
            ProductCategory::WaxKit,
            ProductCategory::WaxTablet,
            ProductCategory::PocketWax,
            ProductCategory::Chain,
            ProductCategory::ChainConsumable,
            ProductCategory::ChainTool,
            ProductCategory::Heater,
            ProductCategory::HeaterAccessory,
            ProductCategory::Cleaning,
        ];

        $kitsWaxChains = [
            ProductCategory::StarterKit,
            ProductCategory::WaxKit,
            ProductCategory::WaxTablet,
            ProductCategory::PocketWax,
            ProductCategory::Chain,
            ProductCategory::ChainConsumable,
        ];

        // Black Friday 2024
        $service->createWithCategories(
            [
                'name' => 'Black Friday 2024',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2024-11-18',
                'end_date' => '2024-12-01',
                'description' => 'Black Friday promo campaign with discounts across all product lines.',
                'is_historical' => true,
            ],
            collect($allForecastable)->map(fn (ProductCategory $c) => [
                'product_category' => $c->value,
            ])->all(),
        );

        // Kerstacties 2024
        $service->createWithCategories(
            [
                'name' => 'Kerstacties 2024',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2024-12-09',
                'end_date' => '2024-12-24',
                'description' => 'Christmas promotions including Ho-Ho-Hot editions and gift kits.',
                'is_historical' => true,
            ],
            collect($kitsWaxChains)->map(fn (ProductCategory $c) => [
                'product_category' => $c->value,
            ])->all(),
        );

        // Wax Tablet launch (Performance + Race recipes)
        $service->createWithCategories(
            [
                'name' => 'Wax Tablet Launch 2025',
                'type' => DemandEventType::ProductLaunch,
                'start_date' => '2025-09-15',
                'end_date' => '2025-10-15',
                'description' => 'Launch of Performance and Race wax tablet recipes.',
                'is_historical' => true,
            ],
            [
                ['product_category' => ProductCategory::WaxTablet->value],
            ],
        );

        // Black Friday 2025
        $service->createWithCategories(
            [
                'name' => 'Black Friday 2025',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2025-11-17',
                'end_date' => '2025-12-01',
                'description' => 'Black Friday 2025 promo campaign.',
                'is_historical' => true,
            ],
            collect($allForecastable)->map(fn (ProductCategory $c) => [
                'product_category' => $c->value,
            ])->all(),
        );

        // Kerstacties 2025
        $service->createWithCategories(
            [
                'name' => 'Kerstacties 2025',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2025-12-08',
                'end_date' => '2025-12-22',
                'description' => 'Christmas 2025 promotions.',
                'is_historical' => true,
            ],
            collect($kitsWaxChains)->map(fn (ProductCategory $c) => [
                'product_category' => $c->value,
            ])->all(),
        );

        // Performance Wax Kit pre-order launch
        $service->createWithCategories(
            [
                'name' => 'Performance Wax Kit Launch',
                'type' => DemandEventType::ProductLaunch,
                'start_date' => '2026-01-13',
                'end_date' => '2026-03-15',
                'description' => 'Pre-order and launch of Performance Wax Kit with Performance Heater.',
                'is_historical' => true,
            ],
            [
                ['product_category' => ProductCategory::WaxKit->value],
            ],
        );
    }
}
