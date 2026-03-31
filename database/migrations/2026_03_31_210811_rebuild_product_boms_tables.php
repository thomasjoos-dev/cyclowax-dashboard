<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_bom_lines');
        Schema::dropIfExists('product_boms');

        Schema::create('product_boms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('odoo_bom_id')->unique();
            $table->unsignedInteger('product_id');
            $table->string('bom_type', 10);
            $table->decimal('product_qty', 10, 4)->default(1);
            $table->decimal('assembly_lead_time_days', 6, 1)->default(0);
            $table->string('assembly_time_source', 20)->nullable();
            $table->unsignedSmallInteger('assembly_time_samples')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('product_boms')->cascadeOnDelete();
            $table->unsignedInteger('component_product_id');
            $table->decimal('quantity', 10, 4);
            $table->timestamps();

            $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bom_lines');
        Schema::dropIfExists('product_boms');
    }
};
