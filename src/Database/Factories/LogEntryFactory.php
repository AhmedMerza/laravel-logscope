<?php

declare(strict_types=1);

namespace LogScope\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogScope\Enums\LogStatus;
use LogScope\Models\LogEntry;

/**
 * @extends Factory<LogEntry>
 */
class LogEntryFactory extends Factory
{
    protected $model = LogEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $channels = ['stack', 'single', 'daily', 'slack', 'stderr'];
        $httpMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', null];
        $statuses = [LogStatus::Open, LogStatus::Investigating, LogStatus::Resolved, LogStatus::Ignored];

        $messages = [
            'debug' => [
                'Query executed in {time}ms',
                'Cache hit for key: {key}',
                'Session started for user',
                'Route matched: {route}',
            ],
            'info' => [
                'User {user} logged in successfully',
                'Order #{order_id} created',
                'Email sent to {email}',
                'Payment processed for ${amount}',
                'File uploaded: {filename}',
                'API request completed',
            ],
            'notice' => [
                'Configuration reloaded',
                'Cache cleared',
                'Scheduled task completed',
            ],
            'warning' => [
                'Slow query detected: {time}ms',
                'Rate limit approaching for IP {ip}',
                'Deprecated method used: {method}',
                'Low disk space warning',
                'Memory usage at {percent}%',
            ],
            'error' => [
                'Failed to connect to database',
                'API request failed: {error}',
                'File not found: {path}',
                'Invalid input received',
                'Authentication failed for user {user}',
                'Payment declined for order #{order_id}',
            ],
            'critical' => [
                'Database connection pool exhausted',
                'Redis connection lost',
                'Queue worker crashed',
            ],
            'alert' => [
                'System overload detected',
                'Security breach attempt',
                'Service unavailable',
            ],
            'emergency' => [
                'Application crashed',
                'Data corruption detected',
                'System failure',
            ],
        ];

        $level = $this->faker->randomElement($levels);
        $message = $this->faker->randomElement($messages[$level]);

        // Replace placeholders with fake data
        $message = str_replace(
            ['{time}', '{key}', '{route}', '{user}', '{order_id}', '{email}', '{amount}', '{filename}', '{ip}', '{method}', '{percent}', '{error}', '{path}'],
            [
                $this->faker->numberBetween(10, 5000),
                'cache:'.$this->faker->word(),
                $this->faker->randomElement(['/api/users', '/api/orders', '/dashboard', '/login']),
                $this->faker->userName(),
                $this->faker->numberBetween(1000, 9999),
                $this->faker->email(),
                $this->faker->numberBetween(10, 500),
                $this->faker->word().'.pdf',
                $this->faker->ipv4(),
                $this->faker->randomElement(['getUsers', 'processPayment', 'sendEmail']),
                $this->faker->numberBetween(70, 95),
                $this->faker->sentence(3),
                '/var/www/'.$this->faker->word().'.php',
            ],
            $message
        );

        $hasContext = $this->faker->boolean(70);
        $hasRequestContext = $this->faker->boolean(60);

        $status = $this->faker->randomElement($statuses);

        return [
            'level' => $level,
            'message' => $message,
            'message_preview' => Str::limit($message, 500),
            'context' => $hasContext ? $this->generateContext() : null,
            'channel' => $this->faker->randomElement($channels),
            'source' => $this->faker->boolean(80)
                ? '/app/'.$this->faker->randomElement(['Http/Controllers', 'Services', 'Jobs', 'Models']).'/'.$this->faker->word().'.php'
                : null,
            'source_line' => $this->faker->boolean(80) ? $this->faker->numberBetween(10, 500) : null,
            'trace_id' => $hasRequestContext ? Str::uuid()->toString() : null,
            'user_id' => $hasRequestContext && $this->faker->boolean(50) ? $this->faker->numberBetween(1, 100) : null,
            'ip_address' => $hasRequestContext ? $this->faker->ipv4() : null,
            'user_agent' => $hasRequestContext ? $this->faker->userAgent() : null,
            'http_method' => $hasRequestContext ? $this->faker->randomElement($httpMethods) : null,
            'url' => $hasRequestContext ? $this->faker->randomElement([
                '/api/users',
                '/api/orders/'.$this->faker->numberBetween(1, 100),
                '/dashboard',
                '/login',
                '/api/products',
                '/checkout',
            ]) : null,
            'status' => $status->value,
            'status_changed_at' => $status !== LogStatus::Open ? $this->faker->dateTimeBetween('-3 days', 'now') : null,
            'status_changed_by' => $status !== LogStatus::Open && $this->faker->boolean(80) ? $this->faker->name() : null,
            'occurred_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Generate random context data with varied JSON structures.
     */
    protected function generateContext(): array
    {
        $contexts = [
            // Simple key-value
            [
                'user_id' => $this->faker->numberBetween(1, 100),
                'action' => $this->faker->word(),
                'success' => $this->faker->boolean(),
            ],
            // Nested object
            [
                'order' => [
                    'id' => $this->faker->numberBetween(1000, 9999),
                    'total' => $this->faker->randomFloat(2, 10, 500),
                    'currency' => 'USD',
                    'items_count' => $this->faker->numberBetween(1, 10),
                ],
                'customer' => [
                    'id' => $this->faker->numberBetween(1, 100),
                    'email' => $this->faker->email(),
                    'is_guest' => $this->faker->boolean(),
                ],
            ],
            // With array
            [
                'query_time' => $this->faker->numberBetween(10, 1000),
                'query' => 'SELECT * FROM users WHERE id = ?',
                'bindings' => [$this->faker->numberBetween(1, 100)],
                'cached' => false,
            ],
            // Exception with stack trace
            [
                'exception' => [
                    'class' => $this->faker->randomElement(['RuntimeException', 'InvalidArgumentException', 'ValidationException', 'ModelNotFoundException']),
                    'message' => $this->faker->sentence(),
                    'code' => $this->faker->randomElement([0, 400, 404, 500, null]),
                    'file' => '/app/Services/'.$this->faker->word().'Service.php',
                    'line' => $this->faker->numberBetween(1, 200),
                ],
                'trace' => [
                    ['file' => '/app/Http/Controllers/'.$this->faker->word().'Controller.php', 'line' => $this->faker->numberBetween(10, 100), 'function' => $this->faker->word()],
                    ['file' => '/app/Services/'.$this->faker->word().'Service.php', 'line' => $this->faker->numberBetween(10, 100), 'function' => $this->faker->word()],
                    ['file' => '/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 54, 'function' => 'callAction'],
                ],
            ],
            // API response
            [
                'request' => [
                    'method' => $this->faker->randomElement(['GET', 'POST', 'PUT']),
                    'url' => 'https://api.example.com/'.$this->faker->word(),
                    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                ],
                'response' => [
                    'status' => $this->faker->randomElement([200, 201, 400, 401, 500]),
                    'body' => ['success' => $this->faker->boolean(), 'message' => $this->faker->sentence()],
                ],
                'duration_ms' => $this->faker->numberBetween(50, 2000),
            ],
            // Payment context
            [
                'payment' => [
                    'id' => 'pay_'.$this->faker->regexify('[A-Za-z0-9]{14}'),
                    'amount' => $this->faker->randomFloat(2, 10, 1000),
                    'currency' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
                    'status' => $this->faker->randomElement(['pending', 'completed', 'failed']),
                    'method' => $this->faker->randomElement(['card', 'paypal', 'bank_transfer']),
                ],
                'metadata' => [
                    'order_id' => $this->faker->numberBetween(1000, 9999),
                    'customer_email' => $this->faker->email(),
                    'ip_address' => $this->faker->ipv4(),
                ],
                'is_test' => true,
            ],
            // Validation errors
            [
                'validation_errors' => [
                    'email' => ['The email field is required.', 'The email must be a valid email address.'],
                    'password' => ['The password must be at least 8 characters.'],
                ],
                'input' => [
                    'email' => null,
                    'name' => $this->faker->name(),
                    'terms_accepted' => false,
                ],
            ],
            // Simple with nulls and booleans
            [
                'request_id' => Str::uuid()->toString(),
                'duration' => $this->faker->numberBetween(50, 2000),
                'cached' => $this->faker->boolean(),
                'error' => null,
                'retries' => 0,
            ],
            // Job context
            [
                'job' => [
                    'class' => 'App\\Jobs\\'.$this->faker->word().'Job',
                    'queue' => $this->faker->randomElement(['default', 'high', 'low', 'emails']),
                    'attempts' => $this->faker->numberBetween(1, 3),
                    'max_tries' => 3,
                ],
                'payload' => [
                    'user_id' => $this->faker->numberBetween(1, 100),
                    'data' => ['key' => $this->faker->word(), 'value' => $this->faker->sentence()],
                ],
                'completed' => $this->faker->boolean(),
                'execution_time' => $this->faker->randomFloat(2, 0.1, 30),
            ],
        ];

        return $this->faker->randomElement($contexts);
    }

    /**
     * Set a specific log level.
     */
    public function level(string $level): static
    {
        return $this->state(fn (array $attributes) => ['level' => $level]);
    }

    /**
     * Set as error level.
     */
    public function error(): static
    {
        return $this->level('error');
    }

    /**
     * Set as warning level.
     */
    public function warning(): static
    {
        return $this->level('warning');
    }

    /**
     * Set as info level.
     */
    public function info(): static
    {
        return $this->level('info');
    }

    /**
     * Set as debug level.
     */
    public function debug(): static
    {
        return $this->level('debug');
    }

    /**
     * Group logs under the same trace ID.
     */
    public function withTraceId(string $traceId): static
    {
        return $this->state(fn (array $attributes) => ['trace_id' => $traceId]);
    }

    /**
     * Set the user ID.
     */
    public function forUser(int $userId): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $userId]);
    }

    /**
     * Set occurred_at to today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'occurred_at' => $this->faker->dateTimeBetween('today', 'now'),
        ]);
    }

    /**
     * Set occurred_at to a specific time range.
     */
    public function between(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'occurred_at' => $this->faker->dateTimeBetween($from, $to),
        ]);
    }

    /**
     * Set status to open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LogStatus::Open->value,
            'status_changed_at' => null,
            'status_changed_by' => null,
        ]);
    }

    /**
     * Set status to investigating.
     */
    public function investigating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LogStatus::Investigating->value,
            'status_changed_at' => now(),
            'status_changed_by' => $this->faker->name(),
        ]);
    }

    /**
     * Set status to resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LogStatus::Resolved->value,
            'status_changed_at' => now(),
            'status_changed_by' => $this->faker->name(),
        ]);
    }

    /**
     * Set status to ignored.
     */
    public function ignored(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LogStatus::Ignored->value,
            'status_changed_at' => now(),
            'status_changed_by' => $this->faker->name(),
        ]);
    }
}
