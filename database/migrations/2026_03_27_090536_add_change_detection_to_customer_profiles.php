<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('previous_follower_segment')->nullable()->after('follower_segment');
            $table->timestamp('segment_synced_at')->nullable()->after('linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn(['previous_follower_segment', 'segment_synced_at']);
        });
    }
};
