<?php

declare(strict_types=1);

namespace LogScope\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
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
            'environments' => $this->getAvailableEnvironments(),
            'httpMethods' => $this->getAvailableHttpMethods(),
            'quickFilters' => config('logscope.quick_filters', []),
        ]);
    }

    /**
     * Get paginated log entries with filters.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = LogEntry::query()->recent();

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

        if ($request->filled('environments')) {
            $query->environment((array) $request->input('environments'));
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

                        if ($field === 'any') {
                            $q->where(function ($subQ) use ($value) {
                                $subQ->where('message', 'like', $value)
                                    ->orWhere('context', 'like', $value)
                                    ->orWhere('source', 'like', $value);
                            }, null, null, $boolean);
                        } else {
                            $q->where($field, 'like', $value, $boolean);
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
                $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->input('from')) : null;
                $to = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->input('to')) : null;
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

        if ($request->filled('environments')) {
            $query->environment((array) $request->input('environments'));
        }

        $deleted = $query->delete();

        return response()->json(['message' => "{$deleted} log entries cleared"]);
    }

    /**
     * Get statistics for the dashboard.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => LogEntry::count(),
            'by_level' => LogEntry::selectRaw('level, count(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level'),
            'today' => LogEntry::where('occurred_at', '>=', now()->startOfDay())->count(),
            'this_hour' => LogEntry::where('occurred_at', '>=', now()->startOfHour())->count(),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Get available log levels.
     */
    protected function getAvailableLevels(): array
    {
        return LogEntry::distinct()
            ->pluck('level')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get available channels.
     */
    protected function getAvailableChannels(): array
    {
        return LogEntry::distinct()
            ->whereNotNull('channel')
            ->pluck('channel')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get available environments.
     */
    protected function getAvailableEnvironments(): array
    {
        return LogEntry::distinct()
            ->whereNotNull('environment')
            ->pluck('environment')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get available HTTP methods.
     */
    protected function getAvailableHttpMethods(): array
    {
        return LogEntry::distinct()
            ->whereNotNull('http_method')
            ->pluck('http_method')
            ->sort()
            ->values()
            ->toArray();
    }
}
