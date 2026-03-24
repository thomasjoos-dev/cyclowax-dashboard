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
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->decimal('shipping_cost', 8, 2)->nullable();
            $table->string('shipping_carrier', 100)->nullable();
            $table->boolean('shipping_cost_estimated')->default(false);
            $table->decimal('shipping_margin', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_cost', 'shipping_carrier', 'shipping_cost_estimated', 'shipping_margin']);
        });
    }
};
