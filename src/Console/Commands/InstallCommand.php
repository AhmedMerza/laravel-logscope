<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:install
                            {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install LogScope and publish its assets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing LogScope...');

        $this->callSilent('vendor:publish', [
            '--tag' => 'logscope-config',
            '--force' => $this->option('force'),
        ]);

        $this->callSilent('vendor:publish', [
            '--tag' => 'logscope-migrations',
            '--force' => $this->option('force'),
        ]);

        $this->components->info('LogScope installed successfully.');
        $this->components->info('Run [php artisan migrate] to create the required tables.');

        return self::SUCCESS;
    }
}
