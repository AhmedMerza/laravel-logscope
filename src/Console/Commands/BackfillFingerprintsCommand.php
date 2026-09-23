<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogScope\Enums\LogStatus;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;
use LogScope\Services\Fingerprint;
use LogScope\Services\GroupRecorder;

/**
 * Populate fingerprints on rows written before grouping existed, then build
 * the groups they belong to (#29).
 *
 * Resumable by construction: it normally looks only at rows whose fingerprint
 * is still null, so an interrupted run picks up where it stopped with no state
 * to keep. Entries are walked by id in chunks rather than loaded at once —
 * an existing table can be millions of rows.
 *
 * `--recompute` widens that to every row, for when the normalisation rules
 * themselves change and existing fingerprints have to be rebuilt under them.
 */
class BackfillFingerprintsCommand extends Command
{
    protected $signature = 'logscope:backfill-fingerprints
                            {--chunk=1000 : Number of entries to process per batch}
                            {--groups-only : Skip fingerprinting and only rebuild groups}
                            {--recompute : Re-fingerprint every entry, not only ones without a fingerprint}';

    protected $description = 'Fingerprint existing log entries and build their groups';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! $this->option('groups-only')) {
            $this->backfillFingerprints($chunkSize);
        }

        $this->rebuildGroups();

        // A recompute moves entries onto new fingerprints, so whatever groups
        // the old ones had are left holding nothing. Triage recorded against
        // a fingerprint that no longer exists cannot be carried across — the
        // rule change is what decided which entries are the same issue — so
        // say so rather than leaving empty rows behind quietly.
        if ($this->option('recompute')) {
            $orphaned = GroupRecorder::deleteOrphaned();

            if ($orphaned > 0) {
                $this->components->warn(
                    "Removed {$orphaned} groups left empty by the new fingerprints — any status or note on them is gone."
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Walk entries with no fingerprint and give them one.
     */
    protected function backfillFingerprints(int $chunkSize): void
    {
        $recompute = (bool) $this->option('recompute');

        $remaining = $this->pendingQuery()->count();

        if ($remaining === 0) {
            $this->components->info('All log entries already have a fingerprint.');

            return;
        }

        $this->components->info(
            $recompute
                ? "Re-fingerprinting {$remaining} log entries."
                : "Fingerprinting {$remaining} log entries."
        );

        $bar = $this->output->createProgressBar($remaining);
        $bar->start();

        $done = 0;

        // chunkById, not chunk: the where clause stops matching each row as
        // soon as it is updated, so offset paging would skip past unprocessed
        // rows as the result set shrinks under it.
        $this->pendingQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($entries) use ($bar, &$done) {
                $this->fingerprintChunk($entries);

                $done += $entries->count();
                $bar->advance($entries->count());
            });

        $bar->finish();
        $this->newLine(2);
        $this->components->info("Fingerprinted {$done} log entries.");
    }

    /**
     * The entries still to be fingerprinted.
     *
     * Normally only rows that have none, which is what makes an interrupted
     * run resumable with no state to keep. With --recompute it is every row:
     * the normalisation rules are effectively a data format, and changing
     * them re-groups everything, so there has to be a way to apply a rule
     * change to rows that were fingerprinted under the old one (#29).
     */
    protected function pendingQuery(): Builder
    {
        $query = LogEntry::query();

        return $this->option('recompute') ? $query : $query->whereNull('fingerprint');
    }

    /**
     * Fingerprint one chunk, writing one statement per distinct fingerprint
     * rather than one per row — a chunk of a thousand repetitions of the same
     * error costs a single update.
     */
    protected function fingerprintChunk(iterable $entries): void
    {
        $byFingerprint = [];

        foreach ($entries as $entry) {
            $fingerprint = Fingerprint::for([
                'message' => (string) $entry->message,
                'level' => (string) $entry->level,
                'channel' => $entry->channel,
                'context' => $this->decodeContext($entry->getRawOriginal('context')),
            ]);

            $byFingerprint[$fingerprint][] = $entry->id;
        }

        foreach ($byFingerprint as $fingerprint => $ids) {
            LogEntry::query()->whereIn('id', $ids)->update(['fingerprint' => $fingerprint]);
        }
    }

    /**
     * Context is stored as JSON. Fingerprint needs the array to find an
     * exception in it, and would otherwise silently fall back to the message
     * — giving backfilled rows a different fingerprint from ones written
     * live, and splitting the same error into two groups.
     */
    protected function decodeContext(mixed $raw): mixed
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return json_decode($raw, true) ?: null;
    }

    /**
     * Build or refresh a group row for every fingerprint present in the
     * entries table.
     *
     * Counts are recomputed from the entries rather than incremented, so
     * running this after a drift — a failed group write, an import — makes
     * the two tables agree again.
     */
    protected function rebuildGroups(): void
    {
        $aggregates = LogEntry::query()
            ->whereNotNull('fingerprint')
            ->selectRaw('fingerprint, count(*) as occurrences, min(occurred_at) as first_seen, max(occurred_at) as last_seen')
            ->groupBy('fingerprint')
            ->get();

        if ($aggregates->isEmpty()) {
            $this->components->info('No fingerprinted entries to group.');

            return;
        }

        $this->components->info("Building {$aggregates->count()} groups.");

        $bar = $this->output->createProgressBar($aggregates->count());
        $bar->start();

        foreach ($aggregates as $aggregate) {
            $this->upsertGroup($aggregate);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->components->info("Built {$aggregates->count()} groups.");
    }

    /**
     * One group, from the entries that belong to it.
     *
     * Two extra reads per group rather than one clever query: "the newest row
     * per fingerprint" needs a window function, and this package supports
     * MySQL versions that predate them. The number of groups is small by
     * definition — that is what grouping is for — and this runs once.
     */
    protected function upsertGroup(object $aggregate): void
    {
        $latest = LogEntry::query()
            ->where('fingerprint', $aggregate->fingerprint)
            ->orderByDesc('occurred_at')
            ->first();

        if (! $latest) {
            return;
        }

        $existing = LogGroup::query()->where('fingerprint', $aggregate->fingerprint)->first();

        LogGroup::query()->updateOrInsert(
            ['fingerprint' => $aggregate->fingerprint],
            [
                'id' => $existing?->id ?? strtolower((string) Str::ulid()),
                'sample_message' => (string) ($latest->message_preview ?? $latest->message),
                'level' => (string) $latest->level,
                'channel' => $latest->channel,
                'occurrence_count' => (int) $aggregate->occurrences,
                'first_seen_at' => $aggregate->first_seen,
                'last_seen_at' => $aggregate->last_seen,
                // An existing group's triage is authoritative — it was set on
                // the group and must not be overwritten by rolling entries up
                // a second time. Only a group being created for the first time
                // inherits status from the entries it is built from.
                'status' => $existing?->status?->value ?? $this->inheritedStatus($aggregate->fingerprint),
                'status_changed_at' => $existing?->status_changed_at,
                'status_changed_by' => $existing?->status_changed_by,
                'note' => $existing?->note,
                'regressed_at' => $existing?->regressed_at,
                'updated_at' => now(),
                'created_at' => $existing?->created_at ?? now(),
            ]
        );
    }

    /**
     * The status a brand-new group inherits from the entries it is built from.
     *
     * Entries were triaged one row at a time before grouping existed, so a
     * group's entries can disagree. The most recently changed one wins: it is
     * the last decision anybody actually made about this error. Notes are left
     * on the entries — merging free text from many rows would invent a note
     * nobody wrote.
     */
    protected function inheritedStatus(string $fingerprint): string
    {
        $triaged = LogEntry::query()
            ->where('fingerprint', $fingerprint)
            ->whereNotNull('status_changed_at')
            ->orderByDesc('status_changed_at')
            ->first();

        return $triaged?->status?->value ?? LogStatus::Open->value;
    }
}
