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
            $table->unsignedInteger('emails_received')->default(0)->after('klaviyo_updated_at');
            $table->unsignedInteger('emails_opened')->default(0)->after('emails_received');
            $table->unsignedInteger('emails_clicked')->default(0)->after('emails_opened');
            $table->timestamp('engagement_synced_at')->nullable()->after('emails_clicked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klaviyo_profiles', function (Blueprint $table) {
            $table->dropColumn(['emails_received', 'emails_opened', 'emails_clicked', 'engagement_synced_at']);
        });
    }
};
