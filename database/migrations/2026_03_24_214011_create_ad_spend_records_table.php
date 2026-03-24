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
        Schema::create('ad_spend_records', function (Blueprint $table) {
            $table->id();
            $table->date('period')->index();
            $table->string('channel', 50)->index();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('campaign_name', 255)->nullable();
            $table->decimal('spend', 12, 2);
            $table->unsignedInteger('impressions')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->unsignedInteger('conversions')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('imported_at');
            $table->timestamps();

            $table->index(['period', 'channel', 'country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_spend_records');
    }
};
