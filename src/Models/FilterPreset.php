<?php

declare(strict_types=1);

namespace LogScope\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FilterPreset extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = [
        'is_default' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('logscope.tables.presets', 'filter_presets');
    }

    /**
     * Scope: Get default preset.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope: Order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Apply this preset's filters to a LogEntry query.
     */
    public function applyTo(Builder $query): Builder
    {
        $filters = $this->filters ?? [];

        if (! empty($filters['levels'])) {
            $query->level($filters['levels']);
        }

        if (! empty($filters['exclude_levels'])) {
            $query->excludeLevel($filters['exclude_levels']);
        }

        if (! empty($filters['channels'])) {
            $query->channel($filters['channels']);
        }

        if (! empty($filters['environments'])) {
            $query->environment($filters['environments']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = ! empty($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from']) : null;
            $to = ! empty($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to']) : null;
            $query->dateRange($from, $to);
        }

        if (! empty($filters['trace_id'])) {
            $query->traceId($filters['trace_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->userId($filters['user_id']);
        }

        if (! empty($filters['ip_address'])) {
            $query->ipAddress($filters['ip_address']);
        }

        if (! empty($filters['http_method'])) {
            $query->httpMethod($filters['http_method']);
        }

        if (! empty($filters['url'])) {
            $query->url($filters['url']);
        }

        return $query;
    }

    /**
     * Set this preset as the default, unsetting any other default.
     */
    public function setAsDefault(): void
    {
        static::query()->where('is_default', true)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    /**
     * Create a preset from filter array.
     */
    public static function createFromFilters(string $name, array $filters, ?string $description = null): static
    {
        return static::create([
            'name' => $name,
            'description' => $description,
            'filters' => $filters,
            'sort_order' => static::query()->max('sort_order') + 1,
        ]);
    }
}
