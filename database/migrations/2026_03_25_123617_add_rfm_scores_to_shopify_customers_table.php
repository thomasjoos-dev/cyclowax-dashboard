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
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->tinyInteger('r_score')->nullable()->after('first_order_channel');
            $table->tinyInteger('f_score')->nullable()->after('r_score');
            $table->tinyInteger('m_score')->nullable()->after('f_score');
            $table->string('rfm_segment')->nullable()->after('m_score');
            $table->dateTime('rfm_scored_at')->nullable()->after('rfm_segment');

            $table->index('rfm_segment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->dropIndex(['rfm_segment']);
            $table->dropColumn(['r_score', 'f_score', 'm_score', 'rfm_segment', 'rfm_scored_at']);
        });
    }
};
