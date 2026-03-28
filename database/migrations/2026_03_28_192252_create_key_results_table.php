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
        Schema::create('key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('metric_key')->nullable();
            $table->decimal('target_value', 12, 2);
            $table->decimal('current_value', 12, 2)->nullable();
            $table->string('unit');
            $table->string('tracking_mode')->default('manual');
            $table->string('quarter', 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('objectives', function (Blueprint $table) {
            $table->foreign('parent_key_result_id')->references('id')->on('key_results')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('objectives', function (Blueprint $table) {
            $table->dropForeign(['parent_key_result_id']);
        });

        Schema::dropIfExists('key_results');
    }
};
