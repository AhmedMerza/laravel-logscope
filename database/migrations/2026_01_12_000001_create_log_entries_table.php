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
        Schema::create(config('logscope.table', 'log_entries'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('level', 20)->index();
            $table->text('message');
            $table->string('message_preview', 500)->nullable();
            $table->json('context')->nullable();
            $table->string('context_preview', 500)->nullable();
            $table->string('channel', 100)->nullable()->index();
            $table->string('source', 500)->nullable();
            $table->unsignedInteger('source_line')->nullable();
            $table->uuid('trace_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index(); // 45 chars for IPv6
            $table->string('user_agent', 500)->nullable();
            $table->string('http_method', 10)->nullable()->index();
            $table->string('url', 2000)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->boolean('is_truncated')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'occurred_at']);
            $table->index(['channel', 'occurred_at']);
            $table->index(['trace_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('logscope.table', 'log_entries'));
    }
};
