<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->renameColumn('country_code', 'billing_country_code');
            $table->renameColumn('province_code', 'shipping_province_code');
        });

        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->string('shipping_country_code', 2)->nullable()->after('billing_country_code')->index();
            $table->string('billing_province_code', 10)->nullable()->after('billing_country_code');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_country_code', 'billing_province_code']);
        });

        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->renameColumn('billing_country_code', 'country_code');
            $table->renameColumn('shipping_province_code', 'province_code');
        });
    }
};
