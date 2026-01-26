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
        ]);
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

            if (is_array($searches) && count($searches) > 0) {
                $terms = [];
                foreach ($searches as $search) {
                    if (empty($search['value'])) {
                        continue;
                    }

                    $field = $search['field'] ?? 'any';
                    $value = $search['value'];
                    $exclude = ! empty($search['exclude']) && $search['exclude'] !== '0';

                    // Parse syntax if field is 'any' and syntax feature is enabled
                    if ($field === 'any' && $useSearchSyntax && str_contains($value, ':')) {
                        $parsed = $this->parseSearchSyntax($value);
                        foreach ($parsed as $term) {
                            // Apply exclude from UI if the parsed term isn't already excluding
                            if ($exclude && ! $term['exclude']) {
                                $term['exclude'] = true;
                            }
                            $terms[] = $term;
                        }
                    } else {
                        $terms[] = [
                            'field' => $field,
                            'value' => $value,
                            'exclude' => $exclude,
                        ];
                    }
                }
                $this->applySearchTerms($query, $terms, $useRegex);
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

        $perPage = min(
            (int) $request->input('per_page', config('logscope.pagination.per_page', 50)),
            config('logscope.pagination.max_per_page', 100)
        );

        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
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

        return response()->json(['message' => "{$deleted} log entries cleared"]);
    }

    /**
     * Get statistics for the dashboard.
     */
    public function stats(): JsonResponse
    {
        $cacheKey = 'logscope:stats';
        $cacheTtl = 60; // 60 seconds

        $stats = Cache::remember($cacheKey, $cacheTtl, function () {
            return [
                'total' => LogEntry::count(),
                'by_level' => LogEntry::selectRaw('level, count(*) as count')
                    ->groupBy('level')
                    ->pluck('count', 'level'),
                'today' => LogEntry::where('occurred_at', '>=', now()->startOfDay())->count(),
                'this_hour' => LogEntry::where('occurred_at', '>=', now()->startOfHour())->count(),
            ];
        });

        return response()->json(['data' => $stats]);
    }

    /**
     * Get available log levels (cached).
     */
    protected function getAvailableLevels(): array
    {
        return Cache::remember('logscope:filters:levels', 60, function () {
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
        return Cache::remember('logscope:filters:channels', 60, function () {
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
        return Cache::remember('logscope:filters:http_methods', 60, function () {
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
     * Apply search terms to query.
     */
    protected function applySearchTerms($query, array $terms, bool $useRegex = false): void
    {
        if (empty($terms)) {
            return;
        }

        $query->where(function ($q) use ($terms, $useRegex) {
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
     * Apply LIKE-based search.
     */
    protected function applyLikeSearch($q, string $field, string $value, bool $exclude, int $index): void
    {
        $likeValue = '%'.$value.'%';
        $boolean = $index === 0 ? 'and' : 'and';

        if ($field === 'any') {
            if ($exclude) {
                $q->where(function ($subQ) use ($likeValue) {
                    $subQ->where(function ($mq) use ($likeValue) {
                        $mq->where('message', 'not like', $likeValue)->orWhereNull('message');
                    })->where(function ($cq) use ($likeValue) {
                        $cq->where('context', 'not like', $likeValue)->orWhereNull('context');
                    })->where(function ($sq) use ($likeValue) {
                        $sq->where('source', 'not like', $likeValue)->orWhereNull('source');
                    });
                }, null, null, $boolean);
            } else {
                $q->where(function ($subQ) use ($likeValue) {
                    $subQ->where('message', 'like', $likeValue)
                        ->orWhere('context', 'like', $likeValue)
                        ->orWhere('source', 'like', $likeValue);
                }, null, null, $boolean);
            }
        } else {
            if ($exclude) {
                $q->where(function ($subQ) use ($field, $likeValue) {
                    $subQ->where($field, 'not like', $likeValue)->orWhereNull($field);
                }, null, null, $boolean);
            } else {
                $q->where($field, 'like', $likeValue, $boolean);
            }
        }
    }

    /**
     * Apply regex-based search.
     */
    protected function applyRegexSearch($q, string $field, string $value, bool $exclude, int $index): void
    {
        $boolean = $index === 0 ? 'and' : 'and';
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

        if ($field === 'any') {
            if ($exclude) {
                $q->where(function ($subQ) use ($value, $regexOp) {
                    $subQ->where(function ($mq) use ($value, $regexOp) {
                        $mq->whereRaw("message {$regexOp} ?", [$value])->orWhereNull('message');
                    })->where(function ($cq) use ($value, $regexOp) {
                        $cq->whereRaw("context {$regexOp} ?", [$value])->orWhereNull('context');
                    })->where(function ($sq) use ($value, $regexOp) {
                        $sq->whereRaw("source {$regexOp} ?", [$value])->orWhereNull('source');
                    });
                }, null, null, $boolean);
            } else {
                $q->where(function ($subQ) use ($value, $regexOp) {
                    $subQ->whereRaw("message {$regexOp} ?", [$value])
                        ->orWhereRaw("context {$regexOp} ?", [$value])
                        ->orWhereRaw("source {$regexOp} ?", [$value]);
                }, null, null, $boolean);
            }
        } else {
            if ($exclude) {
                $q->where(function ($subQ) use ($field, $value, $regexOp) {
                    $subQ->whereRaw("{$field} {$regexOp} ?", [$value])->orWhereNull($field);
                }, null, null, $boolean);
            } else {
                $q->whereRaw("{$field} {$regexOp} ?", [$value], $boolean);
            }
        }
    }
}
