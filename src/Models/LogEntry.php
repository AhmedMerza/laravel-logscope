<?php

declare(strict_types=1);

namespace LogScope\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use LogScope\Database\Factories\LogEntryFactory;

class LogEntry extends Model
{
    use HasFactory;
    use HasUlids;
    use Prunable;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): LogEntryFactory
    {
        return LogEntryFactory::new();
    }

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'resolved_at' => 'datetime',
            'is_truncated' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return config('logscope.table', 'log_entries');
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        if (! config('logscope.retention.enabled', true)) {
            return static::query()->whereRaw('1 = 0');
        }

        $days = config('logscope.retention.days', 30);

        return static::query()->where('occurred_at', '<', now()->subDays($days));
    }

    /**
     * Scope: Filter by log level.
     */
    public function scopeLevel(Builder $query, string|array $level): Builder
    {
        if (is_array($level)) {
            return $query->whereIn('level', $level);
        }

        return $query->where('level', $level);
    }

    /**
     * Scope: Exclude specific log levels.
     */
    public function scopeExcludeLevel(Builder $query, string|array $level): Builder
    {
        if (is_array($level)) {
            return $query->whereNotIn('level', $level);
        }

        return $query->where('level', '!=', $level);
    }

    /**
     * Scope: Filter by channel.
     */
    public function scopeChannel(Builder $query, string|array $channel): Builder
    {
        if (is_array($channel)) {
            return $query->whereIn('channel', $channel);
        }

        return $query->where('channel', $channel);
    }

    /**
     * Scope: Exclude specific channels.
     */
    public function scopeExcludeChannel(Builder $query, string|array $channel): Builder
    {
        return $query->where(function ($q) use ($channel) {
            if (is_array($channel)) {
                $q->whereNotIn('channel', $channel);
            } else {
                $q->where('channel', '!=', $channel);
            }
            $q->orWhereNull('channel');
        });
    }

    /**
     * Scope: Filter by environment.
     */
    public function scopeEnvironment(Builder $query, string|array $environment): Builder
    {
        if (is_array($environment)) {
            return $query->whereIn('environment', $environment);
        }

        return $query->where('environment', $environment);
    }

    /**
     * Scope: Filter by date range.
     */
    public function scopeDateRange(Builder $query, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Scope: Search in message.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('message', 'like', "%{$term}%")
                ->orWhere('message_preview', 'like', "%{$term}%");
        });
    }

    /**
     * Scope: Filter by trace ID (group logs from same request).
     */
    public function scopeTraceId(Builder $query, string $traceId): Builder
    {
        return $query->where('trace_id', $traceId);
    }

    /**
     * Scope: Filter by user ID.
     */
    public function scopeUserId(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Filter by IP address.
     */
    public function scopeIpAddress(Builder $query, string $ipAddress): Builder
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope: Filter by HTTP method.
     */
    public function scopeHttpMethod(Builder $query, string|array $method): Builder
    {
        if (is_array($method)) {
            return $query->whereIn('http_method', $method);
        }

        return $query->where('http_method', $method);
    }

    /**
     * Scope: Exclude specific HTTP methods.
     */
    public function scopeExcludeHttpMethod(Builder $query, string|array $method): Builder
    {
        return $query->where(function ($q) use ($method) {
            if (is_array($method)) {
                $q->whereNotIn('http_method', $method);
            } else {
                $q->where('http_method', '!=', $method);
            }
            $q->orWhereNull('http_method');
        });
    }

    /**
     * Scope: Filter by URL (partial match).
     */
    public function scopeUrl(Builder $query, string $url): Builder
    {
        return $query->where('url', 'like', "%{$url}%");
    }

    /**
     * Scope: Only truncated entries.
     */
    public function scopeTruncated(Builder $query): Builder
    {
        return $query->where('is_truncated', true);
    }

    /**
     * Scope: Recent entries first.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Scope: Only resolved entries.
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope: Only unresolved entries.
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope: Filter by resolved status.
     */
    public function scopeResolvedStatus(Builder $query, ?bool $resolved): Builder
    {
        if ($resolved === null) {
            return $query;
        }

        return $resolved ? $query->resolved() : $query->unresolved();
    }

    /**
     * Check if the entry is resolved.
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Mark the entry as resolved.
     */
    public function resolve(?string $resolvedBy = null, ?string $note = null): bool
    {
        $data = ['resolved_at' => now()];

        if ($resolvedBy !== null) {
            $data['resolved_by'] = $resolvedBy;
        }

        if ($note !== null) {
            $data['note'] = $note;
        }

        return $this->update($data);
    }

    /**
     * Mark the entry as unresolved.
     */
    public function unresolve(): bool
    {
        return $this->update([
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
    }

    /**
     * Create a preview from full content.
     */
    public static function createPreview(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength - 3).'...';
    }

    /**
     * Create a new log entry with automatic preview generation.
     */
    public static function createEntry(array $attributes): static
    {
        $limits = config('logscope.limits', []);

        // Generate message preview
        if (isset($attributes['message'])) {
            $maxPreview = $limits['message_preview_length'] ?? 500;
            $maxInline = $limits['message_inline_max'] ?? 16000;
            $truncateAt = $limits['truncate_at'] ?? 1000000;

            $attributes['message_preview'] = static::createPreview($attributes['message'], $maxPreview);

            // Truncate if too large
            if (strlen($attributes['message']) > $truncateAt) {
                $attributes['message'] = substr($attributes['message'], 0, $truncateAt);
                $attributes['is_truncated'] = true;
            } elseif (strlen($attributes['message']) > $maxInline) {
                $attributes['is_truncated'] = true;
            }
        }

        // Generate context preview
        if (isset($attributes['context']) && is_array($attributes['context'])) {
            $contextJson = json_encode($attributes['context']);
            $maxPreview = $limits['context_preview_length'] ?? 500;
            $maxInline = $limits['context_inline_max'] ?? 32000;
            $truncateAt = $limits['truncate_at'] ?? 1000000;

            $attributes['context_preview'] = static::createPreview($contextJson, $maxPreview);

            // Truncate context if too large
            if (strlen($contextJson) > $truncateAt) {
                $attributes['context'] = ['_truncated' => true, '_original_size' => strlen($contextJson)];
                $attributes['is_truncated'] = true;
            }
        }

        // Set occurred_at if not provided
        if (! isset($attributes['occurred_at'])) {
            $attributes['occurred_at'] = now();
        }

        return static::create($attributes);
    }
}
