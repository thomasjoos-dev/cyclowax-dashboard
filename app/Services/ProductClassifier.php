<?php

namespace App\Services;

use App\Enums\HeaterGeneration;
use App\Enums\JourneyPhase;
use App\Enums\PortfolioRole;
use App\Enums\ProductCategory;
use App\Enums\WaxRecipe;
use App\Models\Product;

class ProductClassifier
{
    /**
     * Classify a single product based on SKU, name and category.
     *
     * @return array{product_category: ProductCategory, portfolio_role: ?PortfolioRole, journey_phase: ?JourneyPhase, wax_recipe: ?WaxRecipe, heater_generation: ?HeaterGeneration, is_discontinued: bool, discontinued_at: ?string}|null
     */
    public function classify(Product $product): ?array
    {
        $sku = $product->sku ?? '';
        $name = $product->name ?? '';
        $category = $product->category ?? '';

        if ($this->isInternal($sku, $category)) {
            return null;
        }

        return $this->detectCategory($sku, $name);
    }

    /**
     * Classify all products in bulk. Optionally force reclassification of already classified products.
     *
     * @return array{classified: int, skipped: int, unmatched: list<Product>}
     */
    public function classifyAll(bool $force = false): array
    {
        $classified = 0;
        $skipped = 0;
        $unmatched = [];

        foreach (Product::all() as $product) {
            if (! $force && $product->product_category !== null) {
                $skipped++;

                continue;
            }

            $result = $this->classify($product);

            if ($result === null) {
                if (! $this->isInternal($product->sku ?? '', $product->category ?? '')) {
                    $unmatched[] = $product;
                }

                continue;
            }

            $product->update($result);
            $classified++;
        }

        $this->linkSuccessors();

        return compact('classified', 'skipped', 'unmatched');
    }

    public function isInternal(string $sku, string $category): bool
    {
        $internalSkus = ['COMM', 'FOOD', 'MIL', 'EXP_GEN', 'TRANS & ACC', 'shopifytip'];

        if (in_array($sku, $internalSkus)) {
            return true;
        }

        if (str_starts_with($sku, 'Delivery_')) {
            return true;
        }

        if ($category === 'All') {
            return true;
        }

        return false;
    }

    /**
     * @return array{product_category: ProductCategory, portfolio_role: ?PortfolioRole, journey_phase: ?JourneyPhase, wax_recipe: ?WaxRecipe, heater_generation: ?HeaterGeneration, is_discontinued: bool, discontinued_at: ?string}|null
     */
    private function detectCategory(string $sku, string $name): ?array
    {
        $nameLower = strtolower($name);

        if (str_starts_with($sku, 'CH-')) {
            return $this->make(ProductCategory::Chain, PortfolioRole::RetentionDriver, JourneyPhase::WaxRoutineCycle);
        }

        if (str_starts_with($sku, 'OEM_CH_')) {
            return $this->make(ProductCategory::Chain, null, null);
        }

        if (str_starts_with($sku, 'QL-') || str_starts_with($sku, 'OEM_QL_')) {
            return $this->make(ProductCategory::ChainConsumable, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if (in_array($sku, ['WX-POCK', 'WX-PPOCK'])) {
            $recipe = $sku === 'WX-PPOCK' ? WaxRecipe::Performance : WaxRecipe::Core;

            return $this->make(ProductCategory::PocketWax, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle, waxRecipe: $recipe);
        }
        if ($sku === 'WX-POCK-old') {
            return $this->make(ProductCategory::PocketWax, null, null, waxRecipe: WaxRecipe::Core, discontinued: true, discontinuedAt: config('products.discontinued_dates.WX-POCK-old'));
        }

        if (str_starts_with($sku, 'WX-')) {
            $recipe = $this->detectWaxRecipe($sku, $nameLower);
            $discontinued = $this->isDiscontinuedWax($sku);

            $role = $discontinued ? null : PortfolioRole::RetentionDriver;

            return $this->make(ProductCategory::WaxTablet, $role, JourneyPhase::WaxRoutineCycle, waxRecipe: $recipe, discontinued: $discontinued, discontinuedAt: $discontinued ? config('products.discontinued_dates.wax_tablets') : null);
        }

        if (str_starts_with($sku, 'OEM_WX_')) {
            return $this->make(ProductCategory::WaxTablet, null, null);
        }

        if (str_starts_with($sku, 'SK-PWK') || str_starts_with($sku, 'PRE-SK-PWK')) {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Performance);
        }

        if (str_starts_with($sku, 'SK-WK') || $sku === 'JH_WK') {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Original);
        }

        if (str_starts_with($sku, 'SK-CWK')) {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Core, heaterGeneration: HeaterGeneration::Original);
        }

        if (str_starts_with($sku, 'SK-')) {
            return $this->make(ProductCategory::StarterKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Original);
        }

        if (str_starts_with($sku, 'OEM-WH')) {
            return $this->make(ProductCategory::Heater, PortfolioRole::MarginProtector, JourneyPhase::WaxRoutineCycle, heaterGeneration: HeaterGeneration::Original);
        }

        if (in_array($sku, ['TL-SW', 'TL-HUT', 'TL-PM'])) {
            return $this->make(ProductCategory::HeaterAccessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if (str_starts_with($sku, 'TL-DS')) {
            return $this->make(ProductCategory::MultiTool, PortfolioRole::MarginProtector, JourneyPhase::WaxRoutineCycle);
        }

        if (in_array($sku, ['TL-CC', 'TL-MLIP', 'TL-MLRP', 'TL-QLIP', 'TL-QLRP', 'TL-PTCC', 'TL-CN42'])) {
            return $this->make(ProductCategory::ChainTool, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if (str_starts_with($sku, 'SP-') || $sku === 'RACK-3') {
            return $this->make(ProductCategory::ChainTool, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if ($sku === 'BK-KIT') {
            return $this->make(ProductCategory::Cleaning, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if ($sku === 'MR-CFS') {
            return $this->make(ProductCategory::Accessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if ($sku === 'TL-TPCH') {
            return $this->make(ProductCategory::Accessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        if ($sku === 'SL-LT') {
            return $this->make(ProductCategory::Promotional, null, null);
        }

        if ($sku === 'GIFT' || str_contains(strtolower($name), 'gift card')) {
            return $this->make(ProductCategory::GiftCard, null, null);
        }

        if (str_starts_with($sku, 'POP-') || str_starts_with($sku, 'OEM_POP_')) {
            return $this->make(ProductCategory::Promotional, null, null);
        }

        if (str_starts_with($sku, 'PC')) {
            return $this->make(ProductCategory::HeaterAccessory, null, null);
        }

        if (str_starts_with($sku, 'shopify')) {
            return $this->make(ProductCategory::Promotional, null, null);
        }

        return null;
    }

    private function detectWaxRecipe(string $sku, string $nameLower): ?WaxRecipe
    {
        if (str_contains($nameLower, 'race')) {
            return WaxRecipe::Race;
        }
        if (str_contains($nameLower, 'performance') || str_contains($nameLower, 'perw')) {
            return WaxRecipe::Performance;
        }
        if (str_contains($nameLower, 'core') || str_contains($nameLower, 'basic') || str_contains($nameLower, 'ho-ho')) {
            return WaxRecipe::Core;
        }
        if (str_contains($nameLower, 'giro') || str_contains($nameLower, 'vuelta')) {
            return WaxRecipe::Performance;
        }

        return null;
    }

    private function isDiscontinuedWax(string $sku): bool
    {
        return $sku === 'WX-BASIC-old';
    }

    private function linkSuccessors(): void
    {
        $successors = [
            'WX-BASIC-old' => 'WX-BASIC',
            'WX-POCK-old' => 'WX-POCK',
        ];

        foreach ($successors as $oldSku => $newSku) {
            $old = Product::where('sku', $oldSku)->first();
            $new = Product::where('sku', $newSku)->first();

            if ($old && $new) {
                $old->update(['successor_product_id' => $new->id]);
            }
        }
    }

    /**
     * @return array{product_category: ProductCategory, portfolio_role: ?PortfolioRole, journey_phase: ?JourneyPhase, wax_recipe: ?WaxRecipe, heater_generation: ?HeaterGeneration, is_discontinued: bool, discontinued_at: ?string}
     */
    private function make(
        ProductCategory $productCategory,
        ?PortfolioRole $portfolioRole = null,
        ?JourneyPhase $journeyPhase = null,
        ?WaxRecipe $waxRecipe = null,
        ?HeaterGeneration $heaterGeneration = null,
        bool $discontinued = false,
        ?string $discontinuedAt = null,
    ): array {
        return [
            'product_category' => $productCategory,
            'portfolio_role' => $portfolioRole,
            'journey_phase' => $journeyPhase,
            'wax_recipe' => $waxRecipe,
            'heater_generation' => $heaterGeneration,
            'is_discontinued' => $discontinued,
            'discontinued_at' => $discontinuedAt,
        ];
    }
}
