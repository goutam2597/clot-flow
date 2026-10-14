<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->decimal('rate', 8, 4)->default(0); // percentage e.g. 15.0000
            $table->string('type', 20)->default('exclusive'); // inclusive, exclusive
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 8);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('symbol_position', 10)->default('before'); // before, after
            $table->string('thousand_separator', 4)->default(',');
            $table->string('decimal_separator', 4)->default('.');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique('code');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('type', 20)->default('cash'); // cash, card, bank, mobile, other
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->default('general');
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, boolean, integer, json
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('number_counters', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->unsignedBigInteger('current')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_counters');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('tax_rates');
    }
};
