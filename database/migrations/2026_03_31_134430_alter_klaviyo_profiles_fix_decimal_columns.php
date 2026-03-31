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
            $table->decimal('historic_number_of_orders', 10, 2)->nullable()->change();
            $table->decimal('predicted_number_of_orders', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klaviyo_profiles', function (Blueprint $table) {
            $table->unsignedInteger('historic_number_of_orders')->nullable()->change();
            $table->unsignedInteger('predicted_number_of_orders')->nullable()->change();
        });
    }
};
