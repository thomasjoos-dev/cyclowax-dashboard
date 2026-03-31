<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_configs', function (Blueprint $table) {
            $table->id();
            $table->string('product_category')->unique();
            $table->unsignedSmallInteger('lead_time_days');
            $table->unsignedInteger('moq');
            $table->unsignedSmallInteger('buffer_days')->default(14);
            $table->string('supplier_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_configs');
    }
};
