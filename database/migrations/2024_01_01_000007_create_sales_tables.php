<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // cashier
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('shipping_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->decimal('due_total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0); // for gross profit
            $table->string('payment_status', 20)->default('paid'); // paid, partial, due
            $table->string('status', 20)->default('completed'); // completed, held, voided, returned
            $table->string('source', 20)->default('online'); // online, offline
            $table->string('sync_status', 20)->default('synced'); // synced, pending, syncing, failed, attention
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamp('sold_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('sold_at');
            $table->index('sync_status');
            $table->index('customer_id');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku', 64)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('line_total', 15, 2); // price*qty - discount + tax
            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_id');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('tendered_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->string('status', 20)->default('completed'); // completed, pending
            $table->timestamps();

            $table->index('sale_id');
        });

        // Idempotency records to prevent duplicate processing of retried requests.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();
            $table->string('endpoint', 120);
            $table->string('resource_type', 60)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('status', 20)->default('completed');
            $table->json('response_payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
