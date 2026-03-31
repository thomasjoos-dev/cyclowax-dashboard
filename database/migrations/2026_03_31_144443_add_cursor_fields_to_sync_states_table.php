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
        Schema::table('sync_states', function (Blueprint $table) {
            $table->string('status')->default('idle')->after('step');
            $table->text('cursor')->nullable()->after('was_full_sync');
            $table->timestamp('started_at')->nullable()->after('cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_states', function (Blueprint $table) {
            $table->dropColumn(['status', 'cursor', 'started_at']);
        });
    }
};
