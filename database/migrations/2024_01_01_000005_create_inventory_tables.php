<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('reserved_quantity', 15, 4)->default(0);
            $table->decimal('avg_cost', 15, 4)->default(0);
            $table->timestamps();

            // A single stock row per warehouse/product/variant combination.
            $table->unique(['warehouse_id', 'product_id', 'product_variant_id'], 'stock_unique');
            $table->index('product_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // signed quantity: positive increases stock, negative decreases
            $table->decimal('quantity', 15, 4);
            $table->decimal('balance_after', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('type', 30); // sale, purchase, return, adjustment, transfer_in, transfer_out, opening, damage
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stocks');
    }
};
