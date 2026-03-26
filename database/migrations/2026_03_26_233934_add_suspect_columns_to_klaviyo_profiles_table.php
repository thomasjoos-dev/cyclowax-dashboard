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
            $table->boolean('is_suspect')->default(false)->after('checkouts_started');
            $table->string('suspect_reason')->nullable()->after('is_suspect');

            $table->index('is_suspect');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klaviyo_profiles', function (Blueprint $table) {
            $table->dropIndex(['is_suspect']);
            $table->dropColumn(['is_suspect', 'suspect_reason']);
        });
    }
};
