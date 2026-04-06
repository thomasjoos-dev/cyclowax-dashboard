<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_snapshots', function (Blueprint $table) {
            $table->string('region')->nullable()->after('product_category');

            $table->dropUnique('snapshot_unique');
            $table->unique(['scenario_id', 'year_month', 'product_category', 'region'], 'snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_snapshots', function (Blueprint $table) {
            $table->dropUnique('snapshot_unique');
            $table->unique(['scenario_id', 'year_month', 'product_category'], 'snapshot_unique');

            $table->dropColumn('region');
        });
    }
};
