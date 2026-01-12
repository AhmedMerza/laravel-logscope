<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:import
                            {path? : Path to the log file to import}
                            {--days=7 : Only import logs from the last N days}
                            {--fresh : Clear existing logs before importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import logs from Laravel log files into the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Importing logs...');

        // TODO: Implement log file parsing and import logic

        $this->components->info('Log import completed.');

        return self::SUCCESS;
    }
}
