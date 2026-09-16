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
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->dropIndex($this->indexToDrop($table, ['resolved_at']));
            $blueprint->dropColumn(['resolved_at', 'resolved_by']);
        });

        // Remove environment if it exists
        if (Schema::hasColumn($table, 'environment')) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($this->indexToDrop($table, ['environment']));
            });

            // Try to drop composite index (may not exist on all databases)
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->dropIndex($this->indexToDrop($table, ['environment', 'level']));
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
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. This runs only on the
     * v0.5 upgrade path, so a new install never reached the failure. Only
     * Postgres needs it: MySQL scopes index names to their table, and SQLite's
     * grammar qualifies them itself. Blueprint only prefixes index names when
     * the connection sets prefix_indexes.
     */
    private function indexToDrop(string $table, array $columns): array|string
    {
        $connection = Schema::getConnection();

        if (! str_contains($table, '.') || $connection->getDriverName() !== 'pgsql') {
            return $columns;
        }

        $name = $connection->getConfig('prefix_indexes')
            ? substr_replace($table, '.'.$connection->getTablePrefix(), strrpos($table, '.'), 1)
            : $table;

        return substr($table, 0, strrpos($table, '.')).'.'
            .str_replace(['-', '.'], '_', strtolower($name.'_'.implode('_', $columns).'_index'));
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally empty (#41). After up() the table is the same whether this
     * was a new install (up() skipped) or a v0.5 upgrade, so down() can't tell
     * which to restore. Restoring the v0.5 columns broke rollback on new
     * installs: 2026_01_22's down() then couldn't find the status index. With
     * nothing here, 2026_01_22's down() removes the status columns on both.
     */
    public function down(): void
    {
        //
    }
};
