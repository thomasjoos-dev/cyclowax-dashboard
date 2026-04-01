<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->dateTime('validated_at')->nullable()->after('notes');
            $table->string('validated_by')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('supply_profiles', function (Blueprint $table) {
            $table->dropColumn(['validated_at', 'validated_by']);
        });
    }
};
