<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->string('product_category')->nullable();
            $table->integer('forecasted_units');
            $table->decimal('forecasted_revenue', 12, 2);
            $table->integer('actual_units')->nullable();
            $table->decimal('actual_revenue', 12, 2)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['scenario_id', 'year_month', 'product_category'], 'snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
    }
};
