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
        Schema::table('ad_spends', function (Blueprint $table) {
            $table->unique(
                ['date', 'platform', 'campaign_id', 'country_code'],
                'ad_spends_upsert_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_spends', function (Blueprint $table) {
            $table->dropUnique('ad_spends_upsert_unique');
        });
    }
};
