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
        Schema::create('product_boms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('odoo_bom_id')->unique();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('bom_type');
            $table->decimal('product_qty', 10, 4)->default(1);
            $table->decimal('assembly_lead_time_days', 5, 1)->nullable();
            $table->string('assembly_lead_time_source')->nullable();
            $table->unsignedSmallInteger('assembly_mos_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_boms');
    }
};
