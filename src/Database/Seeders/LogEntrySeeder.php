<?php

declare(strict_types=1);

namespace LogScope\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class LogEntrySeeder extends Seeder
{
    /**
     * Seed the application's log entries using real log calls.
     */
    public function run(int $count = 50): void
    {
        $this->command?->info("Generating {$count} log entries...");

        $scenarios = $this->getScenarios();

        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[array_rand($scenarios)];
            $this->executeScenario($scenario);

            if ($this->command && $i % 10 === 0) {
                $this->command->info("Generated {$i} entries...");
            }
        }

        $this->command?->info("Done! Generated {$count} log entries.");
    }

    /**
     * Execute a logging scenario.
     */
    protected function executeScenario(array $scenario): void
    {
        $level = $scenario['level'];
        $message = $scenario['message'];
        $context = $scenario['context'] ?? [];

        // Replace placeholders with random data
        $message = $this->replacePlaceholders($message);
        $context = $this->processContext($context);

        Log::{$level}($message, $context);
    }

    /**
     * Replace placeholders in messages with fake data.
     */
    protected function replacePlaceholders(string $message): string
    {
        $replacements = [
            '{user_id}' => rand(1, 100),
            '{username}' => 'user_' . rand(100, 999),
            '{email}' => 'user' . rand(1, 100) . '@example.com',
            '{order_id}' => rand(10000, 99999),
            '{amount}' => number_format(rand(10, 500) + rand(0, 99) / 100, 2),
            '{time}' => rand(10, 5000),
            '{ip}' => rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 255),
            '{path}' => '/app/' . ['Controllers', 'Services', 'Models', 'Jobs'][rand(0, 3)] . '/File.php',
            '{percent}' => rand(70, 95),
            '{method}' => ['GET', 'POST', 'PUT', 'DELETE'][rand(0, 3)],
            '{endpoint}' => ['/api/users', '/api/orders', '/api/products', '/dashboard'][rand(0, 3)],
            '{status}' => [200, 201, 400, 401, 404, 500][rand(0, 5)],
            '{product_id}' => rand(1, 500),
            '{filename}' => 'file_' . rand(1000, 9999) . '.pdf',
            '{queue}' => ['default', 'high', 'low', 'emails'][rand(0, 3)],
            '{job}' => ['SendEmail', 'ProcessPayment', 'GenerateReport', 'SyncData'][rand(0, 3)],
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    /**
     * Process context array and replace placeholders.
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
     * Get available logging scenarios.
     */
    protected function getScenarios(): array
    {
        return [
            // Debug scenarios
            [
                'level' => 'debug',
                'message' => 'Query executed in {time}ms',
                'context' => ['query' => 'SELECT * FROM users WHERE id = ?', 'bindings' => ['{random_id}']],
            ],
            [
                'level' => 'debug',
                'message' => 'Cache hit for key: user:{user_id}:profile',
            ],
            [
                'level' => 'debug',
                'message' => 'Route matched: {endpoint}',
                'context' => ['method' => '{method}'],
            ],

            // Info scenarios
            [
                'level' => 'info',
                'message' => 'User {username} logged in successfully',
                'context' => ['user_id' => '{random_id}', 'ip' => '{ip}'],
            ],
            [
                'level' => 'info',
                'message' => 'Order #{order_id} created successfully',
                'context' => ['user_id' => '{random_id}', 'total' => '{random_float}'],
            ],
            [
                'level' => 'info',
                'message' => 'Email sent to {email}',
                'context' => ['template' => 'welcome', 'queue' => '{queue}'],
            ],
            [
                'level' => 'info',
                'message' => 'Payment of ${amount} processed for order #{order_id}',
                'context' => ['payment_method' => 'credit_card', 'status' => 'completed'],
            ],
            [
                'level' => 'info',
                'message' => 'File {filename} uploaded successfully',
                'context' => ['size' => rand(1000, 5000000), 'mime' => 'application/pdf'],
            ],
            [
                'level' => 'info',
                'message' => 'API request to {endpoint} completed',
                'context' => ['status' => '{status}', 'duration' => '{time}'],
            ],
            [
                'level' => 'info',
                'message' => 'User {username} updated their profile',
                'context' => ['fields' => ['name', 'email', 'avatar']],
            ],
            [
                'level' => 'info',
                'message' => 'Product #{product_id} added to cart',
                'context' => ['quantity' => rand(1, 5), 'user_id' => '{random_id}'],
            ],

            // Notice scenarios
            [
                'level' => 'notice',
                'message' => 'Configuration cache cleared',
            ],
            [
                'level' => 'notice',
                'message' => 'Scheduled task {job} completed',
                'context' => ['duration' => '{time}', 'memory' => rand(10, 128) . 'MB'],
            ],
            [
                'level' => 'notice',
                'message' => 'New user registration from {ip}',
                'context' => ['user_id' => '{random_id}'],
            ],

            // Warning scenarios
            [
                'level' => 'warning',
                'message' => 'Slow query detected: {time}ms',
                'context' => ['query' => 'SELECT * FROM orders WHERE created_at > ?', 'threshold' => 1000],
            ],
            [
                'level' => 'warning',
                'message' => 'Rate limit approaching for IP {ip}',
                'context' => ['current' => rand(80, 95), 'limit' => 100],
            ],
            [
                'level' => 'warning',
                'message' => 'Deprecated method used: oldPaymentProcess()',
                'context' => ['file' => '{path}', 'line' => rand(10, 200)],
            ],
            [
                'level' => 'warning',
                'message' => 'Memory usage at {percent}%',
                'context' => ['used' => rand(500, 900) . 'MB', 'total' => '1024MB'],
            ],
            [
                'level' => 'warning',
                'message' => 'Retry attempt {attempt} for job {job}',
                'context' => ['queue' => '{queue}', 'max_tries' => 3],
            ],

            // Error scenarios
            [
                'level' => 'error',
                'message' => 'Failed to connect to external API',
                'context' => ['endpoint' => 'https://api.example.com/v1/data', 'error' => 'Connection timeout'],
            ],
            [
                'level' => 'error',
                'message' => 'Payment declined for order #{order_id}',
                'context' => ['reason' => 'insufficient_funds', 'user_id' => '{random_id}'],
            ],
            [
                'level' => 'error',
                'message' => 'File not found: {path}',
                'context' => ['requested_by' => 'user_{user_id}'],
            ],
            [
                'level' => 'error',
                'message' => 'Authentication failed for user {username}',
                'context' => ['ip' => '{ip}', 'attempts' => rand(3, 5)],
            ],
            [
                'level' => 'error',
                'message' => 'Validation failed for {endpoint}',
                'context' => ['errors' => ['email' => 'Invalid email format', 'password' => 'Too short']],
            ],
            [
                'level' => 'error',
                'message' => 'Job {job} failed after {attempt} attempts',
                'context' => ['exception' => 'RuntimeException', 'queue' => '{queue}'],
            ],

            // Critical scenarios
            [
                'level' => 'critical',
                'message' => 'Database connection pool exhausted',
                'context' => ['active_connections' => rand(90, 100), 'max_connections' => 100],
            ],
            [
                'level' => 'critical',
                'message' => 'Redis connection lost',
                'context' => ['host' => 'redis.example.com', 'port' => 6379],
            ],
            [
                'level' => 'critical',
                'message' => 'Queue worker crashed unexpectedly',
                'context' => ['worker_id' => rand(1, 5), 'queue' => '{queue}'],
            ],

            // Alert scenarios
            [
                'level' => 'alert',
                'message' => 'Multiple failed login attempts detected',
                'context' => ['ip' => '{ip}', 'attempts' => rand(10, 50), 'timeframe' => '5 minutes'],
            ],
            [
                'level' => 'alert',
                'message' => 'Disk space critically low',
                'context' => ['available' => rand(1, 5) . 'GB', 'total' => '100GB'],
            ],

            // Emergency scenarios
            [
                'level' => 'emergency',
                'message' => 'Application encountered fatal error',
                'context' => ['exception' => 'FatalErrorException', 'file' => '{path}'],
            ],
        ];
    }
}
