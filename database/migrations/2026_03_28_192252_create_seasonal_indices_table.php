<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seasonal_indices', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->decimal('index_value', 6, 4);
            $table->string('region')->nullable();
            $table->string('source')->default('calculated');
            $table->timestamps();

            $table->unique(['month', 'region']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasonal_indices');
    }
};
