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
        Schema::create('shopify_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shopify_orders')->cascadeOnDelete();
            $table->string('product_title');
            $table->string('product_type')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_line_items');
    }
};
