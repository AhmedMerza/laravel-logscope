<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogScope\Enums\LogStatus;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;

/**
 * Keeps log_groups in step with the entries being written (#29).
 *
 * The cost of this is fixed per flush, not per entry: whether a batch carries
 * one row or five hundred, it costs three statements. That matters because
 * the write path already bulk-inserts — a per-entry group update would undo
 * the batching that LogBuffer exists to provide.
 *
 *   1. insertOrIgnore  — create rows for fingerprints not seen before
 *   2. update … case   — add each fingerprint's count, advance last_seen_at
 *   3. update … where  — reopen groups that were Resolved and just fired again
 *
 * Step 3 is what makes triage safe. Without it, resolving an error hides every
 * future occurrence of it too, so an error that comes back is invisible in the
 * one view that is supposed to show you what is wrong. Ignored groups are left
 * alone — staying quiet is exactly what that status is for.
 */
final class GroupRecorder
{
    /**
     * Record occurrences for a set of prepared entry rows.
     *
     * @param  array<int, array<string, mixed>>  $rows  Rows as handed to insert()
     */
    public static function record(array $rows): void
    {
        $summaries = self::summarize($rows);

        if ($summaries === []) {
            return;
        }

        self::insertMissing($summaries);
        self::bumpCounts($summaries);
        self::reopenRegressed(array_keys($summaries));
    }

    /**
     * Collapse rows to one summary per fingerprint, so the statements below
     * carry one entry per distinct issue rather than one per log line.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function summarize(array $rows): array
    {
        $summaries = [];

        foreach ($rows as $row) {
            $fingerprint = $row['fingerprint'] ?? null;

            if (! is_string($fingerprint) || $fingerprint === '') {
                continue;
            }

            $occurredAt = self::timestamp($row['occurred_at'] ?? null);

            if (! isset($summaries[$fingerprint])) {
                $summaries[$fingerprint] = [
                    'count' => 0,
                    'first_seen_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    // message_preview is already capped to the configured
                    // preview length; the full message can be a megabyte.
                    'sample_message' => (string) ($row['message_preview'] ?? $row['message'] ?? ''),
                    'level' => (string) ($row['level'] ?? ''),
                    'channel' => $row['channel'] ?? null,
                ];
            }

            $summaries[$fingerprint]['count']++;
            $summaries[$fingerprint]['first_seen_at'] = min($summaries[$fingerprint]['first_seen_at'], $occurredAt);
            $summaries[$fingerprint]['last_seen_at'] = max($summaries[$fingerprint]['last_seen_at'], $occurredAt);
        }

        return $summaries;
    }

    /**
     * Create group rows for fingerprints that have none.
     *
     * insertOrIgnore rather than a read-then-write: two processes flushing the
     * same new fingerprint at once would both see it missing and both insert,
     * and the unique index would fail the second one. Ignoring the conflict
     * lets the count update below apply to whichever row won.
     *
     * @param  array<string, array<string, mixed>>  $summaries
     */
    private static function insertMissing(array $summaries): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $rows = [];

        foreach ($summaries as $fingerprint => $summary) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'fingerprint' => $fingerprint,
                'sample_message' => $summary['sample_message'],
                'level' => $summary['level'],
                'channel' => $summary['channel'],
                // Left at zero: the count update runs for new and existing
                // groups alike, so seeding it here would double-count.
                'occurrence_count' => 0,
                'first_seen_at' => $summary['first_seen_at'],
                'last_seen_at' => $summary['first_seen_at'],
                'status' => LogStatus::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        LogGroup::query()->insertOrIgnore($rows);
    }

    /**
     * Add each fingerprint's occurrences to its group and advance last_seen_at,
     * in one statement whatever the batch size.
     *
     * @param  array<string, array<string, mixed>>  $summaries
     */
    private static function bumpCounts(array $summaries): void
    {
        $connection = (new LogGroup)->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable((new LogGroup)->getTable());

        // SQLite spells the two-argument maximum max(); MySQL and Postgres
        // reserve max() for the aggregate and use greatest() for this.
        $greatest = $connection->getDriverName() === 'sqlite' ? 'max' : 'greatest';

        $countCase = '';
        $seenCase = '';
        $countBindings = [];
        $seenBindings = [];

        foreach ($summaries as $fingerprint => $summary) {
            $countCase .= ' when ? then ?';
            $countBindings[] = $fingerprint;
            $countBindings[] = $summary['count'];

            $seenCase .= ' when ? then ?';
            $seenBindings[] = $fingerprint;
            $seenBindings[] = $summary['last_seen_at'];
        }

        $fingerprints = array_keys($summaries);
        $placeholders = implode(', ', array_fill(0, count($fingerprints), '?'));

        // last_seen_at only ever moves forward. A queue worker running late
        // can flush entries older than ones already recorded, and without the
        // guard that group would jump backwards in a list ordered by recency.
        $sql = "update {$table} set "
            ."occurrence_count = occurrence_count + case fingerprint{$countCase} else 0 end, "
            ."last_seen_at = {$greatest}(last_seen_at, case fingerprint{$seenCase} else last_seen_at end), "
            .'updated_at = ? '
            ."where fingerprint in ({$placeholders})";

        $connection->update($sql, array_merge(
            $countBindings,
            $seenBindings,
            [now()->format('Y-m-d H:i:s')],
            $fingerprints,
        ));
    }

    /**
     * Reopen any of these groups that was Resolved, and mark when it came back.
     *
     * Only Resolved regresses. Ignored is the status that means "I know, stop
     * showing me this", so a new occurrence must not drag it back into view —
     * otherwise there is no way to permanently silence a known-noisy error.
     *
     * @param  array<int, string>  $fingerprints
     */
    private static function reopenRegressed(array $fingerprints): void
    {
        if (! config('logscope.grouping.regression', true)) {
            return;
        }

        LogGroup::query()
            ->whereIn('fingerprint', $fingerprints)
            ->where('status', LogStatus::Resolved->value)
            ->update([
                'status' => LogStatus::Open->value,
                'regressed_at' => now(),
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Normalise the many shapes occurred_at arrives in — prepareData() has
     * already turned it into a string, but createEntry() passes a Carbon and
     * a caller may pass neither.
     */
    private static function timestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return now()->format('Y-m-d H:i:s');
    }

    /**
     * Delete groups whose entries have all been pruned away.
     *
     * Groups do not outlive their entries: retention is one concept, and a
     * tombstone table that nothing ever cleans up would grow without bound on
     * a noisy application. One statement, run after a prune rather than per
     * deleted chunk.
     */
    public static function deleteOrphaned(): int
    {
        $entries = (new LogEntry)->getTable();

        return LogGroup::query()
            ->whereNotExists(function ($query) use ($entries) {
                $query->select(DB::raw(1))
                    ->from($entries)
                    ->whereColumn($entries.'.fingerprint', (new LogGroup)->getTable().'.fingerprint');
            })
            ->delete();
    }
}
