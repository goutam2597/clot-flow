<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Held (parked) sales.
 *
 * A customer steps away, the next one queues, and the basket has to survive a
 * reload, a crash and a closed browser. Storing it on the server rather than
 * only in the till's IndexedDB means a hold outlives the machine as well — the
 * next cashier on that register can pick it up.
 *
 * The payload is the cart exactly as the cashier left it: lines, customer and
 * cart-level discount. A hold that comes back subtly different is a hold the
 * cashier cannot trust, so the whole thing is round-tripped verbatim and
 * repriced from live product records on resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();

            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // What the cashier will recognise it by, e.g. "Hold — 14:32".
            $table->string('label', 120);

            // { lines: [...], customer_id, customer_name, discount_total, note }
            $table->json('payload');

            $table->unsignedSmallInteger('line_count')->default(0);
            $table->decimal('cached_total', 15, 4)->default(0);

            $table->timestamp('parked_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // A register's live holds are the only set anyone ever lists.
            $table->index(['branch_id', 'released_at']);
            $table->index(['cash_register_session_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
    }
};