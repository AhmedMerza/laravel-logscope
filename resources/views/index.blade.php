@extends('logscope::layout')

@section('content')
<div x-data="logScope()" x-init="init()" class="h-full flex" @keydown.escape.window="closePanel()">
    <!-- Sidebar -->
    <aside class="w-64 flex-shrink-0 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex flex-col"
        :class="{ 'hidden': !sidebarOpen }" x-cloak>
        <!-- Logo -->
        <div class="h-14 flex items-center gap-2 px-4 border-b border-gray-200 dark:border-gray-800">
            <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <span class="font-semibold text-gray-900 dark:text-white">LogScope</span>
        </div>

        <!-- Scrollable Filters -->
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <!-- Overview Section -->
            <div class="border-b border-gray-200 dark:border-gray-800">
                <button @click="sections.overview = !sections.overview"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <span>Overview</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.overview }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.overview" x-collapse class="px-4 pb-4">
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Total</span>
                            <span class="font-medium text-gray-900 dark:text-white" x-text="stats.total?.toLocaleString() || '0'"></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Today</span>
                            <span class="font-medium text-gray-900 dark:text-white" x-text="stats.today?.toLocaleString() || '0'"></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">This hour</span>
                            <span class="font-medium text-gray-900 dark:text-white" x-text="stats.this_hour?.toLocaleString() || '0'"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Severity Section -->
            <div class="border-b border-gray-200 dark:border-gray-800">
                <button @click="sections.severity = !sections.severity"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <span>Severity</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.severity }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.severity" x-collapse class="px-4 pb-4">
                    <div class="space-y-1">
                        @foreach(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level)
                        <button @click="toggleLevel('{{ $level }}')"
                            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                            :class="filters.levels.includes('{{ $level }}')
                                ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'">
                            <span class="level-{{ $level }} level-dot flex-shrink-0"></span>
                            <span class="flex-1 text-left capitalize">{{ $level }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-500 tabular-nums" x-text="stats.by_level?.{{ $level }} || 0"></span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if(count($channels) > 0)
            <!-- Channels Section -->
            <div class="border-b border-gray-200 dark:border-gray-800">
                <button @click="sections.channels = !sections.channels"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <span>Channels</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.channels }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.channels" x-collapse class="px-4 pb-4">
                    <div class="space-y-1">
                        @foreach($channels as $channel)
                        <button @click="toggleChannel('{{ $channel }}')"
                            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                            :class="filters.channels.includes('{{ $channel }}')
                                ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'">
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span class="flex-1 text-left">{{ $channel }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            @if(count($environments) > 0)
            <!-- Environment Section -->
            <div class="border-b border-gray-200 dark:border-gray-800">
                <button @click="sections.environments = !sections.environments"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <span>Environment</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.environments }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.environments" x-collapse class="px-4 pb-4">
                    <div class="space-y-1">
                        @foreach($environments as $env)
                        <button @click="toggleEnvironment('{{ $env }}')"
                            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                            :class="filters.environments.includes('{{ $env }}')
                                ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'">
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                            </svg>
                            <span class="flex-1 text-left">{{ $env }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>

        <!-- Theme Toggle -->
        <div class="p-4 border-t border-gray-200 dark:border-gray-800">
            <button @click="darkMode = !darkMode"
                class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
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

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
            <!-- Main header row -->
            <div class="h-14 flex items-center gap-4 px-4">
                <!-- Sidebar Toggle -->
                <button @click="sidebarOpen = !sidebarOpen"
                    class="p-1.5 rounded-md text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <!-- Primary Search -->
                <div class="flex-1 flex items-center gap-2">
                    <div class="flex-1 max-w-xl flex items-center gap-2">
                        <select x-model="searches[0].field"
                            class="h-9 px-2 bg-gray-100 dark:bg-gray-800 border-0 rounded-lg text-sm text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="any">Any field</option>
                            <option value="message">Message</option>
                            <option value="context">Context</option>
                            <option value="source">Source</option>
                        </select>
                        <div class="flex-1 relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input type="text" x-model.debounce.300ms="searches[0].value" @input="fetchLogs()"
                                placeholder="Search logs..."
                                class="w-full h-9 pl-9 pr-4 bg-gray-100 dark:bg-gray-800 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <!-- Add search button -->
                    <button @click="addSearch()"
                        class="h-9 w-9 flex items-center justify-center rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
                        title="Add search condition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </button>

                    <!-- AND/OR toggle (only show if multiple searches) -->
                    <div x-show="searches.length > 1" class="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5">
                        <button @click="searchMode = 'and'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                            :class="searchMode === 'and' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'">
                            AND
                        </button>
                        <button @click="searchMode = 'or'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                            :class="searchMode === 'or' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'">
                            OR
                        </button>
                    </div>
                </div>

                <!-- Date Range -->
                <div class="flex items-center gap-2">
                    <input type="datetime-local" x-model="filters.from" @change="fetchLogs()"
                        class="h-9 px-3 bg-gray-100 dark:bg-gray-800 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        title="From date">
                    <span class="text-gray-400">-</span>
                    <input type="datetime-local" x-model="filters.to" @change="fetchLogs()"
                        class="h-9 px-3 bg-gray-100 dark:bg-gray-800 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        title="To date">
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1">
                    <button @click="clearFilters()"
                        class="h-9 px-3 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        title="Clear filters">
                        Clear
                    </button>
                    <button @click="fetchLogs(); fetchStats()"
                        class="h-9 px-3 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <!-- Additional search rows -->
            <template x-for="(search, index) in searches.slice(1)" :key="index + 1">
                <div class="flex items-center gap-2 px-4 py-2 border-t border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30">
                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500 w-10" x-text="searchMode.toUpperCase()"></span>
                    <select x-model="searches[index + 1].field"
                        class="h-8 px-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md text-sm text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="any">Any field</option>
                        <option value="message">Message</option>
                        <option value="context">Context</option>
                        <option value="source">Source</option>
                    </select>
                    <input type="text" x-model.debounce.300ms="searches[index + 1].value" @input="fetchLogs()"
                        placeholder="Search..."
                        class="flex-1 h-8 px-3 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <button @click="removeSearch(index + 1)"
                        class="h-8 w-8 flex items-center justify-center rounded-md text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </template>
        </header>

        <!-- Active Filters Bar -->
        <div x-show="hasActiveFilters()" x-cloak
            class="flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-blue-100 dark:border-blue-900/30">
            <span class="text-xs font-medium text-blue-600 dark:text-blue-400">Active filters:</span>
            <div class="flex flex-wrap gap-1">
                <template x-for="level in filters.levels" :key="level">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-200">
                        <span x-text="level" class="capitalize"></span>
                        <button @click="toggleLevel(level)" class="hover:text-blue-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="channel in filters.channels" :key="channel">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-800 text-green-700 dark:text-green-200">
                        <span x-text="channel"></span>
                        <button @click="toggleChannel(channel)" class="hover:text-green-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="env in filters.environments" :key="env">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-800 text-purple-700 dark:text-purple-200">
                        <span x-text="env"></span>
                        <button @click="toggleEnvironment(env)" class="hover:text-purple-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="(search, idx) in searches.filter(s => s.value)" :key="'search-' + idx">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                        <span x-text="search.field === 'any' ? '' : search.field + ':'"></span>
                        <span x-text="search.value" class="max-w-[100px] truncate"></span>
                        <button @click="search.value = ''; fetchLogs()" class="hover:text-gray-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
            </div>
        </div>

        <!-- Content Area -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Log List -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Loading -->
                <div x-show="loading" class="flex-1 flex items-center justify-center">
                    <div class="flex items-center gap-3 text-gray-500 dark:text-gray-400">
                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm">Loading logs...</span>
                    </div>
                </div>

                <!-- Empty State -->
                <div x-show="!loading && logs.length === 0" x-cloak class="flex-1 flex items-center justify-center">
                    <div class="text-center">
                        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <p class="mt-4 text-sm font-medium text-gray-900 dark:text-gray-100">No logs found</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Try adjusting your filters</p>
                    </div>
                </div>

                <!-- Log Table -->
                <div x-show="!loading && logs.length > 0" x-cloak class="flex-1 overflow-auto custom-scrollbar">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900 z-10">
                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                <th class="w-[3px] p-0"></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-40">Time</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">Level</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Message</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-28">Channel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="log in logs" :key="log.id">
                                <tr class="log-row border-b border-gray-100 dark:border-gray-800/50 cursor-pointer"
                                    :class="{ 'selected': selectedLog?.id === log.id }"
                                    @click="selectLog(log)">
                                    <td class="p-0">
                                        <div class="level-indicator h-full" :class="'level-' + log.level"></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-sm text-gray-600 dark:text-gray-400 tabular-nums font-mono" x-text="formatTime(log.occurred_at)"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="level-badge" :class="'level-' + log.level" x-text="log.level"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate max-w-xl" x-text="log.message_preview || log.message"></p>
                                        <p x-show="log.source" class="mt-0.5 text-xs text-gray-400 dark:text-gray-500 truncate font-mono" x-text="formatSource(log.source, log.source_line)"></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" x-text="log.channel"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div x-show="!loading && logs.length > 0" x-cloak
                    class="flex items-center justify-between px-4 py-3 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Showing <span class="font-medium text-gray-900 dark:text-gray-100" x-text="((meta.current_page - 1) * meta.per_page) + 1"></span>
                        to <span class="font-medium text-gray-900 dark:text-gray-100" x-text="Math.min(meta.current_page * meta.per_page, meta.total)"></span>
                        of <span class="font-medium text-gray-900 dark:text-gray-100" x-text="meta.total?.toLocaleString()"></span>
                    </p>
                    <div class="flex items-center gap-1">
                        <button @click="prevPage()" :disabled="meta.current_page <= 1"
                            class="h-8 px-3 rounded text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <span class="px-2 text-sm text-gray-500 dark:text-gray-400">
                            <span x-text="meta.current_page"></span> / <span x-text="meta.last_page"></span>
                        </span>
                        <button @click="nextPage()" :disabled="meta.current_page >= meta.last_page"
                            class="h-8 px-3 rounded text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <!-- Detail Panel -->
            <div x-show="selectedLog" x-cloak
                class="w-[480px] flex-shrink-0 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col overflow-hidden"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-x-4"
                x-transition:enter-end="opacity-100 translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-x-0"
                x-transition:leave-end="opacity-0 translate-x-4">
                <!-- Panel Header -->
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Log Details</h3>
                    <button @click="closePanel()"
                        class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Panel Content -->
                <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-4">
                    <!-- Meta -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Level</p>
                            <p class="mt-1">
                                <span class="level-badge" :class="'level-' + selectedLog?.level" x-text="selectedLog?.level"></span>
                            </p>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Channel</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white" x-text="selectedLog?.channel || '-'"></p>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Environment</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white" x-text="selectedLog?.environment || '-'"></p>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Time</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white" x-text="formatDateTime(selectedLog?.occurred_at)"></p>
                        </div>
                    </div>

                    <!-- Source -->
                    <div x-show="selectedLog?.source">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Source</p>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 font-mono text-sm text-gray-700 dark:text-gray-300 break-all"
                            x-text="formatSource(selectedLog?.source, selectedLog?.source_line)"></div>
                    </div>

                    <!-- Message -->
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Message</p>
                        <pre class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-words overflow-x-auto"
                            x-text="selectedLog?.message"></pre>
                    </div>

                    <!-- Context -->
                    <div x-show="selectedLog?.context && Object.keys(selectedLog?.context || {}).length > 0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Context</p>
                        <pre class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-300 overflow-x-auto font-mono"
                            x-text="JSON.stringify(selectedLog?.context, null, 2)"></pre>
                    </div>

                    <!-- Fingerprint -->
                    <div x-show="selectedLog?.fingerprint">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Fingerprint</p>
                        <button @click="filterByFingerprint(selectedLog?.fingerprint)"
                            class="w-full p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-sm font-mono text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/30 text-left break-all transition-colors">
                            <span x-text="selectedLog?.fingerprint"></span>
                            <span class="block mt-1 text-xs text-blue-500 dark:text-blue-500">Click to find similar logs</span>
                        </button>
                    </div>
                </div>

                <!-- Panel Footer -->
                <div class="flex items-center gap-2 px-4 py-3 border-t border-gray-200 dark:border-gray-800">
                    <button @click="deleteLog(selectedLog?.id)"
                        class="flex-1 h-9 rounded-lg text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                        Delete
                    </button>
                    <button @click="closePanel()"
                        class="flex-1 h-9 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function logScope() {
    return {
        sidebarOpen: true,
        logs: [],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        stats: {},
        loading: true,
        selectedLog: null,
        searches: [{ field: 'any', value: '' }],
        searchMode: 'and',
        filters: {
            levels: [],
            channels: [],
            environments: [],
            from: '',
            to: '',
            fingerprint: ''
        },
        sections: {
            overview: JSON.parse(localStorage.getItem('logscope-section-overview') ?? 'true'),
            severity: JSON.parse(localStorage.getItem('logscope-section-severity') ?? 'true'),
            channels: JSON.parse(localStorage.getItem('logscope-section-channels') ?? 'true'),
            environments: JSON.parse(localStorage.getItem('logscope-section-environments') ?? 'true'),
        },
        page: 1,

        async init() {
            // Watch section states and persist to localStorage
            this.$watch('sections.overview', val => localStorage.setItem('logscope-section-overview', JSON.stringify(val)));
            this.$watch('sections.severity', val => localStorage.setItem('logscope-section-severity', JSON.stringify(val)));
            this.$watch('sections.channels', val => localStorage.setItem('logscope-section-channels', JSON.stringify(val)));
            this.$watch('sections.environments', val => localStorage.setItem('logscope-section-environments', JSON.stringify(val)));

            await Promise.all([this.fetchLogs(), this.fetchStats()]);
        },

        addSearch() {
            this.searches.push({ field: 'any', value: '' });
        },

        removeSearch(index) {
            this.searches.splice(index, 1);
            this.fetchLogs();
        },

        hasActiveFilters() {
            return this.filters.levels.length > 0 ||
                this.filters.channels.length > 0 ||
                this.filters.environments.length > 0 ||
                this.searches.some(s => s.value) ||
                this.filters.from ||
                this.filters.to ||
                this.filters.fingerprint;
        },

        toggleLevel(level) {
            const i = this.filters.levels.indexOf(level);
            i === -1 ? this.filters.levels.push(level) : this.filters.levels.splice(i, 1);
            this.fetchLogs();
        },

        toggleChannel(channel) {
            const i = this.filters.channels.indexOf(channel);
            i === -1 ? this.filters.channels.push(channel) : this.filters.channels.splice(i, 1);
            this.fetchLogs();
        },

        toggleEnvironment(env) {
            const i = this.filters.environments.indexOf(env);
            i === -1 ? this.filters.environments.push(env) : this.filters.environments.splice(i, 1);
            this.fetchLogs();
        },

        async fetchLogs() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('page', this.page);
                if (this.filters.from) params.append('from', this.filters.from);
                if (this.filters.to) params.append('to', this.filters.to);
                if (this.filters.fingerprint) params.append('fingerprint', this.filters.fingerprint);
                this.filters.levels.forEach(l => params.append('levels[]', l));
                this.filters.channels.forEach(c => params.append('channels[]', c));
                this.filters.environments.forEach(e => params.append('environments[]', e));

                // Add advanced search params
                const activeSearches = this.searches.filter(s => s.value);
                if (activeSearches.length > 0) {
                    activeSearches.forEach((s, i) => {
                        params.append(`searches[${i}][field]`, s.field);
                        params.append(`searches[${i}][value]`, s.value);
                    });
                    params.append('search_mode', this.searchMode);
                }

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
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                });
                this.selectedLog = null;
                await Promise.all([this.fetchLogs(), this.fetchStats()]);
            } catch (error) {
                console.error('Failed to delete log:', error);
            }
        },

        selectLog(log) {
            this.selectedLog = this.selectedLog?.id === log.id ? null : log;
        },

        closePanel() {
            this.selectedLog = null;
        },

        formatTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        },

        formatDateTime(dateStr) {
            if (!dateStr) return '';
            return new Date(dateStr).toLocaleString();
        },

        formatSource(source, line) {
            if (!source) return '';
            const parts = source.split('/');
            const short = parts.length > 2 ? '.../' + parts.slice(-2).join('/') : source;
            return line ? `${short}:${line}` : short;
        },

        clearFilters() {
            this.searches = [{ field: 'any', value: '' }];
            this.searchMode = 'and';
            this.filters = { levels: [], channels: [], environments: [], from: '', to: '', fingerprint: '' };
            this.page = 1;
            this.fetchLogs();
        },

        filterByFingerprint(fingerprint) {
            this.filters.fingerprint = fingerprint;
            this.page = 1;
            this.selectedLog = null;
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
