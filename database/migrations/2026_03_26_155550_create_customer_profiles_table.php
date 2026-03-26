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
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('lifecycle_stage')->index();
            $table->foreignId('shopify_customer_id')->nullable()->constrained('shopify_customers')->nullOnDelete();
            $table->foreignId('klaviyo_profile_id')->nullable()->constrained('klaviyo_profiles')->nullOnDelete();
            $table->string('follower_segment')->nullable()->index();
            $table->tinyInteger('engagement_score')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
