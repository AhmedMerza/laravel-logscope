<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;

class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:prune
                            {--days= : Number of days to retain logs (overrides config)}
                            {--dry-run : Show how many records would be deleted}';

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
        $days = $this->option('days') ?? config('logscope.retention.days', 30);
        $dryRun = $this->option('dry-run');

        if (! config('logscope.retention.enabled', true) && ! $this->option('days')) {
            $this->components->warn('Log retention is disabled. Use --days to force pruning.');

            return self::SUCCESS;
        }

        $this->components->info("Pruning logs older than {$days} days...");

        // TODO: Implement pruning logic

        if ($dryRun) {
            $this->components->info('Dry run completed. No records were deleted.');
        } else {
            $this->components->info('Pruning completed.');
        }

        return self::SUCCESS;
    }
}
