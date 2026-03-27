<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_category')->nullable()->index()->after('category');
            $table->string('portfolio_role')->nullable()->index()->after('product_category');
            $table->string('journey_phase')->nullable()->index()->after('portfolio_role');
            $table->string('wax_recipe')->nullable()->after('journey_phase');
            $table->string('heater_generation')->nullable()->after('wax_recipe');
            $table->boolean('is_discontinued')->default(false)->after('is_active');
            $table->date('discontinued_at')->nullable()->after('is_discontinued');
            $table->foreignId('successor_product_id')->nullable()->constrained('products')->nullOnDelete()->after('discontinued_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['successor_product_id']);
            $table->dropColumn([
                'product_category',
                'portfolio_role',
                'journey_phase',
                'wax_recipe',
                'heater_generation',
                'is_discontinued',
                'discontinued_at',
                'successor_product_id',
            ]);
        });
    }
};
