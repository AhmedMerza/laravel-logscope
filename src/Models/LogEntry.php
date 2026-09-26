<?php

declare(strict_types=1);

namespace LogScope\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogScope\Casts\LogStatusCast;
use LogScope\Database\Factories\LogEntryFactory;
use LogScope\Enums\LogStatus;
use LogScope\Services\Fingerprint;
use LogScope\Services\GroupRecorder;
use LogScope\Services\WriteFailureLogger;
use Throwable;

class LogEntry extends Model
{
    use HasFactory;
    use HasUlids;
    use Prunable;

    /**
     * Rows deleted per statement by {@see self::deleteInChunks()}.
     *
     * Matches the default `logscope:prune --chunk` uses.
     */
    public const DELETE_CHUNK_SIZE = 1000;

    /**
     * Ceiling on a caller-supplied chunk size.
     *
     * Every id in a chunk is bound as its own statement parameter, and each
     * engine caps how many a statement may carry — around 32,000 on SQLite,
     * 65,535 on MySQL and Postgres. Staying well under the lowest keeps an
     * oversized `--chunk` merely slow instead of fatal.
     */
    public const MAX_DELETE_CHUNK_SIZE = 10000;

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
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'status' => LogStatusCast::class,
            'is_truncated' => 'boolean',
        ];
    }

    /**
     * Captured request headers (#30).
     *
     * Not a plain 'array' cast: the default encoder escapes slashes and
     * unicode, which would store `application\/xml` and
     * `…[truncated]`. `headers:` search is a substring LIKE over
     * this column, so `headers:application/xml` — the search people
     * actually type — would find nothing.
     */
    protected function headers(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true),
            set: fn (?array $value): ?string => $value === null ? null : static::encodeHeaders($value),
        );
    }

    /**
     * Encode headers for storage. Shared with prepareData(), which writes
     * through insert() and so never reaches the mutator above.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE is load-bearing: header values are the
     * only attacker-controlled bytes that reach this encoder, and a client
     * is free to send a malformed sequence in, say, Referer. Without it
     * json_encode returns false, which casts to '' — silently wiping the
     * row's headers on sqlite, and failing the insert outright on MySQL
     * and Postgres, where '' is not valid JSON. Bad bytes become U+FFFD
     * instead; valid input is byte-identical either way.
     */
    public static function encodeHeaders(array $headers): string
    {
        return (string) json_encode(
            $headers,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Log context (#67).
     *
     * Not a plain 'array' cast. That cast encodes with bare json_encode(),
     * which returns false on a malformed UTF-8 byte; Laravel turns the
     * false into a JsonEncodingException, LogCapture catches it, and
     * FallbackWriter rewrites the row with a _logscope_write_failure
     * marker — so the one log line written to record a bad request is the
     * line that arrives with no detail.
     */
    protected function context(): Attribute
    {
        return Attribute::make(
            // Deliberately not typed ?array, unlike the setter. The column can
            // already hold scalar JSON: under the 'array' cast this replaces,
            // assigning a string stored the double-encoded "\"…\"", and
            // json_decode() hands that back as a string. Declaring ?array here
            // turns every read of such a legacy row into a TypeError — a 500
            // on the dashboard — where the old cast returned the value. Reads
            // stay exactly as permissive as they were; only writes are guarded.
            get: fn (?string $value): mixed => $value === null ? null : json_decode($value, true),
            set: fn (?array $value): ?string => $value === null ? null : static::encodeContext($value),
        );
    }

    /**
     * Encode context for storage. Shared with prepareData() and
     * ImportCommand, which write through insert() and so never reach the
     * mutator above — #67 found three bare json_encode() calls on this one
     * column, which is why they now share an encoder rather than a flag.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE is load-bearing here for the same reason
     * as encodeHeaders(), and reached far more often: any ordinary
     * Log::warning('…', ['agent' => $request->userAgent()]) puts raw client
     * bytes in this column. The flag substitutes inside object keys as well
     * as values, which is what covers the header names sanitizeHeaders()
     * copies in verbatim — unlike captureHeaders(), it has no allowlist, so
     * those keys are attacker-controlled.
     *
     * No escaping flags, deliberately unlike encodeHeaders(): the 'array'
     * cast this replaces used none, and `context:` search is a substring
     * LIKE over these bytes. Valid input stays byte-identical to what
     * previous versions stored.
     */
    public static function encodeContext(array $context): string
    {
        return (string) json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function getTable(): string
    {
        try {
            return config('logscope.table', 'log_entries');
        } catch (Throwable) {
            return 'log_entries';
        }
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        if (! config('logscope.retention.enabled', true)) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::pastRetention();
    }

    /**
     * Retention in days per level (#31): every level listed in
     * retention.levels, lowercased, then '*' for any level not listed,
     * which falls back to retention.days.
     *
     * @return array<string, int>
     */
    public static function retentionPolicy(): array
    {
        $policy = [];

        foreach ((array) config('logscope.retention.levels', []) as $level => $days) {
            $policy[strtolower((string) $level)] = (int) $days;
        }

        $policy['*'] = (int) config('logscope.retention.days', 30);

        return $policy;
    }

    /**
     * Entries older than their level's retention window.
     *
     * @param  array<string, int>|null  $policy  shaped like retentionPolicy(), which it defaults to
     */
    public static function pastRetention(?array $policy = null): Builder
    {
        $policy ??= static::retentionPolicy();
        $now = now();

        // The shortest window bounds the whole set, so the outer range can
        // use the occurred_at index before the per-level conditions apply.
        // With no levels listed this is the only condition — the same query
        // as a plain `days` policy.
        $query = static::query()->where('occurred_at', '<', $now->copy()->subDays(min($policy)));

        if (count($policy) === 1) {
            return $query;
        }

        $fallback = array_pop($policy);

        return $query->where(function (Builder $query) use ($policy, $fallback, $now) {
            foreach ($policy as $level => $days) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('level', $level)
                    ->where('occurred_at', '<', $now->copy()->subDays($days)));
            }

            $query->orWhere(fn (Builder $q) => $q
                ->whereNotIn('level', array_keys($policy))
                ->where('occurred_at', '<', $now->copy()->subDays($fallback)));
        });
    }

    /**
     * Delete everything the query matches, in bounded chunks (#46).
     *
     * The ids are read first with a plain SELECT, then deleted by primary
     * key. Deleting by the filter directly makes MySQL lock every row and
     * gap it scans, so it waits on any row another session has inserted but
     * not committed — measured as a lock wait timeout even in chunks. An
     * MVCC read never sees that row, so it stays out of the id list, and a
     * delete by primary key touches only the rows it names.
     *
     * Not absolute: on a table small enough that InnoDB prefers a full scan
     * to primary-key lookups, the delete scans and waits again. It narrows
     * the window rather than closing it — #45 is what keeps LogScope's own
     * writes out of the app's transaction in the first place.
     */
    public static function deleteInChunks(Builder $query, int $chunkSize = self::DELETE_CHUNK_SIZE): int
    {
        // `--chunk` reaches here straight off the command line. A negative
        // one the query builder drops altogether, leaving the SELECT
        // unbounded — the whole-table statement this method exists to
        // avoid. Zero is worse than it looks: the loop below continues
        // while the SELECT filled a chunk, and an empty result trivially
        // "fills" a chunk of zero, so it spins forever. This clamp is load
        // bearing for termination, not just for sane batch sizes.
        $chunkSize = max(1, min($chunkSize, self::MAX_DELETE_CHUNK_SIZE));

        $deleted = 0;

        do {
            $ids = (clone $query)->limit($chunkSize)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += static::query()->whereIn('id', $ids)->delete();
            }

            // Carry on by what the SELECT found, not by what the DELETE
            // removed. Another session deleting these same ids in between
            // the two statements would otherwise read as "nothing left"
            // and strand every row past this chunk.
        } while ($ids->count() === $chunkSize);

        return $deleted;
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
     * Scope: Filter by trace ID. Uses exact match for full UUIDs (index seek)
     * and prefix LIKE for partial input. Empty input is a no-op so callers
     * that skip the request->filled() guard don't accidentally match all rows.
     * Suffix-substring search is no longer supported.
     */
    public function scopeTraceId(Builder $query, string $traceId): Builder
    {
        $traceId = trim($traceId);

        if ($traceId === '') {
            return $query;
        }

        $isFullUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $traceId)
            || preg_match('/^[0-9a-f]{32}$/i', $traceId);

        if ($isFullUuid) {
            return $query->where('trace_id', $traceId);
        }

        return $query->where('trace_id', 'like', $traceId.'%');
    }

    /**
     * Scope: Filter by user ID. Numeric input → exact match (index seek);
     * non-numeric → prefix LIKE. Empty input is a no-op.
     */
    public function scopeUserId(Builder $query, int|string $userId): Builder
    {
        $userId = trim((string) $userId);

        if ($userId === '') {
            return $query;
        }

        // Compare as a string: user_id is a string column, and an integer
        // binding makes MySQL cast every row, skipping the index.
        if (ctype_digit($userId)) {
            return $query->where('user_id', $userId);
        }

        return $query->where('user_id', 'like', $userId.'%');
    }

    /**
     * Scope: Filter by IP address. Full IPv4 / IPv6 → exact match (index seek);
     * partial input → prefix LIKE. Empty input is a no-op.
     */
    public function scopeIpAddress(Builder $query, string $ipAddress): Builder
    {
        $ipAddress = trim($ipAddress);

        if ($ipAddress === '') {
            return $query;
        }

        $isFullIp = filter_var($ipAddress, FILTER_VALIDATE_IP) !== false;

        if ($isFullIp) {
            return $query->where('ip_address', $ipAddress);
        }

        return $query->where('ip_address', 'like', $ipAddress.'%');
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
     * Scope: Filter by status.
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
     * Scope: Only open entries (needs attention).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LogStatus::Open->value);
    }

    /**
     * Scope: Only entries needing attention (not resolved/ignored).
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LogStatus::Open->value,
            LogStatus::Investigating->value,
        ]);
    }

    /**
     * Scope: Only closed entries (resolved or ignored).
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LogStatus::Resolved->value,
            LogStatus::Ignored->value,
        ]);
    }

    /**
     * Check if the entry needs attention.
     */
    public function needsAttention(): bool
    {
        return ! LogStatus::isClosedValue($this->status);
    }

    /**
     * Check if the entry is resolved.
     */
    public function isResolved(): bool
    {
        return $this->status === LogStatus::Resolved;
    }

    /**
     * Set the status of the entry.
     */
    public function setStatus(LogStatus|string $status, ?string $changedBy = null, ?string $note = null): bool
    {
        $data = [
            'status' => LogStatus::valueOf($status),
            'status_changed_at' => now(),
        ];

        if ($changedBy !== null) {
            $data['status_changed_by'] = $changedBy;
        }

        if ($note !== null) {
            $data['note'] = $note;
        }

        return $this->update($data);
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
     * Prepare raw insert-ready data for a log entry without persisting it.
     *
     * Performs the same preview generation and truncation as createEntry(),
     * but also sets id (ULID), created_at, and JSON-encodes context so the
     * result can be passed directly to LogEntry::insert() for batch writes.
     *
     * NOTE: Keep in sync with createEntry() — any new field defaults or
     * transforms added there must be mirrored here.
     */
    public static function prepareData(array $attributes, array $limits = []): array
    {
        // Use provided limits (from LogBuffer cache) or fall back to config().
        // The fallback is safe during normal request lifecycle but will fail
        // during shutdown — callers in batch mode should always pass $limits.
        if (empty($limits)) {
            $limits = config('logscope.limits', []);
        }

        // Fingerprint first, from the attributes as they arrived (#29).
        // Context is JSON-encoded further down, and Fingerprint needs the
        // array to find an exception in it — computing this any later would
        // silently fall through to the message path and hash a batch-written
        // entry differently from the same log written in sync mode, splitting
        // one error across two groups. A caller that already has one (the
        // backfill command) keeps it.
        $attributes['fingerprint'] ??= Fingerprint::for($attributes);

        $timestamp = date('Y-m-d H:i:s');

        // Generate message preview and handle truncation
        if (isset($attributes['message'])) {
            $maxPreview = $limits['message_preview_length'] ?? 500;
            $maxInline = $limits['message_inline_max'] ?? 16000;
            $truncateAt = $limits['truncate_at'] ?? 1000000;

            $attributes['message_preview'] = static::createPreview($attributes['message'], $maxPreview);

            if (strlen($attributes['message']) > $truncateAt) {
                $attributes['message'] = substr($attributes['message'], 0, $truncateAt);
                $attributes['is_truncated'] = true;
            } elseif (strlen($attributes['message']) > $maxInline) {
                $attributes['is_truncated'] = true;
            }
        }

        // Generate context preview, handle truncation, then JSON-encode for insert()
        if (isset($attributes['context']) && is_array($attributes['context'])) {
            $contextJson = static::encodeContext($attributes['context']);
            $maxPreview = $limits['context_preview_length'] ?? 500;
            $truncateAt = $limits['truncate_at'] ?? 1000000;

            $attributes['context_preview'] = static::createPreview($contextJson, $maxPreview);

            if (strlen($contextJson) > $truncateAt) {
                $attributes['context'] = static::encodeContext(['_truncated' => true, '_original_size' => strlen($contextJson)]);
                $attributes['is_truncated'] = true;
            } else {
                $attributes['context'] = $contextJson;
            }
        }

        // insert() bypasses the 'headers' array cast, so encode it here.
        // isset() skips null, which must stay a real null rather than
        // becoming the string "null" (#30).
        if (isset($attributes['headers']) && is_array($attributes['headers'])) {
            $attributes['headers'] = static::encodeHeaders($attributes['headers']);
        }

        // Normalise occurred_at to a DB-safe string
        if (! isset($attributes['occurred_at'])) {
            $attributes['occurred_at'] = $timestamp;
        } elseif ($attributes['occurred_at'] instanceof \DateTimeInterface) {
            $attributes['occurred_at'] = $attributes['occurred_at']->format('Y-m-d H:i:s');
        }

        // Default status
        if (! isset($attributes['status'])) {
            $attributes['status'] = LogStatus::Open->value;
        }

        // insert() bypasses HasUlids model boot — generate ULID explicitly
        $attributes['id'] = strtolower((string) Str::ulid());

        // insert() bypasses Eloquent; created_at has DB default but set explicitly for clarity
        $attributes['created_at'] = $timestamp;

        return $attributes;
    }

    /**
     * Normalize an authenticated user's id for the user_id column (#26).
     * Integer, string and Stringable ids (e.g. UUID objects) are stored as
     * strings. Anything else, or an id too long for the column, becomes null:
     * one unstorable value fails the insert and, in batch mode, loses every
     * log in the chunk. A truncated id could match a different user, so it
     * isn't truncated.
     */
    public static function normalizeUserId(mixed $id): ?string
    {
        if (! is_int($id) && ! is_string($id) && ! $id instanceof \Stringable) {
            return null;
        }

        $id = (string) $id;

        return $id === '' || mb_strlen($id) > 255 ? null : $id;
    }

    /**
     * Create a new log entry with automatic preview generation.
     */
    public static function createEntry(array $attributes): static
    {
        $limits = config('logscope.limits', []);

        // Fingerprint from the attributes as they arrived, for the same
        // reason prepareData() does — the two paths must agree (#29).
        $attributes['fingerprint'] ??= Fingerprint::for($attributes);

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
            $contextJson = static::encodeContext($attributes['context']);
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

        // Set default status
        if (! isset($attributes['status'])) {
            $attributes['status'] = LogStatus::Open->value;
        }

        $entry = static::create($attributes);

        static::recordGroup($attributes);

        return $entry;
    }

    /**
     * Roll this entry up into its group (#29), for the sync and queue write
     * paths. Batch writes go through LogBuffer, which records a whole chunk
     * in one set of statements instead of one per row.
     *
     * Failures are reported and swallowed for the same reason they are in
     * LogBuffer: the entry is already written and is the record of what
     * happened. A group that failed to update is a counter being wrong, which
     * `logscope:doctor` surfaces and the backfill repairs — worth far less
     * than the log itself.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function recordGroup(array $attributes): void
    {
        try {
            GroupRecorder::record([$attributes]);
        } catch (Throwable $e) {
            WriteFailureLogger::report($e, 'group-recorder');
        }
    }
}
