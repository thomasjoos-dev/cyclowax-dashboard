<?php

use App\Enums\DemandEventType;
use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\SeasonalIndex;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\CategorySeasonalCalculator;
use App\Services\Forecast\DemandEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOrdersForCategory(ProductCategory $category, array $monthlyUnits): void
{
    $product = Product::factory()->create(['product_category' => $category->value]);

    foreach ($monthlyUnits as $month => $units) {
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$month}-15",
            'financial_status' => 'PAID',
        ]);

        ShopifyLineItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $units,
        ]);
    }
}

it('calculates seasonal indices for a category', function () {
    // Create orders with known seasonal pattern: summer peak
    createOrdersForCategory(ProductCategory::WaxTablet, [
        '01' => 50, '02' => 50, '03' => 75, '04' => 100,
        '05' => 150, '06' => 150, '07' => 125, '08' => 125,
        '09' => 75, '10' => 50, '11' => 100, '12' => 50,
    ]);

    $calculator = app(CategorySeasonalCalculator::class);
    $indices = $calculator->calculateForCategory(ProductCategory::WaxTablet);

    expect($indices)->not->toBeNull()
        ->and($indices)->toHaveCount(12)
        ->and($indices[5])->toBeGreaterThan(1.0)   // May above average
        ->and($indices[6])->toBeGreaterThan(1.0)   // June above average
        ->and($indices[1])->toBeLessThan(1.0)      // Jan below average
        ->and($indices[12])->toBeLessThan(1.0);    // Dec below average

    // Verify persistence
    $stored = SeasonalIndex::forCategory(ProductCategory::WaxTablet)->count();
    expect($stored)->toBe(12);
});

it('excludes demand event periods from index calculation', function () {
    // All months have 100 units except November which has 300 (Black Friday)
    $monthlyUnits = array_fill(1, 12, 100);
    $monthlyUnits[11] = 300; // BF spike

    createOrdersForCategory(ProductCategory::StarterKit, collect($monthlyUnits)->mapWithKeys(
        fn ($units, $month) => [str_pad($month, 2, '0', STR_PAD_LEFT) => $units]
    )->all());

    // Create a historical demand event covering November
    $eventService = app(DemandEventService::class);
    $eventService->createWithCategories(
        ['name' => 'BF Test', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2025-11-01', 'end_date' => '2025-11-30', 'is_historical' => true],
        [['product_category' => ProductCategory::StarterKit->value]],
    );

    $calculator = app(CategorySeasonalCalculator::class);
    $indices = $calculator->calculateForCategory(ProductCategory::StarterKit);

    // Without event exclusion, Nov index would be ~2.73. With exclusion, it should be ~1.0 (all equal)
    // November should be excluded, so only 11 months of data. Remaining months are all 100, so indices ≈ 1.0
    expect($indices)->not->toBeNull();

    // All non-excluded months should be approximately equal
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12] as $month) {
        expect($indices[$month])->toBeGreaterThan(0.9)
            ->and($indices[$month])->toBeLessThan(1.15);
    }
});

it('calculates weighted group average', function () {
    // WaxTablet: high volume, strong seasonal
    createOrdersForCategory(ProductCategory::WaxTablet, [
        '01' => 50, '02' => 50, '03' => 75, '04' => 100,
        '05' => 200, '06' => 200, '07' => 150, '08' => 150,
        '09' => 75, '10' => 50, '11' => 50, '12' => 50,
    ]);

    // PocketWax: lower volume, similar pattern
    createOrdersForCategory(ProductCategory::PocketWax, [
        '01' => 10, '02' => 10, '03' => 15, '04' => 20,
        '05' => 30, '06' => 30, '07' => 25, '08' => 25,
        '09' => 15, '10' => 10, '11' => 10, '12' => 10,
    ]);

    $calculator = app(CategorySeasonalCalculator::class);
    $groupIndices = $calculator->calculateForGroup(ForecastGroup::RideActivity);

    expect($groupIndices)->not->toBeNull()
        ->and($groupIndices)->toHaveCount(12)
        ->and($groupIndices[5])->toBeGreaterThan(1.0)  // Summer peak
        ->and($groupIndices[1])->toBeLessThan(1.0);    // Winter low

    // Group index should be stored
    $stored = SeasonalIndex::forGroup(ForecastGroup::RideActivity)->count();
    expect($stored)->toBe(12);
});

it('resolves index with maturity fallback', function () {
    // Create mature category data (WaxTablet with >12 months)
    $product = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);

    // Spread orders over 2 years for maturity
    foreach (['2024', '2025'] as $year) {
        foreach (range(1, 12) as $month) {
            $m = str_pad($month, 2, '0', STR_PAD_LEFT);
            $order = ShopifyOrder::factory()->create([
                'ordered_at' => "{$year}-{$m}-15",
                'financial_status' => 'PAID',
            ]);
            ShopifyLineItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $month <= 6 ? $month * 20 : (13 - $month) * 20,
            ]);
        }
    }

    $calculator = app(CategorySeasonalCalculator::class);
    $calculator->calculateAll();

    // Mature category should use own index
    $index = $calculator->resolveIndex(ProductCategory::WaxTablet, 6);
    expect($index)->toBeGreaterThan(1.0);

    // Category with no data should fall back to group or 1.0
    $fallback = $calculator->resolveIndex(ProductCategory::PocketWax, 6);
    expect($fallback)->toBeFloat();
});
