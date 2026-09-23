<?php

declare(strict_types=1);

namespace LogScope\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogScope\Casts\LogStatusCast;
use LogScope\Enums\LogStatus;

/**
 * A set of entries sharing one fingerprint — the same issue, however many
 * times it fired (#29).
 *
 * Triage lives here rather than on the entry: an error that happened 3,000
 * times is resolved by writing one row, and stays resolved as new occurrences
 * arrive. The exception is a new occurrence of something already Resolved,
 * which reopens the group and stamps regressed_at — see GroupRecorder. An
 * Ignored group is never reopened; that is the difference between the two
 * closed statuses.
 */
class LogGroup extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'regressed_at' => 'datetime',
            'status' => LogStatusCast::class,
            'occurrence_count' => 'integer',
        ];
    }

    /**
     * Resolve the table name from config at runtime, matching LogEntry.
     */
    public function getTable(): string
    {
        return config('logscope.groups_table', 'log_groups');
    }

    /**
     * The occurrences in this group, newest first.
     *
     * Joined on fingerprint rather than a foreign key: the write path upserts
     * groups by fingerprint without reading the group's id back, which is what
     * keeps a batch flush to one statement instead of one per entry.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LogEntry::class, 'fingerprint', 'fingerprint');
    }

    /**
     * Scope: Filter by status (string, enum, or array of either).
     */
    public function scopeStatus(Builder $query, string|array|LogStatus $status): Builder
    {
        if ($status instanceof LogStatus) {
            return $query->where('status', $status->value);
        }

        if (is_array($status)) {
            $values = array_map(
                fn ($s) => $s instanceof LogStatus ? $s->value : $s,
                $status
            );

            return $query->whereIn('status', $values);
        }

        return $query->where('status', $status);
    }

    /**
     * Scope: Only open groups (needs attention).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LogStatus::Open->value);
    }

    /**
     * Scope: Only groups needing attention (not resolved/ignored).
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LogStatus::Open->value,
            LogStatus::Investigating->value,
        ]);
    }

    /**
     * Scope: Only closed groups (resolved or ignored).
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LogStatus::Resolved->value,
            LogStatus::Ignored->value,
        ]);
    }

    /**
     * Scope: Groups that came back after being resolved.
     */
    public function scopeRegressed(Builder $query): Builder
    {
        return $query->whereNotNull('regressed_at');
    }

    /**
     * Check if the group needs attention.
     */
    public function needsAttention(): bool
    {
        return ! LogStatus::isClosedValue($this->status);
    }

    /**
     * Check if the group is resolved.
     */
    public function isResolved(): bool
    {
        return $this->status === LogStatus::Resolved;
    }

    /**
     * Whether this group was resolved and then fired again.
     */
    public function isRegressed(): bool
    {
        return $this->regressed_at !== null;
    }

    /**
     * Set the status of the group.
     *
     * Clears regressed_at: the flag exists to show that a resolved group came
     * back unacknowledged, and setting a status by hand is the acknowledgement.
     * Leaving it set would mark the group as regressed forever.
     */
    public function setStatus(LogStatus|string $status, ?string $changedBy = null, ?string $note = null): bool
    {
        $data = [
            'status' => LogStatus::valueOf($status),
            'status_changed_at' => now(),
            'regressed_at' => null,
        ];

        if ($changedBy !== null) {
            $data['status_changed_by'] = $changedBy;
        }

        if ($note !== null) {
            $data['note'] = $note;
        }

        return $this->update($data);
    }
}
