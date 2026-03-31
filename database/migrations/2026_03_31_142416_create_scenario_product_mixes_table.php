<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_product_mixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->string('product_category');
            $table->decimal('acq_share', 5, 4);
            $table->decimal('repeat_share', 5, 4);
            $table->decimal('avg_unit_price', 8, 2);
            $table->timestamps();

            $table->unique(['scenario_id', 'product_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_product_mixes');
    }
};
