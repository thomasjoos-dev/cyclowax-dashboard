<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('customer_profiles', 'rider_profiles');

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->renameColumn('follower_segment', 'segment');
            $table->renameColumn('previous_follower_segment', 'previous_segment');
        });

        // Add segment_changed_at for tracking when segment last changed
        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->timestamp('segment_changed_at')->nullable()->after('previous_segment');
        });

        // Rename FK in segment_transitions
        Schema::table('segment_transitions', function (Blueprint $table) {
            $table->renameColumn('customer_profile_id', 'rider_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('segment_transitions', function (Blueprint $table) {
            $table->renameColumn('rider_profile_id', 'customer_profile_id');
        });

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->dropColumn('segment_changed_at');
        });

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->renameColumn('segment', 'follower_segment');
            $table->renameColumn('previous_segment', 'previous_follower_segment');
        });

        Schema::rename('rider_profiles', 'customer_profiles');
    }
};
