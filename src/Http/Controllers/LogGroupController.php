<?php

declare(strict_types=1);

namespace LogScope\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LogScope\Concerns\ResolvesStatuses;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;

/**
 * The grouped view of the log (#29).
 *
 * Separate from LogController because the two list different things: that one
 * pages over occurrences, this one pages over issues. Triage endpoints live
 * here too, since status and notes belong to the group.
 */
class LogGroupController extends Controller
{
    use ResolvesStatuses;

    /**
     * List groups, most recently active first.
     *
     * Filtering is deliberately narrower than the entry list: statuses,
     * levels, channels and time range, plus substring matches (include or
     * exclude) on the sample message. The
     * entry list's structured search syntax queries per-occurrence fields
     * (trace id, url, ip) that a group does not have, so it is not offered
     * here rather than being silently ignored.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LogGroup::query();

        if ($request->filled('statuses')) {
            $query->status((array) $request->input('statuses'));
        } else {
            $query->open();
        }

        if ($request->filled('levels')) {
            $query->whereIn('level', (array) $request->input('levels'));
        }

        if ($request->filled('exclude_levels')) {
            $query->whereNotIn('level', (array) $request->input('exclude_levels'));
        }

        if ($request->filled('channels')) {
            $query->whereIn('channel', (array) $request->input('channels'));
        }

        if ($request->filled('exclude_channels')) {
            $query->whereNotIn('channel', (array) $request->input('exclude_channels'));
        }

        // Each search row is a plain substring on the sample message. NOT rows
        // always narrow; the rest combine by search_mode, as on the entry list.
        $like = fn ($value) => '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $value).'%';
        $terms = collect((array) $request->input('searches', []))
            ->filter(fn ($term) => is_array($term) && filled($term['value'] ?? null));
        if ($request->filled('search')) {
            $terms->push(['value' => $request->input('search')]);
        }
        [$excluded, $included] = $terms->partition(fn ($term) => filter_var($term['exclude'] ?? false, FILTER_VALIDATE_BOOLEAN));

        foreach ($excluded as $term) {
            $query->where('sample_message', 'not like', $like($term['value']));
        }

        if ($included->isNotEmpty()) {
            $boolean = $request->input('search_mode') === 'or' ? 'or' : 'and';
            $query->where(function ($q) use ($included, $like, $boolean) {
                foreach ($included as $term) {
                    $q->where('sample_message', 'like', $like($term['value']), $boolean);
                }
            });
        }

        // A group is in range when its active span overlaps the window. A
        // group with no occurrence inside a wide span can still slip in; exact
        // per-window matching would mean joining the entries table.
        if ($request->filled('from') || $request->filled('to')) {
            try {
                $timezone = $request->input('timezone', config('app.timezone', 'UTC'));
                if ($request->filled('from')) {
                    $query->where('last_seen_at', '>=', Carbon::parse($request->input('from'), $timezone)->utc());
                }
                if ($request->filled('to')) {
                    $query->where('first_seen_at', '<=', Carbon::parse($request->input('to'), $timezone)->utc());
                }
            } catch (\Throwable $e) {
                return response()->json([
                    'error' => 'Invalid date format',
                    'message' => 'Please provide valid dates in a recognized format (e.g., YYYY-MM-DD)',
                ], 422);
            }
        }

        $perPage = $this->perPage($request);

        // Matches the [status, last_seen_at] index. id breaks ties so the
        // cursor can never re-serve or skip a row when two groups share a
        // last_seen_at to the second.
        $query->orderByDesc('last_seen_at')->orderByDesc('id');

        if ($cursor = $this->decodeCursor($request->input('cursor'))) {
            $query->where(function ($q) use ($cursor) {
                $q->where('last_seen_at', '<', $cursor['last_seen_at'])
                    ->orWhere(function ($q) use ($cursor) {
                        $q->where('last_seen_at', '=', $cursor['last_seen_at'])
                            ->where('id', '<', $cursor['id']);
                    });
            });
        }

        $countResult = (clone $query)->reorder()->limit(1001)->get(['id'])->count();

        $items = $query->limit($perPage + 1)->get();
        $hasNext = $items->count() > $perPage;
        $items = $items->take($perPage);

        $nextCursor = null;
        if ($hasNext && $last = $items->last()) {
            $nextCursor = base64_encode((string) json_encode([
                'last_seen_at' => $last->last_seen_at->format('Y-m-d H:i:s'),
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
     * Get a single group.
     */
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => LogGroup::findOrFail($id)]);
    }

    /**
     * The occurrences in a group, newest first.
     *
     * Served by the [fingerprint, occurred_at] index — without it this sorts
     * every row in the group to return the first page, and a group with
     * thousands of rows is the case this feature exists for.
     */
    public function entries(Request $request, string $id): JsonResponse
    {
        $group = LogGroup::findOrFail($id);

        $perPage = $this->perPage($request);

        $query = LogEntry::query()
            ->where('fingerprint', $group->fingerprint)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($cursor = $this->decodeCursor($request->input('cursor'), 'occurred_at')) {
            $query->where(function ($q) use ($cursor) {
                $q->where('occurred_at', '<', $cursor['occurred_at'])
                    ->orWhere(function ($q) use ($cursor) {
                        $q->where('occurred_at', '=', $cursor['occurred_at'])
                            ->where('id', '<', $cursor['id']);
                    });
            });
        }

        $items = $query->limit($perPage + 1)->get();
        $hasNext = $items->count() > $perPage;
        $items = $items->take($perPage);

        $nextCursor = null;
        if ($hasNext && $last = $items->last()) {
            $nextCursor = base64_encode((string) json_encode([
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
                'occurrence_count' => $group->occurrence_count,
            ],
        ]);
    }

    /**
     * Update the status of a group. Its entries keep their own statuses.
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

        $group = LogGroup::findOrFail($id);
        $status = $request->input('status');

        if (! in_array($status, $this->getValidStatuses())) {
            return response()->json(['error' => 'Invalid status'], 422);
        }

        $group->setStatus($status, LogScope::getStatusChangedBy($request), $request->input('note'));

        return response()->json([
            'data' => $group->fresh(),
            'message' => 'Status updated to '.$status,
        ]);
    }

    /**
     * Update status for multiple groups.
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

        $status = $request->input('status');

        if (! in_array($status, $this->getValidStatuses())) {
            return response()->json(['error' => 'Invalid status'], 422);
        }

        $values = [
            'status' => $status,
            'status_changed_at' => now(),
            'status_changed_by' => LogScope::getStatusChangedBy($request),
            // Triaging by hand acknowledges the regression, so the flag that
            // marks a group as "came back" is cleared here too — otherwise it
            // would stay set forever. Mirrors LogGroup::setStatus().
            'regressed_at' => null,
        ];
        $updated = 0;

        foreach (array_chunk(array_unique((array) $request->input('ids')), LogEntry::DELETE_CHUNK_SIZE) as $chunk) {
            $updated += LogGroup::query()->whereIn('id', $chunk)->update($values);
        }

        return response()->json(['message' => "{$updated} groups updated to {$status}"]);
    }

    /**
     * Update the note on a group.
     */
    public function updateNote(Request $request, string $id): JsonResponse
    {
        if (! config('logscope.features.notes', true)) {
            return response()->json(['error' => 'Notes feature is disabled'], 403);
        }

        $request->validate([
            'note' => 'nullable|string|max:10000',
        ]);

        $group = LogGroup::findOrFail($id);
        $group->update(['note' => $request->input('note')]);

        return response()->json(['data' => $group->fresh(), 'message' => 'Note updated']);
    }

    /**
     * Delete a group and every occurrence in it.
     */
    public function destroy(string $id): JsonResponse
    {
        $group = LogGroup::findOrFail($id);

        $deleted = LogEntry::deleteInChunks(
            LogEntry::query()->where('fingerprint', $group->fingerprint)
        );

        $group->delete();

        return response()->json(['message' => "Group and {$deleted} entries deleted"]);
    }

    /**
     * Page size, bounded the same way the entry list bounds it.
     */
    protected function perPage(Request $request): int
    {
        $default = (int) config('logscope.pagination.per_page', 50);
        $max = (int) config('logscope.pagination.max_per_page', 200);

        $perPage = (int) $request->input('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Decode a pagination cursor, ignoring anything malformed rather than
     * failing the request — a stale cursor should reload the list, not 500.
     *
     * @return array<string, string>|null
     */
    protected function decodeCursor(mixed $cursor, string $timeKey = 'last_seen_at'): ?array
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode($cursor, true), true);

        if (! is_array($decoded) || ! isset($decoded[$timeKey], $decoded['id'])) {
            return null;
        }

        return [
            $timeKey => (string) $decoded[$timeKey],
            'id' => (string) $decoded['id'],
        ];
    }
}
