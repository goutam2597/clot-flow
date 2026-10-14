<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('code', 10)->unique(); // e.g. en, bn
            $table->string('locale', 20)->nullable(); // e.g. en_US, bn_BD
            $table->string('direction', 3)->default('ltr'); // ltr, rtl
            $table->string('flag', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('group', 60)->default('*'); // e.g. validation, ui, *
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['language_id', 'group', 'key'], 'translation_unique');
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
