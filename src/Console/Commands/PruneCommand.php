<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use LogScope\Models\LogEntry;
use LogScope\Services\GroupRecorder;

class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:prune
                            {--days= : Number of days to retain logs (overrides config)}
                            {--dry-run : Show how many records would be deleted}
                            {--chunk=1000 : Number of records to delete per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old log entries based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $configEnabled = config('logscope.retention.enabled', true);
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        // Check if retention is disabled and no manual days override
        if (! $configEnabled && ! $this->option('days')) {
            $this->components->warn('Log retention is disabled in configuration.');
            $this->components->info('Use --days=N to force pruning regardless of configuration.');

            return self::SUCCESS;
        }

        // --days is one window for every level, overriding the per-level
        // policy as well as retention.days.
        $policy = $this->option('days')
            ? ['*' => (int) $this->option('days')]
            : LogEntry::retentionPolicy();

        $query = LogEntry::pastRetention($policy);

        $window = count($policy) === 1
            ? "older than {$policy['*']} days"
            : 'past their retention window';

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->components->info("No log entries {$window} found.");

            return self::SUCCESS;
        }

        $this->components->info("Found {$count} log entries {$window}.");

        if ($dryRun) {
            $this->components->warn('Dry run - no records will be deleted.');
            $this->showBreakdown($query, $policy);

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete {$count} log entries?", true)) {
            $this->components->info('Pruning cancelled.');

            return self::SUCCESS;
        }

        // Delete in chunks, by id, so the statements stay short and don't
        // wait on rows another session has open (#46).
        $deleted = 0;
        $this->components->task('Pruning old log entries', function () use ($query, $chunkSize, &$deleted) {
            $deleted = LogEntry::deleteInChunks($query, $chunkSize);

            return true;
        });

        $this->newLine();
        $this->components->info("Deleted {$deleted} log entries.");

        // Groups do not outlive their entries (#29). Run once after the
        // chunked delete rather than per chunk: a group is only orphaned once
        // its last entry is gone, so checking earlier would find nothing and
        // cost a scan per chunk.
        $orphaned = GroupRecorder::deleteOrphaned();

        if ($orphaned > 0) {
            $this->components->info("Deleted {$orphaned} empty log groups.");
        }

        return self::SUCCESS;
    }

    /**
     * Show breakdown of entries to be deleted by level, with the window
     * each level is kept for.
     *
     * @param  array<string, int>  $policy
     */
    protected function showBreakdown(Builder $query, array $policy): void
    {
        $breakdown = (clone $query)
            ->selectRaw('level, count(*) as count')
            ->groupBy('level')
            ->orderByDesc('count')
            ->get();

        if ($breakdown->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Breakdown by level:');

        $rows = $breakdown->map(fn ($row) => [
            'Level' => strtoupper($row->level),
            'Kept for' => ($policy[$row->level] ?? $policy['*']).' days',
            'Count' => number_format($row->count),
        ])->toArray();

        $this->table(['Level', 'Kept for', 'Count'], $rows);
    }
}
