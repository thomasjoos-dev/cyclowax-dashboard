<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_product_mixes', function (Blueprint $table) {
            $table->string('region')->nullable()->after('product_category');

            $table->dropUnique(['scenario_id', 'product_category']);
            $table->unique(['scenario_id', 'product_category', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('scenario_product_mixes', function (Blueprint $table) {
            $table->dropUnique(['scenario_id', 'product_category', 'region']);
            $table->unique(['scenario_id', 'product_category']);

            $table->dropColumn('region');
        });
    }
};
