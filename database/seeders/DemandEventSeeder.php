<?php

namespace Database\Seeders;

use App\Enums\DemandEventType;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Services\Forecast\Demand\DemandEventService;
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

        $this->seedPlanned2026Events($service);
    }

    /**
     * Seed planned 2026 demand events with product-level targeting where applicable.
     */
    private function seedPlanned2026Events(DemandEventService $service): void
    {
        // Lookup product IDs for targeted events (EU variants as representative)
        $pwkId = Product::where('sku', 'SK-PWK-EU')->value('id');
        $perfHeaterId = Product::where('sku', 'LIKE', 'HT-PERF%')
            ->orWhere('name', 'LIKE', 'Performance Heater%')
            ->where('is_active', true)
            ->value('id');

        // GCN Video Q2
        $service->createWithCategories(
            [
                'name' => 'GCN Video Q2',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-08',
                'description' => 'GCN YouTube video featuring hot waxing.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::WaxKit->value, 'expected_uplift_units' => 1000],
            ],
        );

        // Zomercampagne Performance Wax Kit — product-targeted
        $service->createWithCategories(
            [
                'name' => 'Zomercampagne Performance Wax Kit',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-06-15',
                'end_date' => '2026-08-31',
                'description' => 'Summer campaign pushing Performance Wax Kit and Performance Heater.',
                'is_historical' => false,
            ],
            array_filter([
                [
                    'product_category' => ProductCategory::WaxKit->value,
                    'product_id' => $pwkId,
                    'expected_uplift_units' => 150,
                    'pull_forward_pct' => 5,
                ],
                [
                    'product_category' => ProductCategory::Heater->value,
                    'product_id' => $perfHeaterId,
                    'expected_uplift_units' => 50,
                ],
                [
                    'product_category' => ProductCategory::Chain->value,
                    'expected_uplift_units' => 70,
                ],
                [
                    'product_category' => ProductCategory::PocketWax->value,
                    'expected_uplift_units' => 80,
                    'pull_forward_pct' => 5,
                ],
            ]),
        );

        // GCN Video Q3
        $service->createWithCategories(
            [
                'name' => 'GCN Video Q3',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-08',
                'description' => 'GCN YouTube video Q3.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::WaxKit->value, 'expected_uplift_units' => 1000],
            ],
        );

        // Najaarscampagne Hot Wax & Pocket Wax
        $service->createWithCategories(
            [
                'name' => 'Najaarscampagne Hot Wax & Pocket Wax',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-09-15',
                'end_date' => '2026-10-31',
                'description' => 'Autumn campaign for heaters and pocket wax.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::Heater->value, 'expected_uplift_units' => 100, 'pull_forward_pct' => 5],
                ['product_category' => ProductCategory::PocketWax->value, 'expected_uplift_units' => 80, 'pull_forward_pct' => 5],
                ['product_category' => ProductCategory::WaxTablet->value, 'expected_uplift_units' => 70],
            ],
        );

        // GCN Video Q4
        $service->createWithCategories(
            [
                'name' => 'GCN Video Q4',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-08',
                'description' => 'GCN YouTube video Q4.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::WaxKit->value, 'expected_uplift_units' => 1000],
            ],
        );

        // Black Friday 2026
        $service->createWithCategories(
            [
                'name' => 'Black Friday 2026',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-11-16',
                'end_date' => '2026-12-01',
                'description' => 'Black Friday 2026 promo campaign.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::StarterKit->value, 'expected_uplift_units' => 80, 'pull_forward_pct' => 15],
                ['product_category' => ProductCategory::WaxKit->value, 'expected_uplift_units' => 120, 'pull_forward_pct' => 15],
                ['product_category' => ProductCategory::Chain->value, 'expected_uplift_units' => 60, 'pull_forward_pct' => 10],
                ['product_category' => ProductCategory::PocketWax->value, 'expected_uplift_units' => 60, 'pull_forward_pct' => 10],
                ['product_category' => ProductCategory::Bundle->value, 'expected_uplift_units' => 100, 'pull_forward_pct' => 15],
                ['product_category' => ProductCategory::GiftCard->value, 'expected_uplift_units' => 80],
            ],
        );

        // Kerstacties 2026
        $service->createWithCategories(
            [
                'name' => 'Kerstacties 2026',
                'type' => DemandEventType::PromoCampaign,
                'start_date' => '2026-12-08',
                'end_date' => '2026-12-24',
                'description' => 'Christmas 2026 promotions.',
                'is_historical' => false,
            ],
            [
                ['product_category' => ProductCategory::StarterKit->value, 'expected_uplift_units' => 80, 'pull_forward_pct' => 10],
                ['product_category' => ProductCategory::WaxKit->value, 'expected_uplift_units' => 60, 'pull_forward_pct' => 10],
                ['product_category' => ProductCategory::PocketWax->value, 'expected_uplift_units' => 50],
                ['product_category' => ProductCategory::Bundle->value, 'expected_uplift_units' => 60, 'pull_forward_pct' => 10],
                ['product_category' => ProductCategory::GiftCard->value, 'expected_uplift_units' => 50],
            ],
        );
    }
}
