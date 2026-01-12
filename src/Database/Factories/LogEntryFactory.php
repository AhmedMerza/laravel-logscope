<?php

declare(strict_types=1);

namespace LogScope\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
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
        $channels = ['stack', 'single', 'daily', 'slack', 'stderr', null];
        $environments = ['local', 'staging', 'production'];
        $httpMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', null];

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
                'cache:' . $this->faker->word(),
                $this->faker->randomElement(['/api/users', '/api/orders', '/dashboard', '/login']),
                $this->faker->userName(),
                $this->faker->numberBetween(1000, 9999),
                $this->faker->email(),
                $this->faker->numberBetween(10, 500),
                $this->faker->word() . '.pdf',
                $this->faker->ipv4(),
                $this->faker->randomElement(['getUsers', 'processPayment', 'sendEmail']),
                $this->faker->numberBetween(70, 95),
                $this->faker->sentence(3),
                '/var/www/' . $this->faker->word() . '.php',
            ],
            $message
        );

        $hasContext = $this->faker->boolean(70);
        $hasRequestContext = $this->faker->boolean(60);

        return [
            'level' => $level,
            'message' => $message,
            'message_preview' => Str::limit($message, 500),
            'context' => $hasContext ? $this->generateContext() : null,
            'channel' => $this->faker->randomElement($channels),
            'environment' => $this->faker->randomElement($environments),
            'source' => $this->faker->boolean(80)
                ? '/app/' . $this->faker->randomElement(['Http/Controllers', 'Services', 'Jobs', 'Models']) . '/' . $this->faker->word() . '.php'
                : null,
            'source_line' => $this->faker->boolean(80) ? $this->faker->numberBetween(10, 500) : null,
            'trace_id' => $hasRequestContext ? Str::uuid()->toString() : null,
            'user_id' => $hasRequestContext && $this->faker->boolean(50) ? $this->faker->numberBetween(1, 100) : null,
            'ip_address' => $hasRequestContext ? $this->faker->ipv4() : null,
            'user_agent' => $hasRequestContext ? $this->faker->userAgent() : null,
            'http_method' => $hasRequestContext ? $this->faker->randomElement($httpMethods) : null,
            'url' => $hasRequestContext ? $this->faker->randomElement([
                '/api/users',
                '/api/orders/' . $this->faker->numberBetween(1, 100),
                '/dashboard',
                '/login',
                '/api/products',
                '/checkout',
            ]) : null,
            'http_status' => $hasRequestContext && $this->faker->boolean(40)
                ? $this->faker->randomElement([200, 201, 204, 301, 400, 401, 403, 404, 500, 502, 503])
                : null,
            'occurred_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Generate random context data.
     */
    protected function generateContext(): array
    {
        $contexts = [
            ['user_id' => $this->faker->numberBetween(1, 100), 'action' => $this->faker->word()],
            ['order_id' => $this->faker->numberBetween(1000, 9999), 'total' => $this->faker->randomFloat(2, 10, 500)],
            ['query_time' => $this->faker->numberBetween(10, 1000), 'query' => 'SELECT * FROM users WHERE id = ?'],
            ['exception' => 'RuntimeException', 'file' => '/app/Services/PaymentService.php', 'line' => $this->faker->numberBetween(1, 200)],
            ['request_id' => Str::uuid()->toString(), 'duration' => $this->faker->numberBetween(50, 2000)],
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
}
