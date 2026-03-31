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
        Schema::create('product_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('product_boms')->cascadeOnDelete();
            $table->foreignId('component_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('odoo_component_product_id');
            $table->string('component_name');
            $table->decimal('quantity', 10, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_bom_lines');
    }
};
