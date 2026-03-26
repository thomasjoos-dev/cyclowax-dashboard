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
        Schema::create('klaviyo_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('klaviyo_id')->unique();
            $table->string('name');
            $table->string('channel')->index();
            $table->string('status')->index();
            $table->boolean('archived')->default(false);
            $table->string('send_strategy')->nullable();
            $table->boolean('is_tracking_opens')->default(false);
            $table->boolean('is_tracking_clicks')->default(false);
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('opens_unique')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('clicks_unique')->default(0);
            $table->unsignedInteger('unsubscribes')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('conversion_value', 12, 2)->default(0);
            $table->decimal('revenue_per_recipient', 10, 4)->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('send_time')->nullable();
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
        Schema::dropIfExists('klaviyo_campaigns');
    }
};
