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
        Schema::create('product_stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_on_hand', 10, 2);
            $table->decimal('qty_forecasted', 10, 2);
            $table->decimal('qty_free', 10, 2);
            $table->dateTime('recorded_at')->index();
            $table->timestamps();

            $table->index(['product_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stock_snapshots');
    }
};
