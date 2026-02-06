<!-- Header -->
<header class="surface-1 border-b border-[var(--border)]">
    <!-- Main header row -->
    <div class="h-14 flex items-center gap-4 px-4">
        <!-- Sidebar Toggle -->
        <button @click="sidebarOpen = !sidebarOpen"
            class="p-1.5 rounded-md text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <!-- Primary Search -->
        <div class="flex-1 flex items-center gap-2 min-w-0">
            <!-- Search input group -->
            <div class="flex-1 flex items-center gap-2 min-w-0">
                <select x-model="searches[0].field"
                    class="h-9 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded-lg text-sm text-[var(--text-secondary)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)] flex-shrink-0 font-mono">
                    <option value="any">Any field</option>
                    <option value="message">Message</option>
                    <option value="context">Context</option>
                    <option value="source">Source</option>
                </select>
                <button @click="searches[0].exclude = !searches[0].exclude; fetchLogs()"
                    class="h-9 px-2 rounded-lg text-xs font-bold font-mono transition-colors border flex-shrink-0"
                    :class="searches[0].exclude
                        ? 'bg-red-500/20 text-red-400 border-red-500/50 shadow-[0_0_10px_rgba(239,68,68,0.2)]'
                        : 'bg-[var(--surface-2)] text-[var(--text-muted)] border-[var(--border)] hover:text-[var(--text-primary)] hover:border-[var(--text-muted)]'"
                    title="Toggle NOT (exclude matching)">
                    NOT
                </button>
                <button x-show="features.regex" @click="useRegex = !useRegex; fetchLogs()"
                    class="h-9 px-2 rounded-lg text-xs font-bold font-mono transition-colors border flex-shrink-0"
                    :class="useRegex
                        ? 'bg-violet-500/20 text-violet-400 border-violet-500/50 shadow-[0_0_10px_rgba(139,92,246,0.2)]'
                        : 'bg-[var(--surface-2)] text-[var(--text-muted)] border-[var(--border)] hover:text-[var(--text-primary)] hover:border-[var(--text-muted)]'"
                    title="Toggle regex mode">
                    .*
                </button>
                <div class="flex-1 relative min-w-[200px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        :class="searches[0].exclude ? 'text-red-400' : 'text-[var(--text-muted)]'">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" x-model="searches[0].value" @input.debounce.300ms="fetchLogs()"
                        x-ref="searchInput"
                        :placeholder="useRegex ? 'Regex pattern...' : (searches[0].exclude ? 'Exclude logs containing...' : (features.search_syntax ? 'Search... (try field:value)' : 'Search logs...'))"
                        class="search-input w-full h-9 pl-9 pr-4 border rounded-lg text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2"
                        :class="searches[0].exclude
                            ? 'bg-red-500/10 border-red-500/30 focus:ring-red-500/50 focus:border-red-500'
                            : (useRegex ? 'bg-violet-500/10 border-violet-500/30 focus:ring-violet-500/50 focus:border-violet-500' : 'bg-[var(--surface-2)] border-[var(--border)] focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]')">
                </div>
                <!-- Add search button -->
                <button @click="addSearch()"
                    class="h-9 w-9 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:text-[var(--accent)] hover:bg-[var(--surface-2)] flex-shrink-0 transition-colors"
                    title="Add search condition (Ctrl+Shift+F)">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </button>
            </div>

            <!-- AND/OR toggle (only show if multiple searches) -->
            <div x-show="searches.length > 1" x-cloak class="flex items-center bg-[var(--surface-2)] rounded-lg p-0.5 flex-shrink-0">
                <button @click="searchMode = 'and'"
                    class="px-3 py-1.5 rounded-md text-xs font-bold font-mono transition-colors"
                    :class="searchMode === 'and' ? 'bg-[var(--accent)] text-white shadow-lg shadow-[var(--accent-glow)]' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'">
                    AND
                </button>
                <button @click="searchMode = 'or'"
                    class="px-3 py-1.5 rounded-md text-xs font-bold font-mono transition-colors"
                    :class="searchMode === 'or' ? 'bg-[var(--accent)] text-white shadow-lg shadow-[var(--accent-glow)]' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'">
                    OR
                </button>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-1 flex-shrink-0">
            <!-- Date Range Dropdown -->
            <div class="relative" x-data="{ dateOpen: false }" @click.away="dateOpen = false">
                <button @click="dateOpen = !dateOpen"
                    class="h-9 w-9 flex items-center justify-center rounded-lg transition-colors"
                    :class="(filters.from || filters.to)
                        ? 'bg-[rgba(var(--accent-rgb),0.2)] text-[var(--accent)] shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                        : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)]'"
                    title="Date range filter">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </button>
                <div x-show="dateOpen" x-cloak
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 mt-2 w-72 glass-panel rounded-lg shadow-xl p-4 z-50">
                    <div class="space-y-3">
                        <div class="section-header">Date Range</div>
                        <div class="space-y-2">
                            <div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-[var(--text-secondary)]">From</span>
                                    <button x-show="filters.from" @click.stop="filters.from = ''; fetchLogs()"
                                        class="text-xs text-[var(--text-muted)] hover:text-red-400 transition-colors"
                                        type="button">
                                        clear
                                    </button>
                                </div>
                                <input type="datetime-local"
                                    x-model="filters.from"
                                    @change="fetchLogs()"
                                    class="mt-1 w-full h-9 px-3 bg-[var(--surface-2)] border border-[var(--border)] rounded-lg text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                            </div>
                            <div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-[var(--text-secondary)]">To</span>
                                    <button x-show="filters.to" @click.stop="filters.to = ''; fetchLogs()"
                                        class="text-xs text-[var(--text-muted)] hover:text-red-400 transition-colors"
                                        type="button">
                                        clear
                                    </button>
                                </div>
                                <input type="datetime-local"
                                    x-model="filters.to"
                                    @change="fetchLogs()"
                                    class="mt-1 w-full h-9 px-3 bg-[var(--surface-2)] border border-[var(--border)] rounded-lg text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                            </div>
                        </div>
                        <button @click.stop="filters.from = ''; filters.to = ''; fetchLogs(); dateOpen = false"
                            type="button"
                            class="w-full h-8 rounded-lg text-xs font-medium transition-colors text-[var(--text-secondary)] hover:bg-red-500/10 hover:text-red-400">
                            Clear all dates
                        </button>
                    </div>
                </div>
            </div>

            <!-- Status Filter -->
            <template x-if="features.status">
                <div class="relative" x-data="{ statusFilterOpen: false }">
                    <button @click="statusFilterOpen = !statusFilterOpen"
                        class="h-9 px-3 flex items-center gap-2 rounded-lg text-sm transition-colors"
                        :class="filters.statuses.length > 0
                            ? 'bg-[rgba(var(--accent-rgb),0.2)] text-[var(--accent)] shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                            : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)]'">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span x-text="getStatusFilterLabel()" class="font-medium"></span>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="statusFilterOpen" @click.away="statusFilterOpen = false" x-transition
                        class="absolute top-full right-0 mt-1 w-48 glass-panel rounded-lg shadow-xl overflow-hidden z-50">
                        <!-- Default: Open only -->
                        <button @click="filters.statuses = []; fetchLogs(); statusFilterOpen = false"
                            class="w-full px-3 py-2 text-sm text-left hover:bg-[var(--surface-2)] flex items-center gap-2"
                            :class="{ 'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)]': filters.statuses.length === 0 }">
                            <span class="w-2 h-2 rounded-full bg-[var(--text-muted)]"></span>
                            <span class="text-[var(--text-secondary)]">Open</span>
                            <span class="ml-auto text-xs text-[var(--text-muted)]">(default)</span>
                        </button>
                        <!-- Needs Attention: All non-closed statuses -->
                        <button @click="filters.statuses = getNeedsAttentionStatuses(); fetchLogs(); statusFilterOpen = false"
                            class="w-full px-3 py-2 text-sm text-left hover:bg-[var(--surface-2)] flex items-center gap-2"
                            :class="{ 'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)]': isNeedsAttentionFilter() }">
                            <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                            <span class="text-[var(--text-secondary)]">Needs Attention</span>
                        </button>
                        <div class="border-t border-[var(--border)]"></div>
                        <template x-for="status in statuses" :key="status.value">
                            <button @click="toggleStatusFilter(status.value)"
                                class="w-full px-3 py-2 text-sm text-left hover:bg-[var(--surface-2)] flex items-center gap-2"
                                :class="{ 'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)]': filters.statuses.includes(status.value) }">
                                <span class="w-2 h-2 rounded-full" :class="'bg-' + status.color + '-500'"></span>
                                <span x-text="status.label" class="text-[var(--text-secondary)]"></span>
                                <svg x-show="filters.statuses.includes(status.value)" class="w-4 h-4 ml-auto text-[var(--accent)]" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </template>
                        <div class="border-t border-[var(--border)]"></div>
                        <button @click="toggleShowAll(); statusFilterOpen = false"
                            class="w-full px-3 py-2 text-sm text-left hover:bg-[var(--surface-2)] text-[var(--text-secondary)]">
                            <span x-text="filters.statuses.length === statuses.length ? 'Reset to Open' : 'Show All'"></span>
                        </button>
                    </div>
                </div>
            </template>

            <!-- Keyboard shortcuts -->
            <button @click="showKeyboardHelp = true"
                class="h-9 w-9 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors"
                title="Keyboard shortcuts (?)">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </button>

            <!-- Clear -->
            <button @click="clearFilters()"
                class="btn-ghost h-9 px-3 rounded-lg text-sm font-medium"
                title="Clear all filters">
                Clear
            </button>

            <!-- Refresh -->
            <button @click="fetchLogs(); fetchStats()"
                class="btn-primary h-9 px-4 rounded-lg text-sm flex items-center gap-2">
                <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </button>
        </div>
    </div>

    <!-- Additional search rows -->
    <template x-for="(search, index) in searches.slice(1)" :key="index + 1">
        <div class="flex items-center gap-2 px-4 py-2 border-t border-[var(--border)] bg-[var(--surface-0)]/50">
            <span class="text-xs font-bold font-mono text-[var(--accent)] w-10" x-text="searchMode.toUpperCase()"></span>
            <select x-model="searches[index + 1].field"
                class="h-8 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded-md text-sm text-[var(--text-secondary)] font-mono focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                <option value="any">Any field</option>
                <option value="message">Message</option>
                <option value="context">Context</option>
                <option value="source">Source</option>
            </select>
            <button @click="searches[index + 1].exclude = !searches[index + 1].exclude; fetchLogs()"
                class="h-8 px-2 rounded-md text-xs font-bold font-mono transition-colors border"
                :class="searches[index + 1].exclude
                    ? 'bg-red-500/20 text-red-400 border-red-500/50'
                    : 'bg-[var(--surface-2)] text-[var(--text-muted)] border-[var(--border)] hover:text-[var(--text-primary)] hover:border-[var(--text-muted)]'"
                title="Toggle NOT (exclude matching)">
                NOT
            </button>
            <input type="text" x-model="searches[index + 1].value" @input.debounce.300ms="fetchLogs()"
                :placeholder="searches[index + 1].exclude ? 'Exclude...' : 'Search...'"
                class="search-input flex-1 h-8 px-3 border rounded-md text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2"
                :class="searches[index + 1].exclude
                    ? 'bg-red-500/10 border-red-500/30 focus:ring-red-500/50 focus:border-red-500'
                    : 'bg-[var(--surface-2)] border-[var(--border)] focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]'">
            <button @click="removeSearch(index + 1)"
                class="h-8 w-8 flex items-center justify-center rounded-md text-[var(--text-muted)] hover:text-red-400 hover:bg-red-500/10 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</header>
