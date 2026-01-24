<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for upgrading from v0.4/v0.5 to v0.6.
 *
 * This migration handles the transition from resolved_at/resolved_by
 * to the new status system, and removes the unused environment column.
 *
 * For new installs, this migration is skipped (columns don't exist).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('logscope.table', 'log_entries');

        // Skip if this is a new install (resolved_at doesn't exist)
        if (! Schema::hasColumn($table, 'resolved_at')) {
            return;
        }

        // Add new status columns if they don't exist
        if (! Schema::hasColumn($table, 'status')) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('status', 20)->default('open')->index();
                $table->timestamp('status_changed_at')->nullable();
                $table->string('status_changed_by', 255)->nullable();
            });
        }

        // Migrate existing resolved logs
        DB::table($table)
            ->whereNotNull('resolved_at')
            ->update([
                'status' => 'resolved',
                'status_changed_at' => DB::raw('resolved_at'),
                'status_changed_by' => DB::raw('resolved_by'),
            ]);

        // Remove old columns
        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(['resolved_at']);
            $table->dropColumn(['resolved_at', 'resolved_by']);
        });

        // Remove environment if it exists
        if (Schema::hasColumn($table, 'environment')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['environment']);
            });

            // Try to drop composite index (may not exist on all databases)
            try {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex(['environment', 'level']);
                });
            } catch (\Exception $e) {
                // Index might not exist, ignore
            }

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('logscope.table', 'log_entries');

        // Skip if status column doesn't exist
        if (! Schema::hasColumn($table, 'status')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            // Restore old columns
            $table->string('environment', 50)->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('resolved_by', 255)->nullable();

            // Add back composite index
            $table->index(['environment', 'level']);
        });

        // Migrate resolved status back
        DB::table($table)
            ->where('status', 'resolved')
            ->update([
                'resolved_at' => DB::raw('status_changed_at'),
                'resolved_by' => DB::raw('status_changed_by'),
            ]);

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'status_changed_at', 'status_changed_by']);
        });
    }
};
