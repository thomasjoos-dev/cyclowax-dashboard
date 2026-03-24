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
            // Basic order-level fields
            $table->string('landing_page_url', 500)->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->string('source_name', 50)->nullable()->index();

            // First-touch attribution
            $table->string('ft_source', 100)->nullable();
            $table->string('ft_source_type', 50)->nullable();
            $table->string('ft_utm_source', 100)->nullable()->index();
            $table->string('ft_utm_medium', 100)->nullable()->index();
            $table->string('ft_utm_campaign', 255)->nullable();
            $table->string('ft_utm_content', 255)->nullable();
            $table->string('ft_utm_term', 255)->nullable();
            $table->string('ft_landing_page', 500)->nullable();
            $table->string('ft_referrer_url', 500)->nullable();

            // Last-touch attribution
            $table->string('lt_source', 100)->nullable();
            $table->string('lt_source_type', 50)->nullable();
            $table->string('lt_utm_source', 100)->nullable()->index();
            $table->string('lt_utm_medium', 100)->nullable()->index();
            $table->string('lt_utm_campaign', 255)->nullable();
            $table->string('lt_utm_content', 255)->nullable();
            $table->string('lt_utm_term', 255)->nullable();
            $table->string('lt_landing_page', 500)->nullable();
            $table->string('lt_referrer_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn([
                'landing_page_url', 'referrer_url', 'source_name',
                'ft_source', 'ft_source_type', 'ft_utm_source', 'ft_utm_medium',
                'ft_utm_campaign', 'ft_utm_content', 'ft_utm_term',
                'ft_landing_page', 'ft_referrer_url',
                'lt_source', 'lt_source_type', 'lt_utm_source', 'lt_utm_medium',
                'lt_utm_campaign', 'lt_utm_content', 'lt_utm_term',
                'lt_landing_page', 'lt_referrer_url',
            ]);
        });
    }
};
