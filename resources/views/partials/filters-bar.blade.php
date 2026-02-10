<!-- Active Filters Bar -->
<div x-show="hasActiveFilters()" x-cloak
    class="flex items-center gap-2 px-4 py-2 bg-[rgba(var(--accent-rgb),0.05)] border-b border-[rgba(var(--accent-rgb),0.2)]">
    <span class="text-xs font-medium font-mono text-[var(--accent)] uppercase tracking-wider">Filters:</span>
    <div class="flex flex-wrap gap-1">
        <template x-for="level in filters.levels" :key="'inc-' + level">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-[rgba(var(--accent-rgb),0.2)] text-[var(--accent)] ring-1 ring-[rgba(var(--accent-rgb),0.3)]">
                <span x-text="level" class="capitalize"></span>
                <button @click="clearLevelFilter(level)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="level in filters.excludeLevels" :key="'exc-' + level">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-500/20 text-red-300 ring-1 ring-red-500/30">
                <span class="line-through capitalize" x-text="level"></span>
                <button @click="clearLevelFilter(level)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="channel in filters.channels" :key="'inc-' + channel">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-cyan-500/20 text-cyan-300 ring-1 ring-cyan-500/30">
                <span x-text="channel"></span>
                <button @click="clearChannelFilter(channel)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="channel in filters.excludeChannels" :key="'exc-' + channel">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-red-500/20 text-red-300 ring-1 ring-red-500/30">
                <span class="line-through" x-text="channel"></span>
                <button @click="clearChannelFilter(channel)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="method in filters.httpMethods" :key="'inc-http-' + method">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-violet-500/20 text-violet-300 ring-1 ring-violet-500/30">
                <span x-text="method"></span>
                <button @click="clearHttpMethodFilter(method)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="method in filters.excludeHttpMethods" :key="'exc-http-' + method">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-red-500/20 text-red-300 ring-1 ring-red-500/30">
                <span class="line-through" x-text="method"></span>
                <button @click="clearHttpMethodFilter(method)" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.from">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-500/20 text-amber-300 ring-1 ring-amber-500/30">
                <span>From:</span>
                <span x-text="filters.from"></span>
                <button @click="filters.from = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.to">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-500/20 text-amber-300 ring-1 ring-amber-500/30">
                <span>To:</span>
                <span x-text="filters.to"></span>
                <button @click="filters.to = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.trace_id">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30">
                <span>Trace:</span>
                <span x-text="filters.trace_id" class="max-w-[80px] truncate"></span>
                <button @click="filters.trace_id = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.user_id">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30">
                <span>User:</span>
                <span x-text="filters.user_id"></span>
                <button @click="filters.user_id = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.ip_address">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30">
                <span>IP:</span>
                <span x-text="filters.ip_address"></span>
                <button @click="filters.ip_address = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-if="filters.url">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30">
                <span>URL:</span>
                <span x-text="filters.url" class="max-w-[120px] truncate"></span>
                <button @click="filters.url = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
        <template x-for="(search, idx) in searches.filter(s => s.value)" :key="'search-' + idx">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium font-mono"
                :class="search.exclude
                    ? 'bg-red-500/20 text-red-300 ring-1 ring-red-500/30'
                    : 'bg-[var(--surface-3)] text-[var(--text-secondary)] ring-1 ring-[var(--border)]'">
                <span x-show="search.exclude" class="font-bold">NOT</span>
                <span x-text="search.field === 'any' ? '' : search.field + ':'"></span>
                <span x-text="search.value" class="max-w-[100px] truncate"></span>
                <button @click="search.value = ''; page = 1; fetchLogs()" class="hover:text-white">&times;</button>
            </span>
        </template>
    </div>
</div>
