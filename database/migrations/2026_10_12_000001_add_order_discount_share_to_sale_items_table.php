<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each sale line's share of an order-level discount.
 *
 * The POS allocates a document discount across the lines in proportion to
 * their net amount *before* tax, so tax is charged on what the customer
 * actually pays. Persisting the share keeps the document auditable: a
 * receipt, a credit note and the offline reconciliation can all show how a
 * single order-level discount reached each line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('order_discount_share', 15, 2)
                ->default(0)
                ->after('discount_amount')
                ->comment('Portion of the document-level discount allocated to this line');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('order_discount_share', 15, 2)
                ->default(0)
                ->after('discount_amount')
                ->comment('Portion of the document-level discount allocated to this line');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('order_discount_share');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('order_discount_share');
        });
    }
};
