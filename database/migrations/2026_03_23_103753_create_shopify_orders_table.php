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
        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_id')->unique();
            $table->string('name')->index();
            $table->timestamp('ordered_at')->index();
            $table->decimal('total_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('shipping', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('discounts', 12, 2)->default(0);
            $table->decimal('refunded', 12, 2)->default(0);
            $table->string('financial_status')->index();
            $table->string('fulfillment_status')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained('shopify_customers')->nullOnDelete();
            $table->string('country_code', 2)->nullable()->index();
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_orders');
    }
};
