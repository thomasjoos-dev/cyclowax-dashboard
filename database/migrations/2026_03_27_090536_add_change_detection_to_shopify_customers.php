<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->string('previous_rfm_segment')->nullable()->after('rfm_segment');
            $table->timestamp('segment_synced_at')->nullable()->after('rfm_scored_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->dropColumn(['previous_rfm_segment', 'segment_synced_at']);
        });
    }
};
