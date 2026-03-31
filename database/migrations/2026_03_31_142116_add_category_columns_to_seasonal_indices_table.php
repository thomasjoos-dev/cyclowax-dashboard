<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonal_indices', function (Blueprint $table) {
            $table->string('product_category')->nullable()->after('region');
            $table->string('forecast_group')->nullable()->after('product_category');

            $table->dropUnique(['month', 'region']);
            $table->unique(['month', 'region', 'product_category']);
        });
    }

    public function down(): void
    {
        Schema::table('seasonal_indices', function (Blueprint $table) {
            $table->dropUnique(['month', 'region', 'product_category']);
            $table->unique(['month', 'region']);

            $table->dropColumn(['product_category', 'forecast_group']);
        });
    }
};
