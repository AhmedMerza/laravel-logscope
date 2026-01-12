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
        Schema::create(config('logscope.tables.entries', 'log_entries'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('level', 20)->index();
            $table->text('message');
            $table->string('message_preview', 500)->nullable();
            $table->json('context')->nullable();
            $table->string('context_preview', 500)->nullable();
            $table->string('channel', 100)->nullable()->index();
            $table->string('environment', 50)->nullable()->index();
            $table->string('source', 500)->nullable();
            $table->unsignedInteger('source_line')->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->boolean('is_truncated')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'occurred_at']);
            $table->index(['channel', 'occurred_at']);
            $table->index(['environment', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('logscope.tables.entries', 'log_entries'));
    }
};
