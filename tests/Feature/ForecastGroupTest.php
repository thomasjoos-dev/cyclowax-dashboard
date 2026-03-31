<?php

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;

it('maps every forecastable ProductCategory to exactly one ForecastGroup', function () {
    $forecastable = collect(ProductCategory::cases())
        ->filter(fn (ProductCategory $c) => $c->forecastGroup() !== null);

    expect($forecastable)->not->toBeEmpty();

    foreach ($forecastable as $category) {
        $group = $category->forecastGroup();
        expect($group)->toBeInstanceOf(ForecastGroup::class);
        expect($group->categories())->toContain($category);
    }
});

it('excludes GiftCard and Promotional from forecast groups', function () {
    expect(ProductCategory::GiftCard->forecastGroup())->toBeNull()
        ->and(ProductCategory::Promotional->forecastGroup())->toBeNull();
});

it('includes all forecastable categories across all groups', function () {
    $allGrouped = collect(ForecastGroup::cases())
        ->flatMap(fn (ForecastGroup $g) => $g->categories());

    $forecastable = collect(ProductCategory::cases())
        ->filter(fn (ProductCategory $c) => $c->forecastGroup() !== null);

    expect($allGrouped)->toHaveCount($forecastable->count());
});

it('returns correct categories per group', function () {
    expect(ForecastGroup::RideActivity->categories())->toContain(
        ProductCategory::WaxTablet,
        ProductCategory::PocketWax,
    )->toHaveCount(2);

    expect(ForecastGroup::GettingStarted->categories())->toContain(
        ProductCategory::StarterKit,
        ProductCategory::WaxKit,
        ProductCategory::Bundle,
    )->toHaveCount(3);

    expect(ForecastGroup::ChainWear->categories())->toContain(
        ProductCategory::Chain,
        ProductCategory::ChainConsumable,
        ProductCategory::ChainTool,
    )->toHaveCount(3);

    expect(ForecastGroup::Companion->categories())->toHaveCount(5);
});
