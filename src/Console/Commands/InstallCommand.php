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

        // Publish config
        $configPath = config_path('logscope.php');
        if (file_exists($configPath) && ! $this->option('force')) {
            $this->components->warn('Config already exists: config/logscope.php (use --force to overwrite)');
        } else {
            $this->callSilent('vendor:publish', [
                '--tag' => 'logscope-config',
                '--force' => $this->option('force'),
            ]);
            $this->components->task('Publishing config');
        }

        // Publish migrations
        $migrationExists = $this->migrationExists();
        if ($migrationExists && ! $this->option('force')) {
            $this->components->warn('Migration already exists: '.$migrationExists.' (use --force to overwrite)');
        } else {
            $this->callSilent('vendor:publish', [
                '--tag' => 'logscope-migrations',
                '--force' => $this->option('force'),
            ]);
            $this->components->task('Publishing migrations');
        }

        $this->newLine();
        $this->components->info('LogScope installed successfully.');
        $this->components->info('Run [php artisan migrate] to create the required tables.');

        return self::SUCCESS;
    }

    /**
     * Check if the LogScope migration already exists.
     */
    protected function migrationExists(): ?string
    {
        $files = glob(database_path('migrations/*_create_log_entries_table.php'));

        return $files ? basename($files[0]) : null;
    }
}
