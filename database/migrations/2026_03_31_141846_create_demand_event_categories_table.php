<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_event_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demand_event_id')->constrained()->cascadeOnDelete();
            $table->string('product_category');
            $table->unsignedInteger('expected_uplift_units')->nullable();
            $table->decimal('pull_forward_pct', 4, 2)->default(0);
            $table->timestamps();

            $table->unique(['demand_event_id', 'product_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_event_categories');
    }
};
