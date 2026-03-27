<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->renameColumn('segment_synced_at', 'klaviyo_synced_at');
        });

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->timestamp('shopify_synced_at')->nullable()->after('klaviyo_synced_at');
        });

        // Also drop segment_synced_at from shopify_customers — not needed there
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->dropColumn('segment_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->timestamp('segment_synced_at')->nullable()->after('rfm_scored_at');
        });

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->dropColumn('shopify_synced_at');
        });

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->renameColumn('klaviyo_synced_at', 'segment_synced_at');
        });
    }
};
