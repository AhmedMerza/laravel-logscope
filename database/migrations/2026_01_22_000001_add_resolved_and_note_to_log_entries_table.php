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
        Schema::table(config('logscope.table', 'log_entries'), function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('resolved_by', 255)->nullable();
            $table->text('note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('logscope.table', 'log_entries'), function (Blueprint $table) {
            $table->dropIndex(['resolved_at']);
            $table->dropColumn(['resolved_at', 'resolved_by', 'note']);
        });
    }
};
