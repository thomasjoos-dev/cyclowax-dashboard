<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_assumptions', function (Blueprint $table) {
            $table->string('region')->nullable()->after('quarter');
            $table->decimal('retention_index', 4, 2)->nullable()->after('repeat_aov');

            $table->dropUnique(['scenario_id', 'quarter']);
            $table->unique(['scenario_id', 'quarter', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('scenario_assumptions', function (Blueprint $table) {
            $table->dropUnique(['scenario_id', 'quarter', 'region']);
            $table->unique(['scenario_id', 'quarter']);

            $table->dropColumn(['region', 'retention_index']);
        });
    }
};
