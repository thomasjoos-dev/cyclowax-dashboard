<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index(); // lifecycle_change, segment_change
            $table->string('from_lifecycle')->nullable();
            $table->string('to_lifecycle')->nullable();
            $table->string('from_segment')->nullable();
            $table->string('to_segment')->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_transitions');
    }
};
