<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('odoo_po_line_id')->unique();
            $table->string('po_reference', 30);
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('odoo_product_id');
            $table->string('product_name');
            $table->decimal('quantity_ordered', 10, 2);
            $table->decimal('quantity_received', 10, 2);
            $table->decimal('quantity_open', 10, 2);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->date('date_order');
            $table->date('date_planned')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('state', 20);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index('product_id');
            $table->index('date_planned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_purchase_orders');
    }
};
