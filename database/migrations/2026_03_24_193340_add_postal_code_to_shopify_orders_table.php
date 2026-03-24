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
            $table->string('billing_postal_code', 20)->nullable()->after('billing_province_code');
            $table->string('shipping_postal_code', 20)->nullable()->after('shipping_province_code');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn(['billing_postal_code', 'shipping_postal_code']);
        });
    }
};
