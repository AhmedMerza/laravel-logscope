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
        Schema::create(config('logscope.tables.presets', 'filter_presets'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('filters');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_default');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('logscope.tables.presets', 'filter_presets'));
    }
};
