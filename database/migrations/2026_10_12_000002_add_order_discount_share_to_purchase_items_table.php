<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each purchase line's share of an order-level discount.
 *
 * Mirrors the sale-side column: the document discount is allocated across the
 * lines before tax so the supplier is charged tax on what is actually owed, and
 * the split is kept for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_items', 'order_discount_share')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->decimal('order_discount_share', 15, 2)
                    ->default(0)
                    ->after('discount_amount')
                    ->comment('Portion of the document-level discount allocated to this line');
            });
        }
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('order_discount_share');
        });
    }
};
