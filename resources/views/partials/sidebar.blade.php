<!-- Sidebar -->
<aside class="w-64 flex-shrink-0 surface-1 border-r border-[var(--border)] flex flex-col"
    :class="{ 'hidden': !sidebarOpen }" x-cloak>
    <!-- Logo -->
    <div class="h-14 flex items-center gap-3 px-4 border-b border-[var(--border)]">
        <div class="w-8 h-8 rounded-lg bg-[var(--accent)] flex items-center justify-center shadow-lg shadow-[var(--accent-glow)]">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <span class="font-semibold text-[var(--text-primary)] tracking-tight">LogScope</span>
    </div>

    <!-- Scrollable Filters -->
    <div class="flex-1 overflow-y-auto custom-scrollbar">
        <!-- Quick Filters Section -->
        <div class="border-b border-[var(--border)]">
            <button @click="sections.quickFilters = !sections.quickFilters"
                class="section-header w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--surface-2)] transition-colors">
                <span>Quick Filters</span>
                <svg class="w-4 h-4 transition-transform text-[var(--text-muted)]" :class="{ 'rotate-180': !sections.quickFilters }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="sections.quickFilters" x-collapse class="px-4 pb-4">
                <div class="space-y-1">
                    <!-- Quick Filters from Config -->
                    @foreach($quickFilters as $index => $filter)
                    <button @click="applyQuickFilter({{ $index }})"
                        class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-2)] hover:text-[var(--text-primary)] transition-colors">
                        @php $icon = $filter['icon'] ?? 'filter'; @endphp
                        @if($icon === 'calendar')
                        <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        @elseif($icon === 'clock')
                        <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        @elseif($icon === 'alert')
                        <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        @else
                        <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>
                        @endif
                        <span>{{ $filter['label'] }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Severity Section -->
        <div class="border-b border-[var(--border)]">
            <button @click="sections.severity = !sections.severity"
                class="section-header w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--surface-2)] transition-colors">
                <span>Severity</span>
                <svg class="w-4 h-4 transition-transform text-[var(--text-muted)]" :class="{ 'rotate-180': !sections.severity }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="sections.severity" x-collapse class="px-4 pb-4">
                <div class="space-y-1">
                    @foreach(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level)
                    <button @click="toggleLevel('{{ $level }}')"
                        class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                        :class="{
                            'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)] ring-1 ring-[rgba(var(--accent-rgb),0.3)]': filters.levels.includes('{{ $level }}'),
                            'bg-red-500/10 text-red-400 ring-1 ring-red-500/30 line-through': filters.excludeLevels.includes('{{ $level }}'),
                            'text-[var(--text-secondary)] hover:bg-[var(--surface-2)] hover:text-[var(--text-primary)]': !filters.levels.includes('{{ $level }}') && !filters.excludeLevels.includes('{{ $level }}')
                        }">
                        <span class="level-{{ $level }} level-dot flex-shrink-0"></span>
                        <span class="flex-1 text-left capitalize">{{ $level }}</span>
                        <span class="text-xs tabular-nums font-mono"
                            :class="filters.excludeLevels.includes('{{ $level }}') ? 'text-red-400' : 'text-[var(--text-muted)]'"
                            x-text="stats.by_level?.{{ $level }} || 0"></span>
                    </button>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Channels Section -->
        <div class="border-b border-[var(--border)]" x-show="allChannels.length > 0">
            <button @click="sections.channels = !sections.channels"
                class="section-header w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--surface-2)] transition-colors">
                <span>Channels</span>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-[var(--text-muted)] tabular-nums" x-text="allChannels.length"></span>
                    <svg class="w-4 h-4 transition-transform text-[var(--text-muted)]" :class="{ 'rotate-180': !sections.channels }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </button>
            <div x-show="sections.channels" x-collapse class="px-4 pb-4">
                <!-- Search Input -->
                <div class="mb-2" x-show="allChannels.length > channelsVisibleLimit">
                    <div class="relative">
                        <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text"
                            x-model="channelSearch"
                            placeholder="Search channels..."
                            class="w-full h-7 pl-7 pr-2 bg-[var(--surface-2)] border border-[var(--border)] rounded text-xs text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-1 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                        <button x-show="channelSearch"
                            @click="channelSearch = ''"
                            class="absolute right-1.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-primary)]">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Channel List -->
                <div class="space-y-1">
                    <template x-for="channel in getVisibleChannels()" :key="channel">
                        <button @click="toggleChannel(channel)"
                            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                            :class="{
                                'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)] ring-1 ring-[rgba(var(--accent-rgb),0.3)]': filters.channels.includes(channel),
                                'bg-red-500/10 text-red-400 ring-1 ring-red-500/30 line-through': filters.excludeChannels.includes(channel),
                                'text-[var(--text-secondary)] hover:bg-[var(--surface-2)] hover:text-[var(--text-primary)]': !filters.channels.includes(channel) && !filters.excludeChannels.includes(channel)
                            }">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                :class="filters.excludeChannels.includes(channel) ? 'text-red-400' : 'text-[var(--text-muted)]'">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span class="flex-1 text-left truncate font-mono text-xs" :title="channel" x-text="channel"></span>
                        </button>
                    </template>
                </div>

                <!-- Show More / Show Less -->
                <div x-show="!channelSearch && getHiddenChannelsCount() > 0" class="mt-2">
                    <button @click="showAllChannels = !showAllChannels"
                        class="w-full flex items-center justify-center gap-1 px-2 py-1 rounded text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors">
                        <span x-text="showAllChannels ? 'Show less' : 'Show ' + getHiddenChannelsCount() + ' more'"></span>
                        <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': showAllChannels }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                </div>

                <!-- No Results -->
                <div x-show="channelSearch && getFilteredChannels().length === 0" class="py-2 text-center text-xs text-[var(--text-muted)]">
                    No channels match "<span x-text="channelSearch"></span>"
                </div>
            </div>
        </div>

        @if(count($httpMethods) > 0)
        <!-- HTTP Method Section -->
        <div class="border-b border-[var(--border)]">
            <button @click="sections.httpMethods = !sections.httpMethods"
                class="section-header w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--surface-2)] transition-colors">
                <span>HTTP Method</span>
                <svg class="w-4 h-4 transition-transform text-[var(--text-muted)]" :class="{ 'rotate-180': !sections.httpMethods }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="sections.httpMethods" x-collapse class="px-4 pb-4">
                <div class="space-y-1">
                    @foreach($httpMethods as $method)
                    <button @click="toggleHttpMethod('{{ $method }}')"
                        class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                        :class="{
                            'bg-[rgba(var(--accent-rgb),0.1)] text-[var(--accent)] ring-1 ring-[rgba(var(--accent-rgb),0.3)]': filters.httpMethods.includes('{{ $method }}'),
                            'bg-red-500/10 text-red-400 ring-1 ring-red-500/30 line-through': filters.excludeHttpMethods.includes('{{ $method }}'),
                            'text-[var(--text-secondary)] hover:bg-[var(--surface-2)] hover:text-[var(--text-primary)]': !filters.httpMethods.includes('{{ $method }}') && !filters.excludeHttpMethods.includes('{{ $method }}')
                        }">
                        <span class="w-4 h-4 flex items-center justify-center text-xs font-bold font-mono"
                            :class="filters.excludeHttpMethods.includes('{{ $method }}') ? 'text-red-400' : 'text-[var(--text-muted)]'">{{ substr($method, 0, 1) }}</span>
                        <span class="flex-1 text-left font-mono">{{ $method }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <!-- Request Context Section -->
        <div class="border-b border-[var(--border)]">
            <button @click="sections.request = !sections.request"
                class="section-header w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--surface-2)] transition-colors">
                <span>Request</span>
                <svg class="w-4 h-4 transition-transform text-[var(--text-muted)]" :class="{ 'rotate-180': !sections.request }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="sections.request" x-collapse class="px-4 pb-4 space-y-3">
                <div>
                    <label class="block text-xs text-[var(--text-muted)] mb-1 font-mono uppercase tracking-wider">Trace ID</label>
                    <input type="text" x-model="filters.trace_id" @input.debounce.300ms="page = 1; fetchLogs()"
                        placeholder="Filter by trace..."
                        class="search-input w-full h-8 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                </div>
                <div>
                    <label class="block text-xs text-[var(--text-muted)] mb-1 font-mono uppercase tracking-wider">User ID</label>
                    <input type="text" x-model="filters.user_id" @input.debounce.300ms="page = 1; fetchLogs()"
                        placeholder="Filter by user..."
                        class="search-input w-full h-8 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                </div>
                <div>
                    <label class="block text-xs text-[var(--text-muted)] mb-1 font-mono uppercase tracking-wider">IP Address</label>
                    <input type="text" x-model="filters.ip_address" @input.debounce.300ms="page = 1; fetchLogs()"
                        placeholder="Filter by IP..."
                        class="search-input w-full h-8 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                </div>
                <div>
                    <label class="block text-xs text-[var(--text-muted)] mb-1 font-mono uppercase tracking-wider">URL</label>
                    <input type="text" x-model="filters.url" @input.debounce.300ms="page = 1; fetchLogs()"
                        placeholder="Filter by URL..."
                        class="search-input w-full h-8 px-2 bg-[var(--surface-2)] border border-[var(--border)] rounded text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)]">
                </div>
            </div>
        </div>
    </div>

    <!-- Theme Toggle -->
    <div class="p-4 border-t border-[var(--border)]">
        <button @click="darkMode = !darkMode"
            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-2)] hover:text-[var(--text-primary)] transition-colors">
            <svg x-show="!darkMode" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg x-show="darkMode" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span x-text="darkMode ? 'Light mode' : 'Dark mode'"></span>
        </button>
    </div>
</aside>
