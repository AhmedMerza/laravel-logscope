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

        // Handle advanced search with multiple conditions
        if ($request->filled('searches')) {
            $searches = $request->input('searches');
            $searchMode = $request->input('search_mode', 'and');

            if (is_array($searches) && count($searches) > 0) {
                $method = $searchMode === 'or' ? 'orWhere' : 'where';

                $query->where(function ($q) use ($searches, $searchMode) {
                    foreach ($searches as $index => $search) {
                        if (empty($search['value'])) {
                            continue;
                        }

                        $field = $search['field'] ?? 'any';
                        $value = '%'.$search['value'].'%';
                        $boolean = ($index === 0 || $searchMode === 'and') ? 'and' : 'or';
                        $exclude = ! empty($search['exclude']) && $search['exclude'] !== '0';

                        if ($field === 'any') {
                            if ($exclude) {
                                // NOT: all fields must NOT match (handle NULLs)
                                $q->where(function ($subQ) use ($value) {
                                    $subQ->where(function ($mq) use ($value) {
                                        $mq->where('message', 'not like', $value)->orWhereNull('message');
                                    })->where(function ($cq) use ($value) {
                                        $cq->where('context', 'not like', $value)->orWhereNull('context');
                                    })->where(function ($sq) use ($value) {
                                        $sq->where('source', 'not like', $value)->orWhereNull('source');
                                    });
                                }, null, null, $boolean);
                            } else {
                                // INCLUDE: any field can match
                                $q->where(function ($subQ) use ($value) {
                                    $subQ->where('message', 'like', $value)
                                        ->orWhere('context', 'like', $value)
                                        ->orWhere('source', 'like', $value);
                                }, null, null, $boolean);
                            }
                        } else {
                            if ($exclude) {
                                // Handle NULL for specific field exclude
                                $q->where(function ($subQ) use ($field, $value) {
                                    $subQ->where($field, 'not like', $value)->orWhereNull($field);
                                }, null, null, $boolean);
                            } else {
                                $q->where($field, 'like', $value, $boolean);
                            }
                        }
                    }
                });
            }
        } elseif ($request->filled('search')) {
            // Fallback to simple search for backwards compatibility
            $query->search($request->input('search'));
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
}
