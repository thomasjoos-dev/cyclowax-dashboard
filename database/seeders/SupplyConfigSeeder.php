<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\SupplyConfig;
use Illuminate\Database\Seeder;

/**
 * Seed initial supply chain parameters per product category.
 * Values are estimates — to be validated by operations team.
 */
class SupplyConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [ProductCategory::WaxTablet, 45, 500, 14, 'Wax production'],
            [ProductCategory::PocketWax, 45, 200, 14, 'Wax production'],
            [ProductCategory::StarterKit, 60, 100, 21, 'Kit assembly'],
            [ProductCategory::WaxKit, 60, 100, 21, 'Kit assembly'],
            [ProductCategory::Chain, 60, 50, 21, 'Chain suppliers (SRAM, Shimano, KMC, etc.)'],
            [ProductCategory::ChainConsumable, 30, 100, 14, 'Quick link suppliers'],
            [ProductCategory::ChainTool, 45, 50, 14, 'Tool suppliers'],
            [ProductCategory::Heater, 90, 50, 30, 'Heater manufacturing'],
            [ProductCategory::HeaterAccessory, 60, 50, 14, 'Heater accessory production'],
            [ProductCategory::Cleaning, 45, 100, 14, 'Cleaning product supplier'],
        ];

        foreach ($configs as [$category, $leadTime, $moq, $buffer, $supplier]) {
            SupplyConfig::updateOrCreate(
                ['product_category' => $category->value],
                [
                    'lead_time_days' => $leadTime,
                    'moq' => $moq,
                    'buffer_days' => $buffer,
                    'supplier_name' => $supplier,
                ],
            );
        }
    }
}
