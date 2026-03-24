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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('product_type')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->string('shopify_product_id')->nullable();
            $table->unsignedInteger('odoo_product_id')->nullable();
            $table->decimal('cost_price', 8, 4)->nullable();
            $table->decimal('list_price', 8, 2)->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
