<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_product_mixes', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('region')
                ->constrained('products')->cascadeOnDelete();
            $table->decimal('sku_share', 5, 4)->nullable()->after('product_id');

            $table->dropUnique(['scenario_id', 'product_category', 'region']);
            $table->unique(['scenario_id', 'product_category', 'region', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('scenario_product_mixes', function (Blueprint $table) {
            $table->dropUnique(['scenario_id', 'product_category', 'region', 'product_id']);
            $table->unique(['scenario_id', 'product_category', 'region']);

            $table->dropColumn('sku_share');
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
