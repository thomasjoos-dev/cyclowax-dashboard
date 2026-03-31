<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\SupplyProfile;
use Illuminate\Database\Seeder;

/**
 * Seed initial supply chain profiles per product category.
 * Values are estimates — to be validated by operations team.
 */
class SupplyProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
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

        foreach ($profiles as [$category, $leadTime, $moq, $buffer, $supplier]) {
            SupplyProfile::updateOrCreate(
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
