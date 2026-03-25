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
        Schema::create('ad_spends', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('platform'); // google_ads, meta_ads
            $table->string('campaign_name');
            $table->string('campaign_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('channel_type')->nullable(); // SEARCH, SHOPPING, PMAX, DISPLAY, VIDEO, acquisition, retargeting
            $table->decimal('spend', 10, 2)->default(0);
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->decimal('conversions', 8, 2)->default(0);
            $table->decimal('conversions_value', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['date', 'platform']);
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_spends');
    }
};
