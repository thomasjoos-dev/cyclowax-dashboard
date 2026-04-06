<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('purchase_calendar_runs')->cascadeOnDelete();
            $table->date('date');
            $table->string('event_type');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->decimal('quantity', 10, 2);
            $table->decimal('gross_quantity', 10, 2);
            $table->decimal('net_quantity', 10, 2);
            $table->string('supplier')->nullable();
            $table->string('product_category');
            $table->string('month_label', 3);
            $table->text('note')->nullable();

            $table->index(['run_id', 'date']);
            $table->index(['run_id', 'event_type']);
            $table->index(['run_id', 'product_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_calendar_events');
    }
};
