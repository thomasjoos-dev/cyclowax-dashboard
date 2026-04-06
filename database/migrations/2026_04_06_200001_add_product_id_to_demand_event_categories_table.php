<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demand_event_categories', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('product_category')
                ->constrained('products')->nullOnDelete();

            $table->dropUnique(['demand_event_id', 'product_category']);
            $table->unique(['demand_event_id', 'product_category', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('demand_event_categories', function (Blueprint $table) {
            $table->dropUnique(['demand_event_id', 'product_category', 'product_id']);
            $table->unique(['demand_event_id', 'product_category']);

            $table->dropConstrainedForeignId('product_id');
        });
    }
};
