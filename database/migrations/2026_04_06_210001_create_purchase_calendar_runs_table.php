<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_calendar_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('warehouse')->nullable();
            $table->dateTime('generated_at');
            $table->json('summary');
            $table->json('netting_summary');
            $table->json('sku_mix_summary');
            $table->timestamps();

            $table->unique(['scenario_id', 'year', 'warehouse']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_calendar_runs');
    }
};
