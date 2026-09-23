<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roll repeated entries up into groups (#29).
 *
 * Every entry gets a fingerprint; entries sharing one are the same issue.
 * The group carries the triage state — status, note, occurrence count — so
 * resolving an error that fired 3,000 times is one row written, not 3,000.
 *
 * The fingerprint column is nullable because existing rows have none until
 * `logscope:backfill-fingerprints` has run, and the backfill finds its work
 * with `whereNull('fingerprint')` — which needs the plain index to be quick.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $entries = config('logscope.table', 'log_entries');

        if (! Schema::hasColumn($entries, 'fingerprint')) {
            Schema::table($entries, function (Blueprint $table) {
                $table->string('fingerprint', 40)->nullable()->index();

                // Opening a group lists its most recent occurrences, newest
                // first, paginated: WHERE fingerprint = ? ORDER BY occurred_at
                // DESC. Without the composite, that sorts every row in the
                // group before it can return the first page — and a group with
                // thousands of rows is the whole point of the feature.
                $table->index(['fingerprint', 'occurred_at']);
            });
        }

        Schema::create(config('logscope.groups_table', 'log_groups'), function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The upsert on the write path keys on this, so it must be unique:
            // ON CONFLICT / ON DUPLICATE KEY has nothing to match without it.
            $table->string('fingerprint', 40)->unique();

            $table->text('sample_message');
            $table->string('level', 20)->index();
            $table->string('channel', 100)->nullable()->index();
            $table->unsignedBigInteger('occurrence_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            // Mirrors log_entries: same column names, same types, so the
            // status vocabulary is identical on both sides.
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_changed_by', 255)->nullable();
            $table->text('note')->nullable();

            // When a Resolved group sees a new occurrence it reopens and
            // stamps this, so "came back" is distinguishable from "never
            // triaged" — both read as Open without it.
            $table->timestamp('regressed_at')->nullable();

            $table->timestamps();

            // The default list: hide closed groups, newest activity first.
            $table->index(['status', 'last_seen_at']);
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('logscope.groups_table', 'log_groups'));

        $entries = config('logscope.table', 'log_entries');

        if (! Schema::hasColumn($entries, 'fingerprint')) {
            return;
        }

        Schema::table($entries, function (Blueprint $table) use ($entries) {
            $table->dropIndex($this->indexToDrop($entries, ['fingerprint', 'occurred_at']));
            $table->dropIndex($this->indexToDrop($entries, ['fingerprint']));
            $table->dropColumn('fingerprint');
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
     *
     * Each segment is wrapped on its own and handed over already quoted:
     * Grammar::wrap() on a dotted string treats the first segment as a table
     * and prepends the connection's table prefix, so a prefixed connection
     * would look for the index under a schema that doesn't exist.
     */
    private function indexToDrop(string $table, array $columns): array|Expression
    {
        $connection = Schema::getConnection();

        if (! str_contains($table, '.') || $connection->getDriverName() !== 'pgsql') {
            return $columns;
        }

        $name = $connection->getConfig('prefix_indexes')
            ? substr_replace($table, '.'.$connection->getTablePrefix(), strrpos($table, '.'), 1)
            : $table;

        $grammar = $connection->getQueryGrammar();

        return $connection->raw(
            $grammar->wrap(substr($table, 0, strrpos($table, '.'))).'.'
            .$grammar->wrap(str_replace(['-', '.'], '_', strtolower($name.'_'.implode('_', $columns).'_index')))
        );
    }
};
