@extends('logscope::layout')

@section('content')
<div x-data="logScope()" x-init="init()" class="h-full flex"
    @keydown.escape.window="closePanel()"
    @keydown.window="handleKeydown($event)">
    <!-- Sidebar -->
    <aside class="w-64 flex-shrink-0 bg-slate-100 dark:bg-slate-850 border-r border-gray-200 dark:border-slate-600 flex flex-col"
        :class="{ 'hidden': !sidebarOpen }" x-cloak>
        <!-- Logo -->
        <div class="h-14 flex items-center gap-2 px-4 border-b border-gray-200 dark:border-slate-600">
            <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <span class="font-semibold text-gray-900 dark:text-white">LogScope</span>
        </div>

        <!-- Scrollable Filters -->
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <!-- Quick Filters Section -->
            <div class="border-b border-gray-200 dark:border-slate-600">
                <button @click="sections.quickFilters = !sections.quickFilters"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-slate-500/50">
                    <span>Quick Filters</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.quickFilters }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.quickFilters" x-collapse class="px-4 pb-4">
                    <div class="space-y-1">
                        <!-- Quick Filters from Config -->
                        @foreach($quickFilters as $index => $filter)
                        <button @click="applyQuickFilter({{ $index }})"
                            class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500 transition-colors">
                            @php $icon = $filter['icon'] ?? 'filter'; @endphp
                            @if($icon === 'calendar')
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            @elseif($icon === 'clock')
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            @elseif($icon === 'alert')
                            <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            @else
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
            <div class="border-b border-gray-200 dark:border-slate-600">
                <button @click="sections.severity = !sections.severity"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-slate-500/50">
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
                            :class="{
                                'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300': filters.levels.includes('{{ $level }}'),
                                'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 line-through': filters.excludeLevels.includes('{{ $level }}'),
                                'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500': !filters.levels.includes('{{ $level }}') && !filters.excludeLevels.includes('{{ $level }}')
                            }">
                            <span class="level-{{ $level }} level-dot flex-shrink-0"></span>
                            <span class="flex-1 text-left capitalize">{{ $level }}</span>
                            <span class="text-xs tabular-nums"
                                :class="filters.excludeLevels.includes('{{ $level }}') ? 'text-red-400 dark:text-red-500' : 'text-gray-400 dark:text-gray-500'"
                                x-text="stats.by_level?.{{ $level }} || 0"></span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if(count($channels) > 0)
            <!-- Channels Section -->
            <div class="border-b border-gray-200 dark:border-slate-600">
                <button @click="sections.channels = !sections.channels"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-slate-500/50">
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
                            :class="{
                                'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300': filters.channels.includes('{{ $channel }}'),
                                'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 line-through': filters.excludeChannels.includes('{{ $channel }}'),
                                'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500': !filters.channels.includes('{{ $channel }}') && !filters.excludeChannels.includes('{{ $channel }}')
                            }">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                :class="filters.excludeChannels.includes('{{ $channel }}') ? 'text-red-400' : 'text-gray-400'">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span class="flex-1 text-left truncate" title="{{ $channel }}">{{ $channel }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            @if(count($httpMethods) > 0)
            <!-- HTTP Method Section -->
            <div class="border-b border-gray-200 dark:border-slate-600">
                <button @click="sections.httpMethods = !sections.httpMethods"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-slate-500/50">
                    <span>HTTP Method</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.httpMethods }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.httpMethods" x-collapse class="px-4 pb-4">
                    <div class="space-y-1">
                        @foreach($httpMethods as $method)
                        <button @click="toggleHttpMethod('{{ $method }}')"
                            class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm transition-colors"
                            :class="{
                                'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300': filters.httpMethods.includes('{{ $method }}'),
                                'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 line-through': filters.excludeHttpMethods.includes('{{ $method }}'),
                                'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500': !filters.httpMethods.includes('{{ $method }}') && !filters.excludeHttpMethods.includes('{{ $method }}')
                            }">
                            <span class="w-4 h-4 flex items-center justify-center text-xs font-bold"
                                :class="filters.excludeHttpMethods.includes('{{ $method }}') ? 'text-red-400' : 'text-gray-400'">{{ substr($method, 0, 1) }}</span>
                            <span class="flex-1 text-left">{{ $method }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Request Context Section -->
            <div class="border-b border-gray-200 dark:border-slate-600">
                <button @click="sections.request = !sections.request"
                    class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hover:bg-gray-50 dark:hover:bg-slate-500/50">
                    <span>Request</span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': !sections.request }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="sections.request" x-collapse class="px-4 pb-4 space-y-3">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Trace ID</label>
                        <input type="text" x-model="filters.trace_id" @input.debounce.300ms="fetchLogs()"
                            placeholder="Filter by trace..."
                            class="w-full h-8 px-2 bg-gray-100 dark:bg-slate-600 border-0 rounded text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">User ID</label>
                        <input type="text" x-model="filters.user_id" @input.debounce.300ms="fetchLogs()"
                            placeholder="Filter by user..."
                            class="w-full h-8 px-2 bg-gray-100 dark:bg-slate-600 border-0 rounded text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">IP Address</label>
                        <input type="text" x-model="filters.ip_address" @input.debounce.300ms="fetchLogs()"
                            placeholder="Filter by IP..."
                            class="w-full h-8 px-2 bg-gray-100 dark:bg-slate-600 border-0 rounded text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">URL</label>
                        <input type="text" x-model="filters.url" @input.debounce.300ms="fetchLogs()"
                            placeholder="Filter by URL..."
                            class="w-full h-8 px-2 bg-gray-100 dark:bg-slate-600 border-0 rounded text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
            </div>
        </div>

        <!-- Theme Toggle -->
        <div class="p-4 border-t border-gray-200 dark:border-slate-600">
            <button @click="darkMode = !darkMode"
                class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500 transition-colors">
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
        <header class="bg-slate-100 dark:bg-slate-850 border-b border-gray-200 dark:border-slate-600">
            <!-- Main header row -->
            <div class="h-14 flex items-center gap-4 px-4">
                <!-- Sidebar Toggle -->
                <button @click="sidebarOpen = !sidebarOpen"
                    class="p-1.5 rounded-md text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <!-- Primary Search -->
                <div class="flex-1 flex items-center gap-2 min-w-0">
                    <!-- Search input group -->
                    <div class="flex-1 flex items-center gap-2 min-w-0">
                        <select x-model="searches[0].field"
                            class="h-9 px-2 bg-gray-100 dark:bg-slate-600 border-0 rounded-lg text-sm text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 flex-shrink-0">
                            <option value="any">Any field</option>
                            <option value="message">Message</option>
                            <option value="context">Context</option>
                            <option value="source">Source</option>
                        </select>
                        <button @click="searches[0].exclude = !searches[0].exclude; fetchLogs()"
                            class="h-9 px-2 rounded-lg text-xs font-bold transition-colors border flex-shrink-0"
                            :class="searches[0].exclude
                                ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border-red-300 dark:border-red-700'
                                : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-gray-400 border-gray-300 dark:border-slate-500 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-400 dark:hover:border-slate-400'"
                            title="Toggle NOT (exclude matching)">
                            NOT
                        </button>
                        <div class="flex-1 relative min-w-[200px]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                :class="searches[0].exclude ? 'text-red-400' : 'text-gray-400'">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input type="text" x-model="searches[0].value" @input.debounce.300ms="fetchLogs()"
                                x-ref="searchInput"
                                :placeholder="searches[0].exclude ? 'Exclude logs containing...' : 'Search logs...'"
                                class="w-full h-9 pl-9 pr-4 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2"
                                :class="searches[0].exclude
                                    ? 'bg-red-50 dark:bg-red-900/20 focus:ring-red-500'
                                    : 'bg-gray-100 dark:bg-slate-600 focus:ring-blue-500'">
                        </div>
                        <!-- Add search button -->
                        <button @click="addSearch()"
                            class="h-9 w-9 flex items-center justify-center rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500 flex-shrink-0"
                            title="Add search condition (Ctrl+Shift+F)">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                        </button>
                    </div>

                    <!-- AND/OR toggle (only show if multiple searches) -->
                    <div x-show="searches.length > 1" x-cloak class="flex items-center bg-gray-100 dark:bg-slate-600 rounded-lg p-0.5 flex-shrink-0">
                        <button @click="searchMode = 'and'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                            :class="searchMode === 'and' ? 'bg-slate-100 dark:bg-slate-500 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'">
                            AND
                        </button>
                        <button @click="searchMode = 'or'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                            :class="searchMode === 'or' ? 'bg-slate-100 dark:bg-slate-500 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'">
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
                                ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400'
                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500'"
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
                            class="absolute right-0 mt-2 w-72 bg-white dark:bg-slate-700 rounded-lg shadow-lg border border-gray-200 dark:border-slate-600 p-4 z-50">
                            <div class="space-y-3">
                                <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Date Range</div>
                                <div class="space-y-2">
                                    <label class="block">
                                        <span class="text-xs text-gray-600 dark:text-gray-300">From</span>
                                        <input type="datetime-local" x-model="filters.from" @change="fetchLogs()"
                                            class="mt-1 w-full h-9 px-3 bg-gray-100 dark:bg-slate-600 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs text-gray-600 dark:text-gray-300">To</span>
                                        <input type="datetime-local" x-model="filters.to" @change="fetchLogs()"
                                            class="mt-1 w-full h-9 px-3 bg-gray-100 dark:bg-slate-600 border-0 rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </label>
                                </div>
                                <button @click="filters.from = ''; filters.to = ''; fetchLogs(); dateOpen = false"
                                    class="w-full h-8 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-600 transition-colors"
                                    x-show="filters.from || filters.to">
                                    Clear dates
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
                                    ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500'">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span x-text="getStatusFilterLabel()"></span>
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div x-show="statusFilterOpen" @click.away="statusFilterOpen = false" x-transition
                                class="absolute top-full right-0 mt-1 w-48 bg-white dark:bg-slate-700 rounded-lg shadow-lg border border-gray-200 dark:border-slate-600 overflow-hidden z-50">
                                <!-- Default: Open only -->
                                <button @click="filters.statuses = []; fetchLogs(); statusFilterOpen = false"
                                    class="w-full px-3 py-2 text-sm text-left hover:bg-gray-100 dark:hover:bg-slate-600 flex items-center gap-2"
                                    :class="{ 'bg-blue-50 dark:bg-blue-900/20': filters.statuses.length === 0 }">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    <span class="text-gray-700 dark:text-gray-300">Open</span>
                                    <span class="ml-auto text-xs text-gray-400">(default)</span>
                                </button>
                                <!-- Needs Attention: All non-closed statuses -->
                                <button @click="filters.statuses = getNeedsAttentionStatuses(); fetchLogs(); statusFilterOpen = false"
                                    class="w-full px-3 py-2 text-sm text-left hover:bg-gray-100 dark:hover:bg-slate-600 flex items-center gap-2"
                                    :class="{ 'bg-blue-50 dark:bg-blue-900/20': isNeedsAttentionFilter() }">
                                    <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                                    <span class="text-gray-700 dark:text-gray-300">Needs Attention</span>
                                </button>
                                <div class="border-t border-gray-200 dark:border-slate-600"></div>
                                <template x-for="status in statuses" :key="status.value">
                                    <button @click="toggleStatusFilter(status.value)"
                                        class="w-full px-3 py-2 text-sm text-left hover:bg-gray-100 dark:hover:bg-slate-600 flex items-center gap-2"
                                        :class="{ 'bg-blue-50 dark:bg-blue-900/20': filters.statuses.includes(status.value) }">
                                        <span class="w-2 h-2 rounded-full" :class="'bg-' + status.color + '-500'"></span>
                                        <span x-text="status.label" class="text-gray-700 dark:text-gray-300"></span>
                                        <svg x-show="filters.statuses.includes(status.value)" class="w-4 h-4 ml-auto text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                        </svg>
                                    </button>
                                </template>
                                <div class="border-t border-gray-200 dark:border-slate-600"></div>
                                <button @click="toggleShowAll(); statusFilterOpen = false"
                                    class="w-full px-3 py-2 text-sm text-left hover:bg-gray-100 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300">
                                    <span x-text="filters.statuses.length === statuses.length ? 'Reset to Open' : 'Show All'"></span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Keyboard shortcuts -->
                    <button @click="showKeyboardHelp = true"
                        class="h-9 w-9 flex items-center justify-center rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500 transition-colors"
                        title="Keyboard shortcuts (?)">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>

                    <!-- Clear -->
                    <button @click="clearFilters()"
                        class="h-9 px-3 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500 transition-colors"
                        title="Clear all filters">
                        Clear
                    </button>

                    <!-- Refresh -->
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
                <div class="flex items-center gap-2 px-4 py-2 border-t border-gray-100 dark:border-slate-600 bg-gray-50/50 dark:bg-slate-600/30">
                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500 w-10" x-text="searchMode.toUpperCase()"></span>
                    <select x-model="searches[index + 1].field"
                        class="h-8 px-2 bg-slate-100 dark:bg-slate-600 border border-gray-200 dark:border-slate-500 rounded-md text-sm text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="any">Any field</option>
                        <option value="message">Message</option>
                        <option value="context">Context</option>
                        <option value="source">Source</option>
                    </select>
                    <button @click="searches[index + 1].exclude = !searches[index + 1].exclude; fetchLogs()"
                        class="h-8 px-2 rounded-md text-xs font-bold transition-colors border"
                        :class="searches[index + 1].exclude
                            ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border-red-300 dark:border-red-700'
                            : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-gray-400 border-gray-300 dark:border-slate-500 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-400 dark:hover:border-slate-400'"
                        title="Toggle NOT (exclude matching)">
                        NOT
                    </button>
                    <input type="text" x-model="searches[index + 1].value" @input.debounce.300ms="fetchLogs()"
                        :placeholder="searches[index + 1].exclude ? 'Exclude...' : 'Search...'"
                        class="flex-1 h-8 px-3 border rounded-md text-sm text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2"
                        :class="searches[index + 1].exclude
                            ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 focus:ring-red-500'
                            : 'bg-slate-100 dark:bg-slate-600 border-gray-200 dark:border-slate-500 focus:ring-blue-500'">
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
                <template x-for="level in filters.levels" :key="'inc-' + level">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-200">
                        <span x-text="level" class="capitalize"></span>
                        <button @click="clearLevelFilter(level)" class="hover:text-blue-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="level in filters.excludeLevels" :key="'exc-' + level">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200">
                        <span class="line-through capitalize" x-text="level"></span>
                        <button @click="clearLevelFilter(level)" class="hover:text-red-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="channel in filters.channels" :key="'inc-' + channel">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-800 text-green-700 dark:text-green-200">
                        <span x-text="channel"></span>
                        <button @click="clearChannelFilter(channel)" class="hover:text-green-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="channel in filters.excludeChannels" :key="'exc-' + channel">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200">
                        <span class="line-through" x-text="channel"></span>
                        <button @click="clearChannelFilter(channel)" class="hover:text-red-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="method in filters.httpMethods" :key="'inc-http-' + method">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-800 text-purple-700 dark:text-purple-200">
                        <span x-text="method"></span>
                        <button @click="clearHttpMethodFilter(method)" class="hover:text-purple-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="method in filters.excludeHttpMethods" :key="'exc-http-' + method">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200">
                        <span class="line-through" x-text="method"></span>
                        <button @click="clearHttpMethodFilter(method)" class="hover:text-red-900 dark:hover:text-white">&times;</button>
                    </span>
                </template>
                <template x-for="(search, idx) in searches.filter(s => s.value)" :key="'search-' + idx">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium"
                        :class="search.exclude
                            ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
                            : 'bg-gray-100 dark:bg-slate-500 text-gray-700 dark:text-gray-200'">
                        <span x-show="search.exclude" class="font-bold">NOT</span>
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

                <!-- Error Alert -->
                <div x-show="error" x-cloak class="mx-4 mt-4">
                    <div class="flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                        <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm text-red-700 dark:text-red-300" x-text="error"></p>
                        <button @click="error = null" class="ml-auto text-red-500 hover:text-red-700 dark:hover:text-red-300">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div x-show="!loading && !error && logs.length === 0" x-cloak class="flex-1 flex items-center justify-center">
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
                        <thead class="sticky top-0 bg-gray-50 dark:bg-slate-850 z-10">
                            <tr class="border-b border-gray-200 dark:border-slate-600">
                                <th class="w-[3px] p-0"></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-40">Time</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">Level</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Message</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-28">Channel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="log in logs" :key="log.id">
                                <tr class="log-row border-b border-gray-100 dark:border-slate-600/50 cursor-pointer"
                                    :class="{
                                        'selected': selectedLog?.id === log.id,
                                        'opacity-60': log.status === 'resolved' || log.status === 'ignored'
                                    }"
                                    @click="selectLog(log)">
                                    <td class="p-0 relative">
                                        <div class="level-indicator h-full" :class="'level-' + log.level"></div>
                                        <div x-show="log.status && log.status !== 'open'" class="absolute top-1 left-1 w-3 h-3" :class="getStatusIconColor(log.status)" :title="getStatusLabel(log.status)">
                                            <template x-if="log.status === 'resolved'">
                                                <svg fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                </svg>
                                            </template>
                                            <template x-if="log.status === 'investigating'">
                                                <svg fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                                                </svg>
                                            </template>
                                            <template x-if="log.status === 'ignored'">
                                                <svg fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/>
                                                </svg>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 relative group">
                                        <span class="text-sm text-gray-600 dark:text-gray-400 tabular-nums whitespace-nowrap cursor-help"
                                            x-text="formatRelativeTime(log.occurred_at)"></span>
                                        <div class="absolute left-0 bottom-full mb-1 px-2 py-1 text-xs bg-gray-900 dark:bg-gray-700 text-white rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none z-20"
                                            x-text="formatFullDateTime(log.occurred_at)"></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="level-badge" :class="'level-' + log.level" x-text="log.level"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate" :style="{ maxWidth: getMessagePreviewWidth() + 'px' }" x-text="log.message_preview || log.message"></p>
                                        <p x-show="log.source" class="mt-0.5 text-xs text-gray-400 dark:text-gray-500 truncate font-mono" x-text="formatSource(log.source, log.source_line)"></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-sm text-gray-500 dark:text-gray-400 truncate block max-w-[120px]" :title="log.channel" x-text="log.channel"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div x-show="!loading && logs.length > 0" x-cloak
                    class="flex items-center justify-between px-4 py-3 bg-slate-100 dark:bg-slate-850 border-t border-gray-200 dark:border-slate-600">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Showing <span class="font-medium text-gray-900 dark:text-gray-100" x-text="((meta.current_page - 1) * meta.per_page) + 1"></span>
                        to <span class="font-medium text-gray-900 dark:text-gray-100" x-text="Math.min(meta.current_page * meta.per_page, meta.total)"></span>
                        of <span class="font-medium text-gray-900 dark:text-gray-100" x-text="meta.total?.toLocaleString()"></span>
                    </p>
                    <div class="flex items-center gap-1">
                        <button @click="prevPage()" :disabled="meta.current_page <= 1"
                            class="h-8 px-3 rounded text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <span class="px-2 text-sm text-gray-500 dark:text-gray-400">
                            <span x-text="meta.current_page"></span> / <span x-text="meta.last_page"></span>
                        </span>
                        <button @click="nextPage()" :disabled="meta.current_page >= meta.last_page"
                            class="h-8 px-3 rounded text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <!-- Detail Panel -->
            <div x-show="selectedLog" x-cloak
                class="flex-shrink-0 bg-slate-100 dark:bg-slate-850 border-l border-gray-200 dark:border-slate-600 flex flex-col overflow-hidden relative"
                :style="{ width: (detailPanelWidth || getDefaultPanelWidth()) + 'px' }"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-x-4"
                x-transition:enter-end="opacity-100 translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-x-0"
                x-transition:leave-end="opacity-0 translate-x-4">
                <!-- Resize Handle -->
                <div class="absolute left-0 top-0 bottom-0 w-1 cursor-col-resize hover:bg-blue-500 active:bg-blue-600 transition-colors z-10"
                    :class="isResizing ? 'bg-blue-500' : 'bg-transparent hover:bg-blue-400/50'"
                    @mousedown.prevent="startResize($event)"></div>
                <!-- Panel Header -->
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-slate-600">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Log Details</h3>
                    <div class="flex items-center gap-1">
                        <button x-show="detailPanelWidth" @click="resetPanelWidth()"
                            class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500"
                            title="Reset panel width">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                        </button>
                        <button @click="closePanel()"
                            class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-500"
                            title="Close panel">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Panel Content -->
                <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-4">
                    <!-- Meta -->
                    <div class="grid grid-cols-3 gap-3">
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Level</p>
                            <p class="mt-1">
                                <span class="level-badge" :class="'level-' + selectedLog?.level" x-text="selectedLog?.level"></span>
                            </p>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600 overflow-hidden">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Channel</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white truncate" :title="selectedLog?.channel" x-text="selectedLog?.channel || '-'"></p>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Time</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white" x-text="formatRelativeTime(selectedLog?.occurred_at)"></p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400" x-text="formatTime(selectedLog?.occurred_at)"></p>
                        </div>
                    </div>

                    <!-- Source -->
                    <div x-show="selectedLog?.source">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Source</p>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600 font-mono text-sm text-gray-700 dark:text-gray-300 break-all"
                            x-text="formatSource(selectedLog?.source, selectedLog?.source_line)"></div>
                    </div>

                    <!-- Message -->
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Message</p>
                        <pre class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-words overflow-x-auto"
                            x-text="selectedLog?.message"></pre>
                    </div>

                    <!-- Context -->
                    <div x-show="selectedLog?.context && Object.keys(selectedLog?.context || {}).length > 0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Context</p>
                        <pre class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600 text-sm overflow-x-auto font-mono json-highlight"
                            x-ref="jsonContext"
                            @click="handleJsonToggle($event)"
                            x-html="renderJsonContext()"></pre>
                    </div>

                    <!-- Request Context -->
                    <div x-show="selectedLog?.trace_id || selectedLog?.user_id || selectedLog?.ip_address || selectedLog?.url">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Request Context</p>
                        <div class="space-y-2">
                            <!-- Trace ID -->
                            <div x-show="selectedLog?.trace_id">
                                <button @click="filterByTraceId(selectedLog?.trace_id)"
                                    class="w-full p-2 rounded-lg bg-slate-100 dark:bg-slate-600 border-l-4 border-indigo-400 dark:border-indigo-500 hover:bg-slate-200 dark:hover:bg-slate-500 text-left break-all transition-colors">
                                    <span class="text-xs text-slate-500 dark:text-slate-400 block">Trace ID</span>
                                    <span x-text="selectedLog?.trace_id" class="text-xs font-mono text-slate-700 dark:text-slate-200"></span>
                                </button>
                            </div>
                            <!-- User ID -->
                            <div x-show="selectedLog?.user_id">
                                <button @click="filterByUserId(selectedLog?.user_id)"
                                    class="w-full p-2 rounded-lg bg-slate-100 dark:bg-slate-600 border-l-4 border-cyan-400 dark:border-cyan-500 hover:bg-slate-200 dark:hover:bg-slate-500 text-left transition-colors">
                                    <span class="text-xs text-slate-500 dark:text-slate-400 block">User ID</span>
                                    <span x-text="selectedLog?.user_id" class="text-sm font-mono text-slate-700 dark:text-slate-200"></span>
                                </button>
                            </div>
                            <!-- IP Address -->
                            <div x-show="selectedLog?.ip_address">
                                <button @click="filterByIpAddress(selectedLog?.ip_address)"
                                    class="w-full p-2 rounded-lg bg-slate-100 dark:bg-slate-600 border-l-4 border-amber-400 dark:border-amber-500 hover:bg-slate-200 dark:hover:bg-slate-500 text-left transition-colors">
                                    <span class="text-xs text-slate-500 dark:text-slate-400 block">IP Address</span>
                                    <span x-text="selectedLog?.ip_address" class="text-sm font-mono text-slate-700 dark:text-slate-200"></span>
                                </button>
                            </div>
                            <!-- HTTP Method & URL -->
                            <div x-show="selectedLog?.http_method || selectedLog?.url" class="p-2 rounded-lg bg-slate-100 dark:bg-slate-600">
                                <span class="text-xs text-slate-500 dark:text-slate-400 block mb-1">Request</span>
                                <div class="flex items-center gap-2 text-sm">
                                    <span x-show="selectedLog?.http_method"
                                        class="px-2 py-0.5 rounded text-xs font-bold"
                                        :class="{
                                            'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300': selectedLog?.http_method === 'GET',
                                            'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300': selectedLog?.http_method === 'POST',
                                            'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300': selectedLog?.http_method === 'PUT' || selectedLog?.http_method === 'PATCH',
                                            'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300': selectedLog?.http_method === 'DELETE',
                                            'bg-slate-200 dark:bg-slate-500 text-slate-700 dark:text-slate-200': !['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(selectedLog?.http_method)
                                        }"
                                        x-text="selectedLog?.http_method"></span>
                                    <span x-show="selectedLog?.url" class="font-mono text-slate-700 dark:text-slate-200 break-all text-xs" x-text="selectedLog?.url"></span>
                                </div>
                            </div>
                            <!-- User Agent -->
                            <div x-show="selectedLog?.user_agent" class="p-2 rounded-lg bg-slate-100 dark:bg-slate-600">
                                <span class="text-xs text-slate-500 dark:text-slate-400 block">User Agent</span>
                                <span class="text-xs font-mono text-slate-700 dark:text-slate-200 break-all" x-text="selectedLog?.user_agent"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Status -->
                    <div x-show="features.status && selectedLog?.status && selectedLog?.status !== 'open'">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Status</p>
                        <div class="p-3 rounded-lg border" :class="getStatusBgClass(selectedLog?.status)">
                            <div class="flex items-center gap-2" :class="getStatusTextClass(selectedLog?.status)">
                                <template x-if="selectedLog?.status === 'resolved'">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                                <template x-if="selectedLog?.status === 'investigating'">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                                <template x-if="selectedLog?.status === 'ignored'">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                                <span class="text-sm font-medium" x-text="getStatusLabel(selectedLog?.status)"></span>
                            </div>
                            <p class="mt-1 text-xs" :class="getStatusMutedTextClass(selectedLog?.status)">
                                <span x-text="formatRelativeTime(selectedLog?.status_changed_at)"></span>
                                <span x-show="selectedLog?.status_changed_by"> by <span x-text="selectedLog?.status_changed_by"></span></span>
                            </p>
                        </div>
                    </div>

                    <!-- Note -->
                    <div x-show="features.notes" x-data="{ editing: false, noteText: '' }" x-effect="if (selectedLog) { editing = false; noteText = ''; }"
                        @focus-note.window="editing = true; noteText = selectedLog?.note || ''; $nextTick(() => $refs.noteInput?.focus())">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">Note</p>
                        <div x-show="!editing" @click="editing = true; noteText = selectedLog?.note || ''; $nextTick(() => $refs.noteInput.focus())"
                            class="p-3 rounded-lg bg-gray-50 dark:bg-slate-600 min-h-[60px] cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-500 transition-colors">
                            <p x-show="selectedLog?.note" class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap" x-text="selectedLog?.note"></p>
                            <p x-show="!selectedLog?.note" class="text-sm text-gray-400 dark:text-gray-500 italic">Click to add a note...</p>
                        </div>
                        <div x-show="editing" class="space-y-2">
                            <textarea x-model="noteText" x-ref="noteInput"
                                class="w-full p-3 rounded-lg bg-gray-50 dark:bg-slate-600 text-sm text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                                rows="3"
                                placeholder="Add a note about this log entry..."></textarea>
                            <div class="flex gap-2">
                                <button @click="updateNote(noteText); editing = false"
                                    class="px-3 py-1.5 rounded text-xs font-medium text-white bg-blue-600 hover:bg-blue-700">
                                    Save
                                </button>
                                <button @click="editing = false"
                                    class="px-3 py-1.5 rounded text-xs font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-slate-500 hover:bg-gray-200 dark:hover:bg-slate-400">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel Footer -->
                <div class="flex items-center gap-2 px-4 py-3 border-t border-gray-200 dark:border-slate-600">
                    <template x-if="features.status">
                        <div class="flex-1 relative" x-data="{ statusOpen: false }">
                            <button @click="statusOpen = !statusOpen"
                                class="w-full h-9 px-3 rounded-lg text-sm font-medium flex items-center justify-between gap-2 transition-colors"
                                :class="getStatusButtonClass(selectedLog?.status)">
                                <span x-text="getStatusLabel(selectedLog?.status)"></span>
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div x-show="statusOpen" @click.away="statusOpen = false" x-transition
                                class="absolute bottom-full left-0 right-0 mb-1 bg-white dark:bg-slate-700 rounded-lg shadow-lg border border-gray-200 dark:border-slate-600 overflow-hidden z-50">
                                <template x-for="status in statuses" :key="status.value">
                                    <button @click="setStatus(status.value); statusOpen = false"
                                        class="w-full px-3 py-2 text-sm text-left hover:bg-gray-100 dark:hover:bg-slate-600 flex items-center gap-2"
                                        :class="{ 'bg-gray-50 dark:bg-slate-650': selectedLog?.status === status.value }">
                                        <span class="w-2 h-2 rounded-full" :class="'bg-' + status.color + '-500'"></span>
                                        <span x-text="status.label" class="text-gray-700 dark:text-gray-300"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <button @click="confirmDelete()"
                        class="flex-1 h-9 rounded-lg text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                        Delete
                    </button>
                    <button @click="closePanel()"
                        class="flex-1 h-9 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-slate-600 hover:bg-gray-200 dark:hover:bg-slate-500 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <div x-show="showDeleteDialog" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="cancelDelete()"></div>
        <!-- Dialog -->
        <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-sm w-full mx-4 p-6"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Delete Log Entry</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">This action cannot be undone.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <button @click="cancelDelete()"
                    class="flex-1 h-10 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-slate-600 hover:bg-gray-200 dark:hover:bg-slate-500 transition-colors">
                    Cancel
                </button>
                <button @click="deleteLog()"
                    class="flex-1 h-10 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors">
                    Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Keyboard Shortcuts Help Dialog -->
    <div x-show="showKeyboardHelp" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center"
        @keydown.escape.window="showKeyboardHelp = false"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="showKeyboardHelp = false"></div>
        <!-- Dialog -->
        <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-sm w-full mx-4 p-6"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Keyboard Shortcuts</h3>
                <button @click="showKeyboardHelp = false"
                    class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="space-y-2">
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Navigation</h4>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Navigate down</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">j</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Navigate up</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">k</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Open detail panel</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">Enter</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Close panel</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">Esc</kbd>
                </div>

                <template x-if="Object.keys(shortcuts).length > 0">
                    <div>
                        <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide pt-2">Filter by Status</h4>
                        <template x-for="(status, key) in shortcuts" :key="key">
                            <div class="flex items-center justify-between mt-2">
                                <span class="text-sm text-gray-600 dark:text-gray-300 capitalize" x-text="getStatusLabel(status)"></span>
                                <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded" x-text="key"></kbd>
                            </div>
                        </template>
                    </div>
                </template>

                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide pt-2">Actions</h4>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Focus search</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">/</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Focus note</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">n</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Clear filters</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">c</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Toggle dark mode</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">d</kbd>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Show this help</span>
                    <kbd class="px-2 py-1 text-xs font-mono bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded">?</kbd>
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
        error: null,
        selectedLog: null,
        showDeleteDialog: false,
        showKeyboardHelp: false,
        detailPanelWidth: parseInt(localStorage.getItem('logscope_panel_width')) || 0,
        isResizing: false,
        minPanelWidth: 360,
        quickFilters: @json($quickFilters),
        features: @json($features),
        jsonViewer: @json($jsonViewer),
        jsonCollapseState: {},
        jsonRenderKey: 0,
        _lastJsonLogId: null,
        statuses: @json($statuses),
        shortcuts: @json($shortcuts),
        searches: [{ field: 'any', value: '', exclude: false }],
        searchMode: 'and',
        filters: {
            levels: [],
            excludeLevels: [],
            channels: [],
            excludeChannels: [],
            httpMethods: [],
            excludeHttpMethods: [],
            statuses: [],
            from: '',
            to: '',
            trace_id: '',
            user_id: '',
            ip_address: '',
            url: ''
        },
        sections: {
            quickFilters: JSON.parse(localStorage.getItem('logscope-section-quickFilters') ?? 'true'),
            severity: JSON.parse(localStorage.getItem('logscope-section-severity') ?? 'true'),
            channels: JSON.parse(localStorage.getItem('logscope-section-channels') ?? 'true'),
            httpMethods: JSON.parse(localStorage.getItem('logscope-section-httpMethods') ?? 'true'),
            request: JSON.parse(localStorage.getItem('logscope-section-request') ?? 'false'),
        },
        page: 1,

        async init() {
            // Validate saved panel width against current max
            if (this.detailPanelWidth) {
                const maxWidth = this.getMaxPanelWidth();
                if (this.detailPanelWidth > maxWidth) {
                    this.detailPanelWidth = maxWidth;
                    localStorage.setItem('logscope_panel_width', maxWidth);
                }
            }

            // Watch section states and persist to localStorage
            this.$watch('sections.quickFilters', val => localStorage.setItem('logscope-section-quickFilters', JSON.stringify(val)));
            this.$watch('sections.severity', val => localStorage.setItem('logscope-section-severity', JSON.stringify(val)));
            this.$watch('sections.channels', val => localStorage.setItem('logscope-section-channels', JSON.stringify(val)));
            this.$watch('sections.httpMethods', val => localStorage.setItem('logscope-section-httpMethods', JSON.stringify(val)));
            this.$watch('sections.request', val => localStorage.setItem('logscope-section-request', JSON.stringify(val)));

            // Load filters from URL on init
            this.loadFiltersFromUrl();

            await Promise.all([this.fetchLogs(), this.fetchStats()]);
        },

        loadFiltersFromUrl() {
            const params = new URLSearchParams(window.location.search);

            // Load levels
            const levels = params.getAll('levels[]');
            if (levels.length > 0) this.filters.levels = levels;

            const excludeLevels = params.getAll('exclude_levels[]');
            if (excludeLevels.length > 0) this.filters.excludeLevels = excludeLevels;

            // Load channels
            const channels = params.getAll('channels[]');
            if (channels.length > 0) this.filters.channels = channels;

            const excludeChannels = params.getAll('exclude_channels[]');
            if (excludeChannels.length > 0) this.filters.excludeChannels = excludeChannels;

            // Load HTTP methods
            const httpMethods = params.getAll('http_method[]');
            if (httpMethods.length > 0) this.filters.httpMethods = httpMethods;

            const excludeHttpMethods = params.getAll('exclude_http_method[]');
            if (excludeHttpMethods.length > 0) this.filters.excludeHttpMethods = excludeHttpMethods;

            // Load date range
            if (params.get('from')) this.filters.from = params.get('from');
            if (params.get('to')) this.filters.to = params.get('to');

            // Load request context filters
            if (params.get('trace_id')) this.filters.trace_id = params.get('trace_id');
            if (params.get('user_id')) this.filters.user_id = params.get('user_id');
            if (params.get('ip_address')) this.filters.ip_address = params.get('ip_address');
            if (params.get('url')) this.filters.url = params.get('url');

            // Load search
            if (params.get('search')) {
                this.searches[0].value = params.get('search');
            }
            if (params.get('search_field')) {
                this.searches[0].field = params.get('search_field');
            }
            if (params.get('search_exclude') === '1') {
                this.searches[0].exclude = true;
            }

            // Load page
            if (params.get('page')) {
                this.page = parseInt(params.get('page')) || 1;
            }
        },

        syncFiltersToUrl() {
            const params = new URLSearchParams();

            // Add levels
            this.filters.levels.forEach(l => params.append('levels[]', l));
            this.filters.excludeLevels.forEach(l => params.append('exclude_levels[]', l));

            // Add channels
            this.filters.channels.forEach(c => params.append('channels[]', c));
            this.filters.excludeChannels.forEach(c => params.append('exclude_channels[]', c));

            // Add HTTP methods
            this.filters.httpMethods.forEach(m => params.append('http_method[]', m));
            this.filters.excludeHttpMethods.forEach(m => params.append('exclude_http_method[]', m));

            // Add date range
            if (this.filters.from) params.set('from', this.filters.from);
            if (this.filters.to) params.set('to', this.filters.to);

            // Add request context
            if (this.filters.trace_id) params.set('trace_id', this.filters.trace_id);
            if (this.filters.user_id) params.set('user_id', this.filters.user_id);
            if (this.filters.ip_address) params.set('ip_address', this.filters.ip_address);
            if (this.filters.url) params.set('url', this.filters.url);

            // Add search
            if (this.searches[0]?.value) {
                params.set('search', this.searches[0].value);
                if (this.searches[0].field !== 'any') {
                    params.set('search_field', this.searches[0].field);
                }
                if (this.searches[0].exclude) {
                    params.set('search_exclude', '1');
                }
            }

            // Add page if not first
            if (this.page > 1) params.set('page', this.page);

            // Update URL without reload
            const newUrl = params.toString()
                ? `${window.location.pathname}?${params.toString()}`
                : window.location.pathname;
            window.history.replaceState({}, '', newUrl);
        },

        addSearch() {
            this.searches.push({ field: 'any', value: '', exclude: false });
        },

        removeSearch(index) {
            this.searches.splice(index, 1);
            this.fetchLogs();
        },

        hasActiveFilters() {
            return this.filters.levels.length > 0 ||
                this.filters.excludeLevels.length > 0 ||
                this.filters.channels.length > 0 ||
                this.filters.excludeChannels.length > 0 ||
                this.filters.httpMethods.length > 0 ||
                this.filters.excludeHttpMethods.length > 0 ||
                this.searches.some(s => s.value) ||
                this.filters.from ||
                this.filters.to ||
                this.filters.trace_id ||
                this.filters.user_id ||
                this.filters.ip_address ||
                this.filters.url;
        },

        toggleHttpMethod(method) {
            const inInclude = this.filters.httpMethods.indexOf(method);
            const inExclude = this.filters.excludeHttpMethods.indexOf(method);

            if (inInclude === -1 && inExclude === -1) {
                // Neutral → Include
                this.filters.httpMethods.push(method);
            } else if (inInclude !== -1) {
                // Include → Exclude
                this.filters.httpMethods.splice(inInclude, 1);
                this.filters.excludeHttpMethods.push(method);
            } else {
                // Exclude → Neutral
                this.filters.excludeHttpMethods.splice(inExclude, 1);
            }
            this.fetchLogs();
        },

        clearHttpMethodFilter(method) {
            const inInclude = this.filters.httpMethods.indexOf(method);
            const inExclude = this.filters.excludeHttpMethods.indexOf(method);
            if (inInclude !== -1) this.filters.httpMethods.splice(inInclude, 1);
            if (inExclude !== -1) this.filters.excludeHttpMethods.splice(inExclude, 1);
            this.fetchLogs();
        },

        filterByTraceId(traceId) {
            this.filters.trace_id = traceId;
            this.sections.request = true;
            localStorage.setItem('logscope-section-request', 'true');
            this.fetchLogs();
        },

        filterByUserId(userId) {
            this.filters.user_id = userId;
            this.sections.request = true;
            localStorage.setItem('logscope-section-request', 'true');
            this.fetchLogs();
        },

        filterByIpAddress(ip) {
            this.filters.ip_address = ip;
            this.sections.request = true;
            localStorage.setItem('logscope-section-request', 'true');
            this.fetchLogs();
        },

        toggleLevel(level) {
            const inInclude = this.filters.levels.indexOf(level);
            const inExclude = this.filters.excludeLevels.indexOf(level);

            if (inInclude === -1 && inExclude === -1) {
                // Neutral → Include
                this.filters.levels.push(level);
            } else if (inInclude !== -1) {
                // Include → Exclude
                this.filters.levels.splice(inInclude, 1);
                this.filters.excludeLevels.push(level);
            } else {
                // Exclude → Neutral
                this.filters.excludeLevels.splice(inExclude, 1);
            }
            this.fetchLogs();
        },

        clearLevelFilter(level) {
            const inInclude = this.filters.levels.indexOf(level);
            const inExclude = this.filters.excludeLevels.indexOf(level);
            if (inInclude !== -1) this.filters.levels.splice(inInclude, 1);
            if (inExclude !== -1) this.filters.excludeLevels.splice(inExclude, 1);
            this.fetchLogs();
        },

        toggleChannel(channel) {
            const inInclude = this.filters.channels.indexOf(channel);
            const inExclude = this.filters.excludeChannels.indexOf(channel);

            if (inInclude === -1 && inExclude === -1) {
                // Neutral → Include
                this.filters.channels.push(channel);
            } else if (inInclude !== -1) {
                // Include → Exclude
                this.filters.channels.splice(inInclude, 1);
                this.filters.excludeChannels.push(channel);
            } else {
                // Exclude → Neutral
                this.filters.excludeChannels.splice(inExclude, 1);
            }
            this.fetchLogs();
        },

        clearChannelFilter(channel) {
            const inInclude = this.filters.channels.indexOf(channel);
            const inExclude = this.filters.excludeChannels.indexOf(channel);
            if (inInclude !== -1) this.filters.channels.splice(inInclude, 1);
            if (inExclude !== -1) this.filters.excludeChannels.splice(inExclude, 1);
            this.fetchLogs();
        },

        async fetchLogs() {
            this.loading = true;
            this.error = null;
            try {
                const params = new URLSearchParams();
                params.append('page', this.page);
                this.filters.statuses.forEach(s => params.append('statuses[]', s));
                if (this.filters.from) params.append('from', this.filters.from);
                if (this.filters.to) params.append('to', this.filters.to);
                if (this.filters.from || this.filters.to) {
                    params.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
                }
                if (this.filters.trace_id) params.append('trace_id', this.filters.trace_id);
                if (this.filters.user_id) params.append('user_id', this.filters.user_id);
                if (this.filters.ip_address) params.append('ip_address', this.filters.ip_address);
                if (this.filters.url) params.append('url', this.filters.url);
                this.filters.levels.forEach(l => params.append('levels[]', l));
                this.filters.excludeLevels.forEach(l => params.append('exclude_levels[]', l));
                this.filters.channels.forEach(c => params.append('channels[]', c));
                this.filters.excludeChannels.forEach(c => params.append('exclude_channels[]', c));
                this.filters.httpMethods.forEach(m => params.append('http_method[]', m));
                this.filters.excludeHttpMethods.forEach(m => params.append('exclude_http_method[]', m));

                // Add advanced search params
                const activeSearches = this.searches.filter(s => s.value);
                if (activeSearches.length > 0) {
                    activeSearches.forEach((s, i) => {
                        params.append(`searches[${i}][field]`, s.field);
                        params.append(`searches[${i}][value]`, s.value);
                        params.append(`searches[${i}][exclude]`, s.exclude ? '1' : '0');
                    });
                    params.append('search_mode', this.searchMode);
                }

                const response = await fetch(`{{ route('logscope.logs') }}?${params}`);
                const data = await response.json();

                if (!response.ok) {
                    this.error = data.message || data.error || 'Failed to fetch logs';
                    this.logs = [];
                    this.meta = { current_page: 1, last_page: 1, per_page: 50, total: 0 };
                    return;
                }

                this.logs = data.data;
                this.meta = data.meta;
                this.syncFiltersToUrl();
            } catch (error) {
                console.error('Failed to fetch logs:', error);
                this.error = 'Failed to fetch logs. Please try again.';
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

        confirmDelete() {
            this.showDeleteDialog = true;
        },

        cancelDelete() {
            this.showDeleteDialog = false;
        },

        async deleteLog() {
            if (!this.selectedLog) return;
            try {
                await fetch(`{{ url(config('logscope.routes.prefix', 'logscope')) }}/api/logs/${this.selectedLog.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                });
                this.showDeleteDialog = false;
                this.selectedLog = null;
                await Promise.all([this.fetchLogs(), this.fetchStats()]);
            } catch (error) {
                console.error('Failed to delete log:', error);
            }
        },

        async setStatus(status, note = null) {
            if (!this.selectedLog) return;
            try {
                const body = { status };
                if (note) body.note = note;

                const response = await fetch(`{{ url(config('logscope.routes.prefix', 'logscope')) }}/api/logs/${this.selectedLog.id}/status`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                if (response.ok) {
                    this.selectedLog = data.data;
                    await this.fetchLogs();
                }
            } catch (error) {
                console.error('Failed to update status:', error);
            }
        },

        getStatusLabel(status) {
            const found = this.statuses.find(s => s.value === status);
            return found ? found.label : (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Open');
        },

        getStatusIconColor(status) {
            const colors = {
                'open': 'text-gray-400',
                'investigating': 'text-yellow-500',
                'resolved': 'text-green-500',
                'ignored': 'text-slate-400'
            };
            return colors[status] || 'text-gray-400';
        },

        getStatusBgClass(status) {
            const classes = {
                'investigating': 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800',
                'resolved': 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800',
                'ignored': 'bg-slate-50 dark:bg-slate-700/50 border-slate-200 dark:border-slate-600'
            };
            return classes[status] || 'bg-gray-50 dark:bg-gray-700/50 border-gray-200 dark:border-gray-600';
        },

        getStatusTextClass(status) {
            const classes = {
                'investigating': 'text-yellow-700 dark:text-yellow-300',
                'resolved': 'text-green-700 dark:text-green-300',
                'ignored': 'text-slate-600 dark:text-slate-300'
            };
            return classes[status] || 'text-gray-700 dark:text-gray-300';
        },

        getStatusMutedTextClass(status) {
            const classes = {
                'investigating': 'text-yellow-600 dark:text-yellow-400',
                'resolved': 'text-green-600 dark:text-green-400',
                'ignored': 'text-slate-500 dark:text-slate-400'
            };
            return classes[status] || 'text-gray-600 dark:text-gray-400';
        },

        getStatusButtonClass(status) {
            const classes = {
                'open': 'text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-slate-600 hover:bg-gray-200 dark:hover:bg-slate-500',
                'investigating': 'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-900/20 hover:bg-yellow-100 dark:hover:bg-yellow-900/30',
                'resolved': 'text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/30',
                'ignored': 'text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-600 hover:bg-slate-200 dark:hover:bg-slate-500'
            };
            return classes[status] || classes['open'];
        },

        getStatusFilterLabel() {
            if (this.filters.statuses.length === 0) {
                return 'Open';
            }
            if (this.isNeedsAttentionFilter()) {
                return 'Needs Attention';
            }
            if (this.filters.statuses.length === this.statuses.length) {
                return 'All Statuses';
            }
            if (this.filters.statuses.length === 1) {
                return this.getStatusLabel(this.filters.statuses[0]);
            }
            return `${this.filters.statuses.length} Statuses`;
        },

        getNeedsAttentionStatuses() {
            return this.statuses.filter(s => !s.closed).map(s => s.value);
        },

        isNeedsAttentionFilter() {
            const needsAttention = this.getNeedsAttentionStatuses();
            if (this.filters.statuses.length !== needsAttention.length) return false;
            return needsAttention.every(s => this.filters.statuses.includes(s));
        },

        toggleStatusFilter(status) {
            const index = this.filters.statuses.indexOf(status);
            if (index === -1) {
                this.filters.statuses.push(status);
            } else {
                this.filters.statuses.splice(index, 1);
            }
            this.fetchLogs();
        },

        toggleShowAll() {
            if (this.filters.statuses.length === this.statuses.length) {
                // All selected, reset to default (empty = open only)
                this.filters.statuses = [];
            } else {
                // Select all
                this.filters.statuses = this.statuses.map(s => s.value);
            }
            this.fetchLogs();
        },

        async updateNote(note) {
            if (!this.selectedLog) return;
            try {
                const response = await fetch(`{{ url(config('logscope.routes.prefix', 'logscope')) }}/api/logs/${this.selectedLog.id}/note`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ note })
                });
                const data = await response.json();
                if (response.ok) {
                    this.selectedLog = data.data;
                }
            } catch (error) {
                console.error('Failed to update note:', error);
            }
        },

        selectLog(log) {
            this.selectedLog = this.selectedLog?.id === log.id ? null : log;
        },

        closePanel() {
            this.selectedLog = null;
        },

        getDefaultPanelWidth() {
            const width = window.innerWidth;
            if (width >= 1920) return 640; // Ultra-wide / 2xl
            if (width >= 1536) return 560; // xl
            if (width >= 1280) return 480; // lg
            return 400;
        },

        getMessagePreviewWidth() {
            const sidebarWidth = this.sidebarOpen ? 256 : 0;
            const panelWidth = this.selectedLog ? (this.detailPanelWidth || this.getDefaultPanelWidth()) : 0;
            const tableOverhead = 350; // Approximate space for other columns (time, level, channel, padding)
            const availableWidth = window.innerWidth - sidebarWidth - panelWidth - tableOverhead;
            // Clamp between 200px min and available space
            return Math.max(200, availableWidth);
        },

        getMaxPanelWidth() {
            // Max 50% of screen width, but at least 400px for usability
            const sidebarWidth = this.sidebarOpen ? 256 : 0; // w-64 = 256px
            const availableWidth = window.innerWidth - sidebarWidth;
            return Math.max(400, Math.min(900, Math.floor(availableWidth * 0.5)));
        },

        renderJsonContext() {
            // Access jsonRenderKey to make this reactive to toggles
            const _ = this.jsonRenderKey;

            if (!this.selectedLog?.context) return '';

            // Reset collapse state when viewing a different log
            if (this._lastJsonLogId !== this.selectedLog?.id) {
                this._lastJsonLogId = this.selectedLog?.id;
                this.jsonCollapseState = {};
            }

            return this.renderJsonValue(this.selectedLog.context, 'root', 0);
        },

        highlightJson(obj, path = 'root') {
            if (!obj) return '';
            return this.renderJsonValue(obj, path, 0);
        },

        renderJsonValue(value, path, indent) {
            const type = this.getJsonType(value);
            switch (type) {
                case 'null': return '<span class="json-null">null</span>';
                case 'boolean': return `<span class="json-boolean">${value}</span>`;
                case 'number': return `<span class="json-number">${value}</span>`;
                case 'string': return `<span class="json-string">"${this.escapeHtml(value)}"</span>`;
                case 'array': return this.renderJsonArray(value, path, indent);
                case 'object': return this.renderJsonObject(value, path, indent);
                default: return this.escapeHtml(String(value));
            }
        },

        renderJsonObject(obj, path, indent) {
            const keys = Object.keys(obj);
            if (keys.length === 0) return '{}';

            const shouldCollapse = this.shouldAutoCollapse(path, keys.length, 'object');
            const collapseId = path;
            if (this.jsonCollapseState[collapseId] === undefined) {
                this.jsonCollapseState[collapseId] = shouldCollapse;
            }
            const isCollapsed = this.jsonCollapseState[collapseId];

            const spaces = '  '.repeat(indent);
            const innerSpaces = '  '.repeat(indent + 1);

            if (isCollapsed) {
                return `<span class="json-toggle" data-path="${collapseId}" data-action="expand">▶</span> {<span class="json-collapsed" data-path="${collapseId}" data-action="expand" title="Click to expand">${keys.length} ${keys.length === 1 ? 'property' : 'properties'}</span>}`;
            }

            let html = `<span class="json-toggle" data-path="${collapseId}" data-action="collapse">▼</span> {\n`;
            keys.forEach((key, i) => {
                const childPath = `${path}.${key}`;
                html += `${innerSpaces}<span class="json-key">"${this.escapeHtml(key)}"</span>: `;
                html += this.renderJsonValue(obj[key], childPath, indent + 1);
                if (i < keys.length - 1) html += ',';
                html += '\n';
            });
            html += `${spaces}}`;
            return html;
        },

        renderJsonArray(arr, path, indent) {
            if (arr.length === 0) return '[]';

            const keyName = path.split('.').pop();
            const shouldCollapse = this.shouldAutoCollapse(path, arr.length, 'array', keyName);
            const collapseId = path;
            if (this.jsonCollapseState[collapseId] === undefined) {
                this.jsonCollapseState[collapseId] = shouldCollapse;
            }
            const isCollapsed = this.jsonCollapseState[collapseId];

            const spaces = '  '.repeat(indent);
            const innerSpaces = '  '.repeat(indent + 1);

            if (isCollapsed) {
                return `<span class="json-toggle" data-path="${collapseId}" data-action="expand">▶</span> [<span class="json-collapsed" data-path="${collapseId}" data-action="expand" title="Click to expand">${arr.length} ${arr.length === 1 ? 'item' : 'items'}</span>]`;
            }

            let html = `<span class="json-toggle" data-path="${collapseId}" data-action="collapse">▼</span> [\n`;
            arr.forEach((item, i) => {
                const childPath = `${path}[${i}]`;
                html += `${innerSpaces}${this.renderJsonValue(item, childPath, indent + 1)}`;
                if (i < arr.length - 1) html += ',';
                html += '\n';
            });
            html += `${spaces}]`;
            return html;
        },

        shouldAutoCollapse(path, itemCount, type, keyName = '') {
            const threshold = this.jsonViewer.collapseThreshold;
            const autoCollapseKeys = this.jsonViewer.autoCollapseKeys || [];

            // Check if this key should always be collapsed
            if (keyName && autoCollapseKeys.includes(keyName.toLowerCase())) {
                return true;
            }

            // Check threshold (0 means disabled)
            if (threshold > 0 && itemCount > threshold) {
                return true;
            }

            return false;
        },

        getJsonType(value) {
            if (value === null) return 'null';
            if (Array.isArray(value)) return 'array';
            return typeof value;
        },

        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        handleJsonToggle(event) {
            // Find the clicked element or closest parent with data-path
            const target = event.target.closest('[data-path]');
            if (!target) return;

            const path = target.dataset.path;
            const action = target.dataset.action;

            if (!path || !action) return;

            // Toggle the collapse state (expand = false, collapse = true)
            this.jsonCollapseState[path] = (action === 'collapse');

            // Increment render key to trigger Alpine re-render
            this.jsonRenderKey++;
        },

        startResize(event) {
            this.isResizing = true;
            const startX = event.clientX;
            const startWidth = this.detailPanelWidth || this.getDefaultPanelWidth();
            const maxWidth = this.getMaxPanelWidth();

            const onMouseMove = (e) => {
                const delta = startX - e.clientX;
                const newWidth = Math.min(maxWidth, Math.max(this.minPanelWidth, startWidth + delta));
                this.detailPanelWidth = newWidth;
            };

            const onMouseUp = () => {
                this.isResizing = false;
                localStorage.setItem('logscope_panel_width', this.detailPanelWidth);
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        },

        resetPanelWidth() {
            this.detailPanelWidth = 0;
            localStorage.removeItem('logscope_panel_width');
        },

        formatTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        },

        formatRelativeTime(dateStr) {
            if (!dateStr) return '';
            const now = new Date();
            const d = new Date(dateStr);
            const diffMs = now - d;
            const diffSec = Math.floor(diffMs / 1000);
            const diffMin = Math.floor(diffSec / 60);
            const diffHour = Math.floor(diffMin / 60);
            const diffDay = Math.floor(diffHour / 24);

            if (diffSec < 60) return 'just now';
            if (diffMin < 60) return `${diffMin}m ago`;
            if (diffHour < 24) return `${diffHour}h ago`;
            if (diffDay === 1) return 'yesterday';
            if (diffDay < 7) return `${diffDay}d ago`;
            if (diffDay < 30) return `${Math.floor(diffDay / 7)}w ago`;
            return this.formatTime(dateStr);
        },

        formatFullDateTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
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
            this.searches = [{ field: 'any', value: '', exclude: false }];
            this.searchMode = 'and';
            this.filters = {
                levels: [],
                excludeLevels: [],
                channels: [],
                excludeChannels: [],
                httpMethods: [],
                excludeHttpMethods: [],
                statuses: [],
                from: '',
                to: '',
                trace_id: '',
                user_id: '',
                ip_address: '',
                url: ''
            };
            this.page = 1;
            this.fetchLogs();
        },

        applyQuickFilter(index) {
            this.clearFilters();
            const filter = this.quickFilters[index];
            if (!filter) return;

            // Handle levels
            if (filter.levels && Array.isArray(filter.levels)) {
                this.filters.levels = filter.levels;
            }

            // Handle statuses
            if (filter.statuses && Array.isArray(filter.statuses)) {
                this.filters.statuses = filter.statuses;
            }

            // Handle time ranges
            if (filter.from) {
                const parsed = this.parseRelativeTime(filter.from);
                this.filters.from = parsed.from;
                if (parsed.to) this.filters.to = parsed.to;
            }
            if (filter.to) {
                const parsed = this.parseRelativeTime(filter.to);
                this.filters.to = parsed.from;
            }

            this.fetchLogs();
        },

        parseRelativeTime(timeStr) {
            const now = new Date();
            const todayDate = now.toISOString().split('T')[0];

            // Handle 'today' keyword
            if (timeStr === 'today') {
                return {
                    from: todayDate + 'T00:00',
                    to: todayDate + 'T23:59'
                };
            }

            // Handle relative times like '-1 hour', '-4 hours', '-7 days'
            const match = timeStr.match(/^-(\d+)\s*(hour|hours|day|days|week|weeks|month|months)$/i);
            if (match) {
                const amount = parseInt(match[1]);
                const unit = match[2].toLowerCase();
                let ms = 0;
                if (unit.startsWith('hour')) ms = amount * 60 * 60 * 1000;
                else if (unit.startsWith('day')) ms = amount * 24 * 60 * 60 * 1000;
                else if (unit.startsWith('week')) ms = amount * 7 * 24 * 60 * 60 * 1000;
                else if (unit.startsWith('month')) ms = amount * 30 * 24 * 60 * 60 * 1000;
                return { from: new Date(now.getTime() - ms).toISOString().slice(0, 16) };
            }

            return { from: timeStr }; // Return as-is if not a relative time
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
        },

        handleKeydown(event) {
            // Ignore if typing in an input, textarea, or select
            const tag = event.target.tagName.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return;
            }

            // Ignore if modifier keys are pressed (except shift for ?)
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            switch (event.key) {
                case '/':
                    event.preventDefault();
                    this.$refs.searchInput?.focus();
                    break;
                case 'j':
                    event.preventDefault();
                    this.selectNextLog();
                    break;
                case 'k':
                    event.preventDefault();
                    this.selectPrevLog();
                    break;
                case '?':
                    event.preventDefault();
                    this.showKeyboardHelp = !this.showKeyboardHelp;
                    break;
                case 'Enter':
                    event.preventDefault();
                    if (this.selectedLog && !this.detailPanelWidth) {
                        this.detailPanelWidth = 500;
                        localStorage.setItem('logscope_panel_width', '500');
                    }
                    break;
                case 'd':
                    event.preventDefault();
                    this.darkMode = !this.darkMode;
                    break;
                case 'c':
                    event.preventDefault();
                    this.clearFilters();
                    break;
                case 'n':
                    event.preventDefault();
                    if (this.selectedLog && this.features.notes && this.detailPanelWidth) {
                        this.$dispatch('focus-note');
                    }
                    break;
                default:
                    // Check for status shortcuts
                    if (this.shortcuts[event.key]) {
                        event.preventDefault();
                        this.filters.statuses = [this.shortcuts[event.key]];
                        this.fetchLogs();
                    }
                    break;
            }
        },

        selectNextLog() {
            if (this.logs.length === 0) return;

            if (!this.selectedLog) {
                this.selectedLog = this.logs[0];
            } else {
                const currentIndex = this.logs.findIndex(log => log.id === this.selectedLog.id);
                if (currentIndex < this.logs.length - 1) {
                    this.selectedLog = this.logs[currentIndex + 1];
                }
            }
            this.scrollToSelectedLog();
        },

        selectPrevLog() {
            if (this.logs.length === 0) return;

            if (!this.selectedLog) {
                this.selectedLog = this.logs[0];
            } else {
                const currentIndex = this.logs.findIndex(log => log.id === this.selectedLog.id);
                if (currentIndex > 0) {
                    this.selectedLog = this.logs[currentIndex - 1];
                }
            }
            this.scrollToSelectedLog();
        },

        scrollToSelectedLog() {
            this.$nextTick(() => {
                const selectedRow = document.querySelector('.log-row.selected');
                if (selectedRow) {
                    selectedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            });
        }
    }
}
</script>
@endsection
