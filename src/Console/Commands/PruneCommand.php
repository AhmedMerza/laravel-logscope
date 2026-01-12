<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use LogScope\Models\LogEntry;

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
        $configDays = config('logscope.retention.days', 30);

        $days = $this->option('days') ? (int) $this->option('days') : $configDays;
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        // Check if retention is disabled and no manual days override
        if (! $configEnabled && ! $this->option('days')) {
            $this->components->warn('Log retention is disabled in configuration.');
            $this->components->info('Use --days=N to force pruning regardless of configuration.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // Count records to be deleted
        $count = LogEntry::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();

        if ($count === 0) {
            $this->components->info("No log entries older than {$days} days found.");

            return self::SUCCESS;
        }

        $this->components->info("Found {$count} log entries older than {$days} days.");

        if ($dryRun) {
            $this->components->warn('Dry run - no records will be deleted.');
            $this->showBreakdown($cutoff);

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete {$count} log entries?", true)) {
            $this->components->info('Pruning cancelled.');

            return self::SUCCESS;
        }

        // Delete in chunks to avoid memory issues with large datasets
        $deleted = 0;
        $this->components->task('Pruning old log entries', function () use ($cutoff, $chunkSize, &$deleted) {
            do {
                $batch = LogEntry::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->limit($chunkSize)
                    ->delete();

                $deleted += $batch;
            } while ($batch > 0);

            return true;
        });

        $this->newLine();
        $this->components->info("Deleted {$deleted} log entries.");

        return self::SUCCESS;
    }

    /**
     * Show breakdown of entries to be deleted by level.
     */
    protected function showBreakdown(\DateTimeInterface $cutoff): void
    {
        $breakdown = LogEntry::query()
            ->where('occurred_at', '<', $cutoff)
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
            'Count' => number_format($row->count),
        ])->toArray();

        $this->table(['Level', 'Count'], $rows);
    }
}
