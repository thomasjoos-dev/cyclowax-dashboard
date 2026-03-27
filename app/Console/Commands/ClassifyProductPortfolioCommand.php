<?php

namespace App\Console\Commands;

use App\Enums\HeaterGeneration;
use App\Enums\JourneyPhase;
use App\Enums\PortfolioRole;
use App\Enums\ProductCategory;
use App\Enums\WaxRecipe;
use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:classify-portfolio {--force : Overwrite existing classifications}')]
#[Description('Classify all products with portfolio role, category, journey phase, wax recipe and heater generation')]
class ClassifyProductPortfolioCommand extends Command
{
    private int $classified = 0;

    private int $skipped = 0;

    private int $unmatched = 0;

    public function handle(): int
    {
        $force = $this->option('force');

        $products = Product::all();
        $this->info("Classifying {$products->count()} products...");

        foreach ($products as $product) {
            if (! $force && $product->product_category !== null) {
                $this->skipped++;

                continue;
            }

            $this->classifyProduct($product);
        }

        $this->linkSuccessors();
        $this->printSummary();

        return self::SUCCESS;
    }

    private function classifyProduct(Product $product): void
    {
        $sku = $product->sku ?? '';
        $name = $product->name ?? '';
        $category = $product->category ?? '';

        // Skip internal/operational Odoo items
        if ($this->isInternalProduct($sku, $category)) {
            return;
        }

        $classification = $this->detectCategory($sku, $name, $category);

        if ($classification === null) {
            $this->unmatched++;
            $this->warn("  Unmatched: [{$product->id}] {$sku} — {$name} (cat: {$category})");

            return;
        }

        $product->update([
            'product_category' => $classification['product_category'],
            'portfolio_role' => $classification['portfolio_role'],
            'journey_phase' => $classification['journey_phase'],
            'wax_recipe' => $classification['wax_recipe'] ?? null,
            'heater_generation' => $classification['heater_generation'] ?? null,
            'is_discontinued' => $classification['is_discontinued'] ?? false,
            'discontinued_at' => $classification['discontinued_at'] ?? null,
        ]);

        $this->classified++;
    }

    /**
     * @return array{product_category: ProductCategory, portfolio_role: ?PortfolioRole, journey_phase: ?JourneyPhase, wax_recipe: ?WaxRecipe, heater_generation: ?HeaterGeneration, is_discontinued: bool, discontinued_at: ?string}|null
     */
    private function detectCategory(string $sku, string $name, string $category): ?array
    {
        $nameLower = strtolower($name);

        // === CHAINS (prewaxed) ===
        if (str_starts_with($sku, 'CH-')) {
            return $this->make(ProductCategory::Chain, PortfolioRole::RetentionDriver, JourneyPhase::WaxRoutineCycle);
        }

        // === CHAIN OEM (raw materials for waxing) ===
        if (str_starts_with($sku, 'OEM_CH_')) {
            return $this->make(ProductCategory::Chain, null, null);
        }

        // === QUICK LINKS (chain consumables) ===
        if (str_starts_with($sku, 'QL-') || str_starts_with($sku, 'OEM_QL_')) {
            return $this->make(ProductCategory::ChainConsumable, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === POCKET WAX ===
        if (in_array($sku, ['WX-POCK', 'WX-PPOCK'])) {
            $recipe = $sku === 'WX-PPOCK' ? WaxRecipe::Performance : WaxRecipe::Core;

            return $this->make(ProductCategory::PocketWax, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle, waxRecipe: $recipe);
        }
        if ($sku === 'WX-POCK-old') {
            return $this->make(ProductCategory::PocketWax, null, null, waxRecipe: WaxRecipe::Core, discontinued: true, discontinuedAt: '2025-01-01');
        }

        // === WAX TABLETS ===
        if (str_starts_with($sku, 'WX-')) {
            $recipe = $this->detectWaxRecipe($sku, $nameLower);
            $discontinued = $this->isDiscontinuedWax($sku, $nameLower);

            // Performance Heater specific wax tablet variants
            $role = $discontinued ? null : PortfolioRole::RetentionDriver;

            return $this->make(ProductCategory::WaxTablet, $role, JourneyPhase::WaxRoutineCycle, waxRecipe: $recipe, discontinued: $discontinued, discontinuedAt: $discontinued ? '2025-09-01' : null);
        }

        // === OEM WAX (raw materials) ===
        if (str_starts_with($sku, 'OEM_WX_')) {
            return $this->make(ProductCategory::WaxTablet, null, null);
        }

        // === PERFORMANCE WAX KIT (new, performance heater) ===
        if (str_starts_with($sku, 'SK-PWK') || str_starts_with($sku, 'PRE-SK-PWK')) {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Performance);
        }

        // === WAXING KIT (original heater) ===
        if (str_starts_with($sku, 'SK-WK') || $sku === 'JH_WK') {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Original);
        }

        // === CHRISTMAS WAXING KIT (seasonal, original heater) ===
        if (str_starts_with($sku, 'SK-CWK')) {
            return $this->make(ProductCategory::WaxKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Core, heaterGeneration: HeaterGeneration::Original);
        }

        // === STARTER KITS (with chain, original heater) ===
        if (str_starts_with($sku, 'SK-')) {
            return $this->make(ProductCategory::StarterKit, PortfolioRole::Acquisition, JourneyPhase::GettingStarted, waxRecipe: WaxRecipe::Performance, heaterGeneration: HeaterGeneration::Original);
        }

        // === HEATERS (sold separately) ===
        if (str_starts_with($sku, 'OEM-WH')) {
            return $this->make(ProductCategory::Heater, PortfolioRole::MarginProtector, JourneyPhase::WaxRoutineCycle, heaterGeneration: HeaterGeneration::Original);
        }

        // === HEATER ACCESSORIES ===
        if (in_array($sku, ['TL-SW', 'TL-HUT', 'TL-PM'])) {
            return $this->make(ProductCategory::HeaterAccessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === DAYSAVER MULTI-TOOLS ===
        if (str_starts_with($sku, 'TL-DS')) {
            return $this->make(ProductCategory::MultiTool, PortfolioRole::MarginProtector, JourneyPhase::WaxRoutineCycle);
        }

        // === CHAIN TOOLS ===
        if (in_array($sku, ['TL-CC', 'TL-MLIP', 'TL-MLRP', 'TL-QLIP', 'TL-QLRP', 'TL-PTCC', 'TL-CN42'])) {
            return $this->make(ProductCategory::ChainTool, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === SPOOL & CHAIN RACK ===
        if (str_starts_with($sku, 'SP-') || $sku === 'RACK-3') {
            return $this->make(ProductCategory::ChainTool, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === BIKE PREP KIT (cleaning) ===
        if ($sku === 'BK-KIT') {
            return $this->make(ProductCategory::Cleaning, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === FRAME STICKER (accessory) ===
        if ($sku === 'MR-CFS') {
            return $this->make(ProductCategory::Accessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === TRAVEL PLUG ===
        if ($sku === 'TL-TPCH') {
            return $this->make(ProductCategory::Accessory, PortfolioRole::LoyaltyBuilder, JourneyPhase::WaxRoutineCycle);
        }

        // === STARTER KIT SLEEVE (packaging) ===
        if ($sku === 'SL-LT') {
            return $this->make(ProductCategory::Promotional, null, null);
        }

        // === GIFT CARD ===
        if ($sku === 'GIFT' || str_contains($nameLower, 'gift card')) {
            return $this->make(ProductCategory::GiftCard, null, null);
        }

        // === DISPLAY (POP) ===
        if (str_starts_with($sku, 'POP-') || str_starts_with($sku, 'OEM_POP_')) {
            return $this->make(ProductCategory::Promotional, null, null);
        }

        // === POWER CORDS (heater components) ===
        if (str_starts_with($sku, 'PC')) {
            return $this->make(ProductCategory::HeaterAccessory, null, null);
        }

        // === SHOPIFY SYSTEM PRODUCTS ===
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
        // Limited editions
        if (str_contains($nameLower, 'giro') || str_contains($nameLower, 'vuelta')) {
            return WaxRecipe::Performance;
        }

        return null;
    }

    private function isDiscontinuedWax(string $sku, string $nameLower): bool
    {
        // Basic Wax Tablet (old name for Core)
        if ($sku === 'WX-BASIC-old') {
            return true;
        }

        return false;
    }

    private function isInternalProduct(string $sku, string $category): bool
    {
        $internalSkus = ['COMM', 'FOOD', 'MIL', 'EXP_GEN', 'TRANS & ACC', 'shopifytip'];

        if (in_array($sku, $internalSkus)) {
            return true;
        }

        // Delivery SKUs
        if (str_starts_with($sku, 'Delivery_')) {
            return true;
        }

        // Category-based: "All" without subcategory is internal
        if ($category === 'All') {
            return true;
        }

        return false;
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

    private function linkSuccessors(): void
    {
        $successors = [
            'WX-BASIC-old' => 'WX-BASIC',   // Basic Wax Tablet → Core Wax Tablet
            'WX-POCK-old' => 'WX-POCK',      // Old Pocket Wax → Core Pocket Wax
        ];

        foreach ($successors as $oldSku => $newSku) {
            $old = Product::where('sku', $oldSku)->first();
            $new = Product::where('sku', $newSku)->first();

            if ($old && $new) {
                $old->update(['successor_product_id' => $new->id]);
                $this->line("  Linked successor: {$oldSku} → {$newSku}");
            }
        }
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info("Classification complete: {$this->classified} classified, {$this->skipped} skipped, {$this->unmatched} unmatched");
        $this->newLine();

        // Summary by category
        $this->table(
            ['Category', 'Count', 'Portfolio Role(s)'],
            Product::whereNotNull('product_category')
                ->selectRaw('product_category, portfolio_role, count(*) as cnt')
                ->groupBy('product_category', 'portfolio_role')
                ->orderBy('product_category')
                ->get()
                ->map(fn ($row) => [
                    $row->product_category instanceof ProductCategory ? $row->product_category->label() : $row->product_category,
                    $row->cnt,
                    $row->portfolio_role instanceof PortfolioRole ? $row->portfolio_role->label() : ($row->portfolio_role ?? '—'),
                ])
                ->toArray()
        );

        // Discontinued products
        $discontinued = Product::where('is_discontinued', true)->get();
        if ($discontinued->isNotEmpty()) {
            $this->newLine();
            $this->info('Discontinued products:');
            foreach ($discontinued as $p) {
                $successor = $p->successor ? " → {$p->successor->name}" : '';
                $this->line("  [{$p->sku}] {$p->name}{$successor}");
            }
        }
    }
}
