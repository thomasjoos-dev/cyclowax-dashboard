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
        Schema::table('klaviyo_profiles', function (Blueprint $table) {
            $table->unsignedInteger('site_visits')->default(0)->after('engagement_synced_at');
            $table->unsignedInteger('product_views')->default(0)->after('site_visits');
            $table->unsignedInteger('cart_adds')->default(0)->after('product_views');
            $table->unsignedInteger('checkouts_started')->default(0)->after('cart_adds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klaviyo_profiles', function (Blueprint $table) {
            $table->dropColumn(['site_visits', 'product_views', 'cart_adds', 'checkouts_started']);
        });
    }
};
