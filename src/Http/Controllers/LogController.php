<?php

declare(strict_types=1);

namespace LogScope\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LogScope\Models\FilterPreset;
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
            'presets' => FilterPreset::ordered()->get(),
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

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from')) : null;
            $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to')) : null;
            $query->dateRange($from, $to);
        }

        if ($request->filled('fingerprint')) {
            $query->fingerprint($request->input('fingerprint'));
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
}
