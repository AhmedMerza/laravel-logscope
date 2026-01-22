<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:seed
                            {count=50 : Number of log entries to generate}
                            {--level= : Only generate logs of this level}
                            {--realistic : Use realistic log calls through Laravel\'s logger}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the database with sample log entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = (int) $this->argument('count');
        $level = $this->option('level');
        $realistic = $this->option('realistic');

        if ($realistic) {
            return $this->seedRealistic($count, $level);
        }

        return $this->seedFactory($count, $level);
    }

    /**
     * Seed using factory (direct database insert).
     */
    protected function seedFactory(int $count, ?string $level): int
    {
        $this->info("Generating {$count} log entries using factory...");

        $factory = \LogScope\Models\LogEntry::factory();

        if ($level) {
            $factory = $factory->level($level);
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Create in chunks for better performance
        $chunkSize = min(100, $count);
        $remaining = $count;

        while ($remaining > 0) {
            $toCreate = min($chunkSize, $remaining);
            $factory->count($toCreate)->create();
            $remaining -= $toCreate;
            $bar->advance($toCreate);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done! Created {$count} log entries.");

        return self::SUCCESS;
    }

    /**
     * Seed using realistic log calls.
     */
    protected function seedRealistic(int $count, ?string $level): int
    {
        $this->info("Generating {$count} log entries using real log calls...");
        $this->info('Note: These will go through your configured logging channels.');

        $scenarios = $this->getScenarios();

        if ($level) {
            $scenarios = array_filter($scenarios, fn ($s) => $s['level'] === $level);
            if (empty($scenarios)) {
                $this->error("No scenarios found for level: {$level}");

                return self::FAILURE;
            }
            $scenarios = array_values($scenarios);
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[array_rand($scenarios)];
            $this->executeScenario($scenario);
            $bar->advance();

            // Small delay to spread out timestamps
            if ($i % 10 === 0) {
                usleep(10000); // 10ms
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done! Generated {$count} log entries through Laravel's logger.");

        return self::SUCCESS;
    }

    /**
     * Execute a logging scenario.
     */
    protected function executeScenario(array $scenario): void
    {
        $level = $scenario['level'];
        $message = $this->replacePlaceholders($scenario['message']);
        $context = $this->processContext($scenario['context'] ?? []);

        Log::{$level}($message, $context);
    }

    /**
     * Replace placeholders in messages.
     */
    protected function replacePlaceholders(string $message): string
    {
        $replacements = [
            '{user_id}' => rand(1, 100),
            '{username}' => 'user_'.rand(100, 999),
            '{email}' => 'user'.rand(1, 100).'@example.com',
            '{order_id}' => rand(10000, 99999),
            '{amount}' => number_format(rand(10, 500) + rand(0, 99) / 100, 2),
            '{time}' => rand(10, 5000),
            '{ip}' => rand(1, 255).'.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(1, 255),
            '{path}' => '/app/'.['Controllers', 'Services', 'Models', 'Jobs'][rand(0, 3)].'/File.php',
            '{percent}' => rand(70, 95),
            '{endpoint}' => ['/api/users', '/api/orders', '/api/products', '/dashboard'][rand(0, 3)],
            '{status}' => [200, 201, 400, 401, 404, 500][rand(0, 5)],
            '{product_id}' => rand(1, 500),
            '{filename}' => 'file_'.rand(1000, 9999).'.pdf',
            '{queue}' => ['default', 'high', 'low', 'emails'][rand(0, 3)],
            '{job}' => ['SendEmail', 'ProcessPayment', 'GenerateReport', 'SyncData'][rand(0, 3)],
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    /**
     * Process context array.
     */
    protected function processContext(array $context): array
    {
        $processed = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $processed[$key] = $this->replacePlaceholders($value);
            } elseif ($value === '{random_id}') {
                $processed[$key] = rand(1, 1000);
            } elseif ($value === '{random_float}') {
                $processed[$key] = rand(10, 500) + rand(0, 99) / 100;
            } else {
                $processed[$key] = $value;
            }
        }

        return $processed;
    }

    /**
     * Get logging scenarios.
     */
    protected function getScenarios(): array
    {
        return [
            ['level' => 'debug', 'message' => 'Query executed in {time}ms', 'context' => ['query' => 'SELECT * FROM users']],
            ['level' => 'debug', 'message' => 'Cache hit for key: user:{user_id}:profile'],
            ['level' => 'info', 'message' => 'User {username} logged in successfully', 'context' => ['user_id' => '{random_id}']],
            ['level' => 'info', 'message' => 'Order #{order_id} created successfully', 'context' => ['total' => '{random_float}']],
            ['level' => 'info', 'message' => 'Email sent to {email}', 'context' => ['template' => 'welcome']],
            ['level' => 'info', 'message' => 'Payment of ${amount} processed for order #{order_id}'],
            ['level' => 'info', 'message' => 'File {filename} uploaded successfully'],
            ['level' => 'info', 'message' => 'API request to {endpoint} completed', 'context' => ['status' => '{status}']],
            ['level' => 'notice', 'message' => 'Configuration cache cleared'],
            ['level' => 'notice', 'message' => 'Scheduled task {job} completed', 'context' => ['duration' => '{time}']],
            ['level' => 'warning', 'message' => 'Slow query detected: {time}ms'],
            ['level' => 'warning', 'message' => 'Rate limit approaching for IP {ip}', 'context' => ['current' => rand(80, 95)]],
            ['level' => 'warning', 'message' => 'Memory usage at {percent}%'],
            ['level' => 'error', 'message' => 'Failed to connect to external API', 'context' => ['error' => 'Connection timeout']],
            ['level' => 'error', 'message' => 'Payment declined for order #{order_id}', 'context' => ['reason' => 'insufficient_funds']],
            ['level' => 'error', 'message' => 'Authentication failed for user {username}', 'context' => ['ip' => '{ip}']],
            ['level' => 'error', 'message' => 'Validation failed for {endpoint}'],
            ['level' => 'critical', 'message' => 'Database connection pool exhausted'],
            ['level' => 'critical', 'message' => 'Redis connection lost'],
            ['level' => 'alert', 'message' => 'Multiple failed login attempts detected', 'context' => ['ip' => '{ip}']],
            ['level' => 'emergency', 'message' => 'Application encountered fatal error'],
        ];
    }
}
