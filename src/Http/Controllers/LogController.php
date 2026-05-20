<?php

declare(strict_types=1);

namespace LogScope\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use LogScope\Enums\LogStatus;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteFailureLogger;

class LogController extends Controller
{
    /**
     * Display the log viewer.
     */
    public function index(Request $request): View
    {
        return view('logscope::index', [
            'levels' => $this->getAvailableLevels(),
            'channels' => $this->getAvailableChannels(),
            'httpMethods' => $this->getAvailableHttpMethods(),
            'statuses' => $this->getStatusOptions(),
            'quickFilters' => config('logscope.quick_filters', []),
            'features' => [
                'status' => config('logscope.features.status', true),
                'notes' => config('logscope.features.notes', true),
                'search_syntax' => config('logscope.features.search_syntax', true),
                'regex' => config('logscope.features.regex', true),
            ],
            'jsonViewer' => [
                'collapseThreshold' => config('logscope.json_viewer.collapse_threshold', 5),
                'autoCollapseKeys' => config('logscope.json_viewer.auto_collapse_keys', ['trace', 'stack_trace', 'stacktrace', 'backtrace']),
            ],
            'shortcuts' => $this->getShortcuts(),
            'failureBanner' => config('logscope.failure_banner.enabled', true)
                ? WriteFailureLogger::recentFailures()
                : null,
        ]);
    }

    /**
     * Dismiss the cached write-failure breadcrumb so the banner clears.
     */
    public function dismissFailures(Request $request): JsonResponse
    {
        WriteFailureLogger::dismissFailures();

        return response()->json(['ok' => true]);
    }

    /**
     * Get paginated log entries with filters.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = LogEntry::query()->recent();

        // Apply status filter (default: show only open logs)
        if ($request->filled('statuses')) {
            $query->status((array) $request->input('statuses'));
        } else {
            // By default, show only open logs
            $query->open();
        }

        // Apply filters
        if ($request->filled('levels')) {
            $query->level((array) $request->input('levels'));
        }

        if ($request->filled('exclude_levels')) {
            $query->excludeLevel((array) $request->input('exclude_levels'));
        }

        if ($request->filled('channels')) {
            $query->channel((array) $request->input('channels'));
        }

        if ($request->filled('exclude_channels')) {
            $query->excludeChannel((array) $request->input('exclude_channels'));
        }

        // Handle search
        $useRegex = config('logscope.features.regex', true) && $request->boolean('regex', false);
        $useSearchSyntax = config('logscope.features.search_syntax', true);

        if ($request->filled('searches')) {
            // Advanced search with structured conditions (from UI dropdowns)
            $searches = $request->input('searches');
            $validFields = $this->getSearchableFields();

            if (is_array($searches) && count($searches) > 0) {
                foreach ($searches as $search) {
                    if (empty($search['value'])) {
                        continue;
                    }

                    $field = $search['field'] ?? 'any';
                    $value = $search['value'];
                    $exclude = ! empty($search['exclude']) && $search['exclude'] !== '0';

                    // Validate field to prevent SQL injection - only allow known fields
                    if ($field !== 'any' && ! in_array($field, $validFields, true)) {
                        $field = 'any';
                    }

                    // Decide whether the value warrants structured-syntax parsing.
                    // A bare `:` is NOT enough — see shouldUseStructuredSearch().
                    // Otherwise the input is treated as a single substring term,
                    // and the UI's NOT toggle (exclude) inverts that whole group.
                    if ($field === 'any' && $useSearchSyntax && $this->shouldUseStructuredSearch($value)) {
                        // Per-term `-foo` exclusions inside $value are preserved
                        // as the user wrote them. The outer `exclude` flag is
                        // applied to the whole group via $negateGroup below —
                        // NOT propagated onto each term (which would give
                        // "contains NONE" instead of the boolean complement).
                        $terms = $this->parseSearchSyntax($value);
                    } else {
                        $terms = [[
                            'field' => $field,
                            'value' => $value,
                            'exclude' => false,
                        ]];
                    }

                    // Apply this search entry as its own where/whereNot group.
                    // Each searches[] entry is independent; they're AND'd at
                    // the outer query level by virtue of being separate where
                    // clauses.
                    $this->applySearchTerms($query, $terms, $useRegex, negateGroup: $exclude);
                }
            }
        } elseif ($request->filled('search')) {
            if ($useSearchSyntax) {
                // Parse search syntax (field:value, -field:value, plain text)
                $terms = $this->parseSearchSyntax($request->input('search'));
                $this->applySearchTerms($query, $terms, $useRegex);
            } else {
                // Simple LIKE search on all fields
                $query->search($request->input('search'));
            }
        }

        if ($request->filled('from') || $request->filled('to')) {
            try {
                $timezone = $request->input('timezone', config('app.timezone', 'UTC'));
                $from = $request->filled('from')
                    ? \Illuminate\Support\Carbon::parse($request->input('from'), $timezone)->utc()
                    : null;
                $to = $request->filled('to')
                    ? \Illuminate\Support\Carbon::parse($request->input('to'), $timezone)->utc()
                    : null;
                $query->dateRange($from, $to);
            } catch (\Throwable $e) {
                return response()->json([
                    'error' => 'Invalid date format',
                    'message' => 'Please provide valid dates in a recognized format (e.g., YYYY-MM-DD)',
                ], 422);
            }
        }

        if ($request->filled('trace_id')) {
            $query->traceId($request->input('trace_id'));
        }

        if ($request->filled('user_id')) {
            $query->userId($request->input('user_id'));
        }

        if ($request->filled('ip_address')) {
            $query->ipAddress($request->input('ip_address'));
        }

        if ($request->filled('http_method')) {
            $query->httpMethod((array) $request->input('http_method'));
        }

        if ($request->filled('exclude_http_method')) {
            $query->excludeHttpMethod((array) $request->input('exclude_http_method'));
        }

        if ($request->filled('url')) {
            $query->url($request->input('url'));
        }

        $perPage = max(1, min(
            (int) $request->input('per_page', config('logscope.pagination.per_page', 50)),
            config('logscope.pagination.max_per_page', 100)
        ));

        // Decode and apply cursor for keyset pagination
        if ($request->filled('cursor')) {
            $cursor = json_decode(base64_decode($request->input('cursor'), true) ?: '', true);
            if (isset($cursor['occurred_at'], $cursor['id'])) {
                $query->where(function ($q) use ($cursor) {
                    $q->where('occurred_at', '<', $cursor['occurred_at'])
                        ->orWhere(function ($q2) use ($cursor) {
                            $q2->where('occurred_at', $cursor['occurred_at'])
                                ->where('id', '<', $cursor['id']);
                        });
                });
            }
        }

        // Capped count for display. Compute it after applying the cursor so the
        // footer metadata reflects the same remaining slice as the current page.
        // reorder() drops ORDER BY so the DB can early-exit at LIMIT without sorting.
        $countResult = (clone $query)->reorder()->limit(1001)->get(['id'])->count();

        // Fetch one extra to detect has_next. By default include the full
        // message/context so the detail panel can render instantly without an
        // extra round-trip to /logs/{id} — important on high-latency links
        // where every click costs an RTT. Server-side cost is negligible
        // (+1ms typical) and the payload roughly doubles, still well under
        // 200KB for 50 normal rows. Installs with very large messages can
        // disable via `logscope.pagination.eager_load_detail`.
        $listColumns = [
            'id',
            'level',
            'message_preview',
            'context_preview',
            'channel',
            'source',
            'source_line',
            'occurred_at',
            'status',
            'trace_id',
            'user_id',
            'ip_address',
            'http_method',
            'url',
            'is_truncated',
        ];

        if (config('logscope.pagination.eager_load_detail', true)) {
            array_splice($listColumns, 2, 0, ['message', 'context']);
        }

        $items = $query->select($listColumns)->limit($perPage + 1)->get();
        $hasNext = $items->count() > $perPage;
        $items = $items->take($perPage);

        // Build next_cursor from last item
        $nextCursor = null;
        if ($hasNext && $last = $items->last()) {
            $nextCursor = base64_encode(json_encode([
                'occurred_at' => $last->occurred_at->format('Y-m-d H:i:s'),
                'id' => $last->id,
            ]));
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'has_next' => $hasNext,
                'next_cursor' => $nextCursor,
                'per_page' => $perPage,
                'count' => min($countResult, 1000),
                'has_next_count' => $countResult > 1000,
            ],
        ]);
    }

    /**
     * Get a single log entry.
     */
    public function show(string $id): JsonResponse
    {
        $log = LogEntry::findOrFail($id);

        return response()->json(['data' => $log]);
    }

    /**
     * Delete a log entry.
     */
    public function destroy(string $id): JsonResponse
    {
        $log = LogEntry::findOrFail($id);
        $log->delete();

        $this->clearFilterCaches();

        return response()->json(['message' => 'Log entry deleted']);
    }

    /**
     * Update the status of a log entry.
     */
    public function setStatus(Request $request, string $id): JsonResponse
    {
        if (! config('logscope.features.status', true)) {
            return response()->json(['error' => 'Status feature is disabled'], 403);
        }

        $request->validate([
            'status' => 'required|string',
            'note' => 'nullable|string|max:10000',
        ]);

        $log = LogEntry::findOrFail($id);

        // Validate status is a valid option
        $validStatuses = $this->getValidStatuses();
        $status = $request->input('status');

        if (! in_array($status, $validStatuses)) {
            return response()->json(['error' => 'Invalid status'], 422);
        }

        $changedBy = LogScope::getStatusChangedBy($request);
        $note = $request->input('note');

        $log->setStatus($status, $changedBy, $note);

        return response()->json([
            'data' => $log->fresh(),
            'message' => 'Status updated to '.$status,
        ]);
    }

    /**
     * Update status for multiple log entries.
     */
    public function setStatusMany(Request $request): JsonResponse
    {
        if (! config('logscope.features.status', true)) {
            return response()->json(['error' => 'Status feature is disabled'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string',
            'status' => 'required|string',
        ]);

        // Validate status is a valid option
        $validStatuses = $this->getValidStatuses();
        $status = $request->input('status');

        if (! in_array($status, $validStatuses)) {
            return response()->json(['error' => 'Invalid status'], 422);
        }

        $changedBy = LogScope::getStatusChangedBy($request);

        $updated = LogEntry::whereIn('id', $request->input('ids'))
            ->update([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => $changedBy,
            ]);

        return response()->json(['message' => "{$updated} log entries updated to {$status}"]);
    }

    /**
     * Update note for a log entry.
     */
    public function updateNote(Request $request, string $id): JsonResponse
    {
        if (! config('logscope.features.notes', true)) {
            return response()->json(['error' => 'Notes feature is disabled'], 403);
        }

        $request->validate([
            'note' => 'nullable|string|max:10000',
        ]);

        $log = LogEntry::findOrFail($id);
        $log->update(['note' => $request->input('note')]);

        return response()->json(['data' => $log->fresh(), 'message' => 'Note updated']);
    }

    /**
     * Delete multiple log entries.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string',
        ]);

        $deleted = LogEntry::whereIn('id', $request->input('ids'))->delete();

        $this->clearFilterCaches();

        return response()->json(['message' => "{$deleted} log entries deleted"]);
    }

    /**
     * Clear all log entries.
     */
    public function clear(Request $request): JsonResponse
    {
        $query = LogEntry::query();

        // Apply same filters as listing to clear only filtered results
        if ($request->filled('levels')) {
            $query->level((array) $request->input('levels'));
        }

        if ($request->filled('channels')) {
            $query->channel((array) $request->input('channels'));
        }

        if ($request->filled('statuses')) {
            $query->status((array) $request->input('statuses'));
        }

        $deleted = $query->delete();

        $this->clearFilterCaches();

        return response()->json(['message' => "{$deleted} log entries cleared"]);
    }

    /**
     * Clear cached filters and stats after delete operations.
     */
    protected function clearFilterCaches(): void
    {
        Cache::forget('logscope:stats');
        Cache::forget('logscope:filters:levels');
        Cache::forget('logscope:filters:channels');
        Cache::forget('logscope:filters:http_methods');
    }

    /**
     * Get statistics for the dashboard.
     */
    public function stats(): JsonResponse
    {
        $cacheKey = 'logscope:stats';
        $cacheTtl = config('logscope.cache_ttl', 60);

        $stats = Cache::remember($cacheKey, $cacheTtl, function () {
            $startOfDay = now()->startOfDay();
            $startOfHour = now()->startOfHour();

            // Single query replacing 4 separate counts. Groups by level and uses
            // conditional aggregates for the time-scoped counts.
            $rows = LogEntry::selectRaw(
                'level, count(*) as total,'.
                ' sum(case when occurred_at >= ? then 1 else 0 end) as today,'.
                ' sum(case when occurred_at >= ? then 1 else 0 end) as this_hour',
                [$startOfDay, $startOfHour]
            )->groupBy('level')->get();

            return [
                'total' => (int) $rows->sum('total'),
                'by_level' => $rows->pluck('total', 'level'),
                'today' => (int) $rows->sum('today'),
                'this_hour' => (int) $rows->sum('this_hour'),
            ];
        });

        return response()->json(['data' => $stats]);
    }

    /**
     * Get available log levels (cached).
     */
    protected function getAvailableLevels(): array
    {
        return Cache::remember('logscope:filters:levels', config('logscope.cache_ttl', 60), function () {
            return LogEntry::distinct()
                ->pluck('level')
                ->sort()
                ->values()
                ->toArray();
        });
    }

    /**
     * Get available channels (cached).
     */
    protected function getAvailableChannels(): array
    {
        return Cache::remember('logscope:filters:channels', config('logscope.cache_ttl', 60), function () {
            return LogEntry::distinct()
                ->whereNotNull('channel')
                ->pluck('channel')
                ->sort()
                ->values()
                ->toArray();
        });
    }

    /**
     * Get available HTTP methods (cached).
     */
    protected function getAvailableHttpMethods(): array
    {
        return Cache::remember('logscope:filters:http_methods', config('logscope.cache_ttl', 60), function () {
            return LogEntry::distinct()
                ->whereNotNull('http_method')
                ->pluck('http_method')
                ->sort()
                ->values()
                ->toArray();
        });
    }

    /**
     * Get status options for UI (built-in + config overrides/additions).
     */
    protected function getStatusOptions(): array
    {
        $configStatuses = config('logscope.statuses', []);
        $options = [];

        // Add built-in statuses (with config overrides)
        foreach (LogStatus::cases() as $status) {
            $override = $configStatuses[$status->value] ?? [];
            // Allow shortcut to be explicitly set to null to disable
            $shortcut = array_key_exists('shortcut', $override)
                ? $override['shortcut']
                : $status->shortcut();
            $options[] = [
                'value' => $status->value,
                'label' => $override['label'] ?? $status->label(),
                'color' => $override['color'] ?? $status->color(),
                'closed' => $override['closed'] ?? $status->isClosed(),
                'shortcut' => $shortcut,
            ];
        }

        // Add custom statuses from config (non-built-in keys)
        $builtInValues = array_column(LogStatus::cases(), 'value');
        foreach ($configStatuses as $value => $config) {
            if (in_array($value, $builtInValues)) {
                continue; // Already handled above
            }
            $options[] = [
                'value' => $value,
                'label' => $config['label'] ?? ucfirst($value),
                'color' => $config['color'] ?? 'gray',
                'closed' => $config['closed'] ?? false,
                'shortcut' => $config['shortcut'] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Get all valid status values (built-in + custom).
     */
    protected function getValidStatuses(): array
    {
        $statuses = array_column(LogStatus::cases(), 'value');

        // Add custom statuses from config
        $configStatuses = config('logscope.statuses', []);
        $builtInValues = array_column(LogStatus::cases(), 'value');

        foreach (array_keys($configStatuses) as $value) {
            if (! in_array($value, $builtInValues)) {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }

    /**
     * Get keyboard shortcuts for status filtering.
     * Returns array of [key => status_value].
     */
    protected function getShortcuts(): array
    {
        $shortcuts = [];
        foreach ($this->getStatusOptions() as $status) {
            if (! empty($status['shortcut'])) {
                $shortcuts[$status['shortcut']] = $status['value'];
            }
        }

        return $shortcuts;
    }

    /**
     * Get list of searchable fields.
     */
    protected function getSearchableFields(): array
    {
        return [
            'message',
            'source',
            'context',
            'level',
            'channel',
            'user_id',
            'ip_address',
            'url',
            'trace_id',
            'http_method',
        ];
    }

    /**
     * Parse search syntax string into structured search terms.
     *
     * Supported syntax:
     * - field:value (search specific field)
     * - -field:value (exclude from field)
     * - field:"value with spaces" (quoted values)
     * - plain text (search in any field)
     *
     * @return array Array of ['field' => string, 'value' => string, 'exclude' => bool]
     */
    protected function parseSearchSyntax(string $input): array
    {
        $terms = [];
        $validFields = $this->getSearchableFields();

        // Pattern matches: -field:"quoted value" OR -field:value OR plain text
        // Updated to handle quoted values and negation
        $pattern = '/(-)?(\w+):"([^"]+)"|(-)?(\w+):(\S+)|"([^"]+)"|(\S+)/';

        preg_match_all($pattern, $input, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (! empty($match[3])) {
                // -field:"quoted value" or field:"quoted value"
                $exclude = $match[1] === '-';
                $field = strtolower($match[2]);
                $value = $match[3];

                if (in_array($field, $validFields)) {
                    $terms[] = ['field' => $field, 'value' => $value, 'exclude' => $exclude];
                } else {
                    // Unknown field, treat as plain text search
                    $terms[] = ['field' => 'any', 'value' => $match[0], 'exclude' => false];
                }
            } elseif (! empty($match[6])) {
                // -field:value or field:value (unquoted)
                $exclude = $match[4] === '-';
                $field = strtolower($match[5]);
                $value = $match[6];

                if (in_array($field, $validFields)) {
                    $terms[] = ['field' => $field, 'value' => $value, 'exclude' => $exclude];
                } else {
                    // Unknown field, treat as plain text search
                    $terms[] = ['field' => 'any', 'value' => $match[0], 'exclude' => false];
                }
            } elseif (! empty($match[7])) {
                // "quoted plain text"
                $terms[] = ['field' => 'any', 'value' => $match[7], 'exclude' => false];
            } elseif (! empty($match[8])) {
                // Plain text (no field prefix)
                $value = $match[8];
                // Check if it starts with - for exclusion
                if (str_starts_with($value, '-') && strlen($value) > 1) {
                    $terms[] = ['field' => 'any', 'value' => substr($value, 1), 'exclude' => true];
                } else {
                    $terms[] = ['field' => 'any', 'value' => $value, 'exclude' => false];
                }
            }
        }

        return $terms;
    }

    /**
     * Apply a list of parsed search terms to the query as a single group.
     *
     * $negateGroup wraps the whole AND'd expression in a SQL NOT, giving
     * the boolean complement of the include set. This is the semantic of
     * the UI's "NOT" toggle: "show me everything that does NOT match this
     * filter," not "show me logs that contain NONE of the words" (which
     * is what per-term negation would produce, and which fails to sum to
     * the total — see GitHub issue #24).
     *
     * Per-term `exclude` flags inside $terms (from the parser's `-foo`
     * syntax) remain in effect inside the group. When the group is
     * negated, the boolean complement of the whole structured expression
     * is what the user gets — including any per-term `-` they wrote.
     */
    protected function applySearchTerms($query, array $terms, bool $useRegex = false, bool $negateGroup = false): void
    {
        if (empty($terms)) {
            return;
        }

        $method = $negateGroup ? 'whereNot' : 'where';

        $query->$method(function ($q) use ($terms, $useRegex) {
            foreach ($terms as $index => $term) {
                $field = $term['field'];
                $value = $term['value'];
                $exclude = $term['exclude'];

                if ($useRegex) {
                    $this->applyRegexSearch($q, $field, $value, $exclude, $index);
                } else {
                    $this->applyLikeSearch($q, $field, $value, $exclude, $index);
                }
            }
        });
    }

    /**
     * Decide whether a search value should be routed through the structured
     * parser (parseSearchSyntax) or treated as a single substring term.
     *
     * Why this exists: the previous "any colon triggers structured mode"
     * rule mistook trailing punctuation (e.g. `skipped:`) for a field:value
     * separator, then tokenized the whole input by whitespace. Combined with
     * the UI's per-term NOT propagation, this produced search results that
     * silently dropped logs containing some-but-not-all of the tokens (see
     * GitHub issue #24).
     *
     * The structured parser is only invoked when at least one of these
     * actually-structural cues is present:
     *
     *  - A quoted phrase (`"..."`) — the parser strips the quotes.
     *  - A leading `-` on a token (`-foo` or ` -foo`) — per-term exclusion.
     *  - A `field:value` where `field` is a known searchable column name.
     *
     * A bare `:` anywhere else is just punctuation. We keep the input as
     * one substring search instead of fragmenting it.
     */
    private function shouldUseStructuredSearch(string $value): bool
    {
        if (str_contains($value, '"')) {
            return true;
        }

        // Per-token exclusion: a `-` either at the start of the string or
        // immediately after whitespace, followed by at least one non-space.
        if (preg_match('/(^|\s)-\S/', $value)) {
            return true;
        }

        // Valid field-name colon prefix. Anchored to word boundaries so
        // `foo:bar` (where foo isn't a field) doesn't fragment.
        //
        // The pattern is built once per request from the static field list
        // and memoized — same value across every search hit on this instance.
        static $fieldPattern = null;
        if ($fieldPattern === null) {
            $fields = $this->getSearchableFields();
            if (empty($fields)) {
                return false;
            }
            $fieldPattern = '/\b('.implode('|', array_map(fn ($f) => preg_quote($f, '/'), $fields)).'):\S/i';
        }

        return (bool) preg_match($fieldPattern, $value);
    }

    /**
     * Apply LIKE-based search.
     */
    protected function applyLikeSearch($q, string $field, string $value, bool $exclude, int $index): void
    {
        // Defense in depth: validate field is allowed
        $validFields = array_merge(['any'], $this->getSearchableFields());
        if (! in_array($field, $validFields, true)) {
            $field = 'any';
        }

        $likeValue = '%'.$value.'%';
        // All terms within an applySearchTerms call are AND-combined. The
        // $index parameter is kept on the signature so subclasses can switch
        // on position (e.g. swap to 'or' for the first term and 'and' for
        // the rest) without changing the call sites, but we currently have
        // no use for that distinction.
        $boolean = 'and';

        // COALESCE the column to '' so NULL columns never produce NULL truth
        // values. Without this, the group-level `whereNot()` used for the UI's
        // NOT toggle hits SQL three-valued logic: `NOT (NULL OR FALSE)` is
        // NULL, which is filtered out of the result set — meaning rows with
        // NULL columns silently disappear from BOTH include and exclude. With
        // COALESCE, every LIKE comparison is definitively TRUE or FALSE.
        if ($field === 'any') {
            if ($exclude) {
                $q->where(function ($subQ) use ($likeValue) {
                    $subQ->whereRaw("COALESCE(message, '') NOT LIKE ?", [$likeValue])
                        ->whereRaw("COALESCE(context, '') NOT LIKE ?", [$likeValue])
                        ->whereRaw("COALESCE(source, '') NOT LIKE ?", [$likeValue]);
                }, null, null, $boolean);
            } else {
                $q->where(function ($subQ) use ($likeValue) {
                    $subQ->whereRaw("COALESCE(message, '') LIKE ?", [$likeValue])
                        ->orWhereRaw("COALESCE(context, '') LIKE ?", [$likeValue])
                        ->orWhereRaw("COALESCE(source, '') LIKE ?", [$likeValue]);
                }, null, null, $boolean);
            }
        } else {
            // $field comes from the validated whitelist above — safe to interpolate.
            if ($exclude) {
                $q->whereRaw("COALESCE({$field}, '') NOT LIKE ?", [$likeValue], $boolean);
            } else {
                $q->whereRaw("COALESCE({$field}, '') LIKE ?", [$likeValue], $boolean);
            }
        }
    }

    /**
     * Apply regex-based search.
     */
    protected function applyRegexSearch($q, string $field, string $value, bool $exclude, int $index): void
    {
        // Defense in depth: validate field is allowed to prevent SQL injection
        $validFields = array_merge(['any'], $this->getSearchableFields());
        if (! in_array($field, $validFields, true)) {
            $field = 'any';
        }

        // See applyLikeSearch — same AND-combine rationale for $boolean.
        $boolean = 'and';
        $driver = $q->getConnection()->getDriverName();

        // Build regex operator based on database driver
        $regexOp = match ($driver) {
            'mysql', 'mariadb' => $exclude ? 'not regexp' : 'regexp',
            'pgsql' => $exclude ? '!~*' : '~*',  // Case-insensitive
            'sqlite' => $exclude ? 'not regexp' : 'regexp',  // Requires extension
            default => null,
        };

        // Fall back to LIKE if regex not supported
        if ($regexOp === null) {
            $this->applyLikeSearch($q, $field, $value, $exclude, $index);

            return;
        }

        // See the COALESCE rationale on applyLikeSearch — same NULL-handling
        // issue applies to regex against nullable columns.
        if ($field === 'any') {
            if ($exclude) {
                $q->where(function ($subQ) use ($value, $regexOp) {
                    $subQ->whereRaw("COALESCE(message, '') {$regexOp} ?", [$value])
                        ->whereRaw("COALESCE(context, '') {$regexOp} ?", [$value])
                        ->whereRaw("COALESCE(source, '') {$regexOp} ?", [$value]);
                }, null, null, $boolean);
            } else {
                $q->where(function ($subQ) use ($value, $regexOp) {
                    $subQ->whereRaw("COALESCE(message, '') {$regexOp} ?", [$value])
                        ->orWhereRaw("COALESCE(context, '') {$regexOp} ?", [$value])
                        ->orWhereRaw("COALESCE(source, '') {$regexOp} ?", [$value]);
                }, null, null, $boolean);
            }
        } else {
            // $field comes from the validated whitelist — safe to interpolate.
            // $regexOp already encodes negation when $exclude is true, so the
            // include/exclude branches collapse to one whereRaw call.
            $q->whereRaw("COALESCE({$field}, '') {$regexOp} ?", [$value], $boolean);
        }
    }
}
