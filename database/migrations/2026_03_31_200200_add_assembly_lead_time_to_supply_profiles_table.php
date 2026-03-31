<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->renameColumn('lead_time_days', 'procurement_lead_time_days');
        });

        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('assembly_lead_time_days')->default(0)->after('procurement_lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->dropColumn('assembly_lead_time_days');
        });

        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->renameColumn('procurement_lead_time_days', 'lead_time_days');
        });
    }
};
