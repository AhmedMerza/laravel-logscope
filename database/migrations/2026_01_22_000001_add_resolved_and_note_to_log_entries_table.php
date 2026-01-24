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
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_changed_by', 255)->nullable();
            $table->text('note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('logscope.table', 'log_entries'), function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'status_changed_at', 'status_changed_by', 'note']);
        });
    }
};
