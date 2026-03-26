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
        Schema::create('klaviyo_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('klaviyo_id')->unique();
            $table->string('email')->nullable()->index();
            $table->string('phone_number')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('organization')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('zip')->nullable();
            $table->string('timezone')->nullable();
            $table->json('properties')->nullable();
            $table->decimal('historic_clv', 12, 2)->nullable();
            $table->decimal('predicted_clv', 12, 2)->nullable();
            $table->decimal('total_clv', 12, 2)->nullable();
            $table->unsignedInteger('historic_number_of_orders')->nullable();
            $table->unsignedInteger('predicted_number_of_orders')->nullable();
            $table->decimal('average_order_value', 10, 2)->nullable();
            $table->decimal('churn_probability', 5, 4)->nullable();
            $table->decimal('average_days_between_orders', 8, 2)->nullable();
            $table->timestamp('expected_date_of_next_order')->nullable();
            $table->timestamp('last_event_date')->nullable();
            $table->timestamp('klaviyo_created_at')->nullable();
            $table->timestamp('klaviyo_updated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('klaviyo_profiles');
    }
};
