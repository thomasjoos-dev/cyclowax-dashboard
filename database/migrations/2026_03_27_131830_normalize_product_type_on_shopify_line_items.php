<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $normalizations = [
            'Wax Kit' => 'wax_kit',
            'Quick Links' => 'quick_link',
            'Gift Cards' => 'gift_card',
            'FREE PRODUCT' => 'promotional',
            'bogos' => 'promotional',
            'starter kit' => 'starter_kit',
            'heater accessory' => 'heater_accessory',
        ];

        foreach ($normalizations as $old => $new) {
            DB::table('shopify_line_items')
                ->where('product_type', $old)
                ->update(['product_type' => $new]);
        }

        // Fix empty product_type for Pocket Wax products
        DB::table('shopify_line_items')
            ->where('product_type', '')
            ->where(function ($query) {
                $query->where('product_title', 'like', '%Pocket Wax%');
            })
            ->update(['product_type' => 'pocket_wax']);

        // Normalize product_type on shopify_products table too
        foreach ($normalizations as $old => $new) {
            DB::table('shopify_products')
                ->where('product_type', $old)
                ->update(['product_type' => $new]);
        }

        // Normalize product_type on products table
        foreach ($normalizations as $old => $new) {
            DB::table('products')
                ->where('product_type', $old)
                ->update(['product_type' => $new]);
        }
    }

    public function down(): void
    {
        $reversals = [
            'wax_kit' => 'Wax Kit',
            'quick_link' => 'Quick Links',
            'gift_card' => 'Gift Cards',
            'promotional' => 'FREE PRODUCT',
            'starter_kit' => 'starter kit',
            'heater_accessory' => 'heater accessory',
            'pocket_wax' => '',
        ];

        foreach ($reversals as $new => $old) {
            DB::table('shopify_line_items')
                ->where('product_type', $new)
                ->update(['product_type' => $old]);
        }
    }
};
