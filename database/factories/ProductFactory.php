<?php

namespace Database\Factories;

use App\Enums\HeaterGeneration;
use App\Enums\JourneyPhase;
use App\Enums\PortfolioRole;
use App\Enums\ProductCategory;
use App\Enums\WaxRecipe;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('CW-###-??')),
            'name' => fake()->words(3, true),
            'product_type' => fake()->randomElement(['wax_tablet', 'chain', 'heater', 'starter_kit']),
            'category' => fake()->randomElement(['wax', 'chain', 'heater', 'kit', 'accessory']),
            'shopify_product_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'odoo_product_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'cost_price' => fake()->randomFloat(4, 5, 80),
            'list_price' => fake()->randomFloat(2, 20, 300),
            'weight' => fake()->randomFloat(3, 0.05, 5.0),
            'barcode' => fake()->ean13(),
            'is_active' => true,
            'is_discontinued' => false,
            'discontinued_at' => null,
            'last_synced_at' => now(),
            'product_category' => fake()->randomElement(ProductCategory::cases()),
            'portfolio_role' => fake()->randomElement(PortfolioRole::cases()),
            'journey_phase' => fake()->randomElement(JourneyPhase::cases()),
            'wax_recipe' => null,
            'heater_generation' => null,
            'successor_product_id' => null,
        ];
    }

    public function acquisition(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::StarterKit,
            'portfolio_role' => PortfolioRole::Acquisition,
            'journey_phase' => JourneyPhase::GettingStarted,
            'product_type' => 'starter_kit',
        ]);
    }

    public function retention(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::WaxTablet,
            'portfolio_role' => PortfolioRole::RetentionDriver,
            'journey_phase' => JourneyPhase::WaxRoutineCycle,
            'product_type' => 'wax_tablet',
            'wax_recipe' => WaxRecipe::Core,
        ]);
    }

    public function starterKit(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::StarterKit,
            'portfolio_role' => PortfolioRole::Acquisition,
            'journey_phase' => JourneyPhase::GettingStarted,
            'product_type' => 'starter_kit',
            'list_price' => fake()->randomFloat(2, 120, 250),
        ]);
    }

    public function consumable(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::ChainConsumable,
            'portfolio_role' => PortfolioRole::RetentionDriver,
            'journey_phase' => JourneyPhase::WaxRoutineCycle,
            'product_type' => 'chain_consumable',
            'list_price' => fake()->randomFloat(2, 5, 25),
        ]);
    }

    public function heater(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::Heater,
            'portfolio_role' => PortfolioRole::MarginProtector,
            'journey_phase' => JourneyPhase::GettingStarted,
            'product_type' => 'heater',
            'heater_generation' => fake()->randomElement(HeaterGeneration::cases()),
        ]);
    }

    public function discontinued(): static
    {
        return $this->state(fn () => [
            'is_discontinued' => true,
            'is_active' => false,
            'discontinued_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
