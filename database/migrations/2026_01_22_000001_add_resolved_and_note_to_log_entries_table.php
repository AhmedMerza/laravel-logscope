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
        $table = config('logscope.table', 'log_entries');

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->dropIndex($this->indexToDrop($table, ['status']));
            $blueprint->dropColumn(['status', 'status_changed_at', 'status_changed_by', 'note']);
        });
    }

    /**
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. Only Postgres needs this:
     * MySQL scopes index names to their table, and SQLite's grammar qualifies
     * them itself. Blueprint only prefixes index names when the connection
     * sets prefix_indexes.
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
};
