@extends('logscope::layout')

@section('content')
<div x-data="logViewer()" x-init="init()" class="space-y-4">
    <!-- Stats Bar -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Total Logs</div>
            <div class="text-2xl font-bold" x-text="stats.total || 0"></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Today</div>
            <div class="text-2xl font-bold" x-text="stats.today || 0"></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">This Hour</div>
            <div class="text-2xl font-bold" x-text="stats.this_hour || 0"></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Errors</div>
            <div class="text-2xl font-bold text-red-600" x-text="(stats.by_level?.error || 0) + (stats.by_level?.critical || 0)"></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex flex-wrap gap-4 items-end">
            <!-- Search -->
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" x-model.debounce.300ms="filters.search" @input="fetchLogs()"
                    placeholder="Search logs..."
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            </div>

            <!-- Level Filter -->
            <div class="min-w-[150px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Levels</label>
                <select x-model="filters.levels" @change="fetchLogs()" multiple
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
                    @foreach($levels as $level)
                        <option value="{{ $level }}">{{ ucfirst($level) }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Channel Filter -->
            @if(count($channels) > 0)
            <div class="min-w-[150px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Channels</label>
                <select x-model="filters.channels" @change="fetchLogs()" multiple
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
                    @foreach($channels as $channel)
                        <option value="{{ $channel }}">{{ $channel }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <!-- Environment Filter -->
            @if(count($environments) > 0)
            <div class="min-w-[150px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                <select x-model="filters.environments" @change="fetchLogs()" multiple
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
                    @foreach($environments as $env)
                        <option value="{{ $env }}">{{ $env }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <!-- Date Range -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                <input type="datetime-local" x-model="filters.from" @change="fetchLogs()"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                <input type="datetime-local" x-model="filters.to" @change="fetchLogs()"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
                <button @click="clearFilters()" class="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-md">
                    Clear
                </button>
                <button @click="fetchLogs()" class="px-4 py-2 text-sm bg-indigo-600 text-white hover:bg-indigo-700 rounded-md">
                    Refresh
                </button>
            </div>
        </div>

        <!-- Presets -->
        @if(count($presets) > 0)
        <div class="mt-4 pt-4 border-t flex flex-wrap gap-2">
            <span class="text-sm text-gray-500 py-1">Presets:</span>
            @foreach($presets as $preset)
                <button @click="applyPreset({{ json_encode($preset->filters) }})"
                    class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-full {{ $preset->is_default ? 'ring-2 ring-indigo-500' : '' }}">
                    {{ $preset->name }}
                </button>
            @endforeach
        </div>
        @endif
    </div>

    <!-- Log Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <!-- Loading State -->
        <div x-show="loading" class="p-8 text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            <p class="mt-2 text-gray-500">Loading logs...</p>
        </div>

        <!-- Empty State -->
        <div x-show="!loading && logs.length === 0" x-cloak class="p-8 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p class="mt-2 text-gray-500">No log entries found</p>
        </div>

        <!-- Log List -->
        <div x-show="!loading && logs.length > 0" x-cloak>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Level</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Channel</th>
                        <th class="px-4 py-3 w-20"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <template x-for="log in logs" :key="log.id">
                        <tr class="hover:bg-gray-50 cursor-pointer" @click="showDetail(log)">
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap" x-text="formatDate(log.occurred_at)"></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="'log-' + log.level"
                                    x-text="log.level.toUpperCase()"></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <div class="truncate max-w-xl" x-text="log.message_preview || log.message"></div>
                                <div x-show="log.source" class="text-xs text-gray-400 truncate" x-text="log.source + (log.source_line ? ':' + log.source_line : '')"></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500" x-text="log.channel"></td>
                            <td class="px-4 py-3 text-right">
                                <button @click.stop="deleteLog(log.id)" class="text-red-600 hover:text-red-800">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="bg-gray-50 px-4 py-3 flex items-center justify-between border-t">
                <div class="text-sm text-gray-500">
                    Showing <span x-text="((meta.current_page - 1) * meta.per_page) + 1"></span> to
                    <span x-text="Math.min(meta.current_page * meta.per_page, meta.total)"></span> of
                    <span x-text="meta.total"></span> results
                </div>
                <div class="flex gap-2">
                    <button @click="prevPage()" :disabled="meta.current_page <= 1"
                        :class="meta.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                        class="px-3 py-1 text-sm bg-gray-100 rounded-md">
                        Previous
                    </button>
                    <button @click="nextPage()" :disabled="meta.current_page >= meta.last_page"
                        :class="meta.current_page >= meta.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                        class="px-3 py-1 text-sm bg-gray-100 rounded-md">
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div x-show="selectedLog" x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        @keydown.escape.window="selectedLog = null">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black opacity-50" @click="selectedLog = null"></div>
            <div class="relative bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[80vh] overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h3 class="text-lg font-medium">Log Details</h3>
                    <button @click="selectedLog = null" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto max-h-[60vh]" x-show="selectedLog">
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <div class="text-sm text-gray-500">Level</div>
                                <span class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="'log-' + selectedLog?.level"
                                    x-text="selectedLog?.level?.toUpperCase()"></span>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Time</div>
                                <div class="text-sm" x-text="formatDate(selectedLog?.occurred_at)"></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Channel</div>
                                <div class="text-sm" x-text="selectedLog?.channel"></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Environment</div>
                                <div class="text-sm" x-text="selectedLog?.environment"></div>
                            </div>
                        </div>

                        <div x-show="selectedLog?.source">
                            <div class="text-sm text-gray-500">Source</div>
                            <div class="text-sm font-mono bg-gray-100 px-2 py-1 rounded" x-text="selectedLog?.source + (selectedLog?.source_line ? ':' + selectedLog?.source_line : '')"></div>
                        </div>

                        <div>
                            <div class="text-sm text-gray-500 mb-1">Message</div>
                            <pre class="text-sm bg-gray-100 p-4 rounded overflow-x-auto whitespace-pre-wrap" x-text="selectedLog?.message"></pre>
                        </div>

                        <div x-show="selectedLog?.context && Object.keys(selectedLog?.context || {}).length > 0">
                            <div class="text-sm text-gray-500 mb-1">Context</div>
                            <pre class="text-sm bg-gray-100 p-4 rounded overflow-x-auto" x-text="JSON.stringify(selectedLog?.context, null, 2)"></pre>
                        </div>

                        <div x-show="selectedLog?.fingerprint">
                            <div class="text-sm text-gray-500">Fingerprint</div>
                            <button @click="filterByFingerprint(selectedLog?.fingerprint); selectedLog = null"
                                class="text-sm font-mono text-indigo-600 hover:underline" x-text="selectedLog?.fingerprint"></button>
                            <span class="text-xs text-gray-400 ml-2">(click to filter similar logs)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function logViewer() {
    return {
        logs: [],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        stats: {},
        loading: true,
        selectedLog: null,
        filters: {
            search: '',
            levels: [],
            channels: [],
            environments: [],
            from: '',
            to: '',
            fingerprint: ''
        },
        page: 1,

        async init() {
            await Promise.all([this.fetchLogs(), this.fetchStats()]);
        },

        async fetchLogs() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('page', this.page);

                if (this.filters.search) params.append('search', this.filters.search);
                if (this.filters.from) params.append('from', this.filters.from);
                if (this.filters.to) params.append('to', this.filters.to);
                if (this.filters.fingerprint) params.append('fingerprint', this.filters.fingerprint);

                this.filters.levels.forEach(l => params.append('levels[]', l));
                this.filters.channels.forEach(c => params.append('channels[]', c));
                this.filters.environments.forEach(e => params.append('environments[]', e));

                const response = await fetch(`{{ route('logscope.logs') }}?${params}`);
                const data = await response.json();

                this.logs = data.data;
                this.meta = data.meta;
            } catch (error) {
                console.error('Failed to fetch logs:', error);
            } finally {
                this.loading = false;
            }
        },

        async fetchStats() {
            try {
                const response = await fetch('{{ route('logscope.stats') }}');
                const data = await response.json();
                this.stats = data.data;
            } catch (error) {
                console.error('Failed to fetch stats:', error);
            }
        },

        async deleteLog(id) {
            if (!confirm('Delete this log entry?')) return;

            try {
                await fetch(`{{ url(config('logscope.routes.prefix', 'logscope')) }}/api/logs/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                await this.fetchLogs();
                await this.fetchStats();
            } catch (error) {
                console.error('Failed to delete log:', error);
            }
        },

        showDetail(log) {
            this.selectedLog = log;
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleString();
        },

        clearFilters() {
            this.filters = {
                search: '',
                levels: [],
                channels: [],
                environments: [],
                from: '',
                to: '',
                fingerprint: ''
            };
            this.page = 1;
            this.fetchLogs();
        },

        applyPreset(presetFilters) {
            this.filters = {
                search: presetFilters.search || '',
                levels: presetFilters.levels || [],
                channels: presetFilters.channels || [],
                environments: presetFilters.environments || [],
                from: presetFilters.from || '',
                to: presetFilters.to || '',
                fingerprint: presetFilters.fingerprint || ''
            };
            this.page = 1;
            this.fetchLogs();
        },

        filterByFingerprint(fingerprint) {
            this.filters.fingerprint = fingerprint;
            this.page = 1;
            this.fetchLogs();
        },

        prevPage() {
            if (this.meta.current_page > 1) {
                this.page = this.meta.current_page - 1;
                this.fetchLogs();
            }
        },

        nextPage() {
            if (this.meta.current_page < this.meta.last_page) {
                this.page = this.meta.current_page + 1;
                this.fetchLogs();
            }
        }
    }
}
</script>
@endsection
