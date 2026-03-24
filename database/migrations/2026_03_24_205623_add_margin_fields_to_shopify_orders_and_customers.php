<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->decimal('gross_margin', 12, 2)->nullable();
            $table->boolean('is_first_order')->nullable()->index();
        });

        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->unsignedInteger('local_orders_count')->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('first_order_channel', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'gross_margin', 'is_first_order']);
        });

        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->dropColumn(['local_orders_count', 'total_cost', 'first_order_channel']);
        });
    }
};
