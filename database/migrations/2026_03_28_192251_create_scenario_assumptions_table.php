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
        Schema::create('scenario_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->string('quarter', 2);
            $table->decimal('acq_rate', 6, 4);
            $table->decimal('repeat_rate', 6, 4);
            $table->decimal('repeat_aov', 8, 2);
            $table->timestamps();

            $table->unique(['scenario_id', 'quarter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenario_assumptions');
    }
};
