<!-- Log List -->
<div class="flex-1 flex flex-col min-w-0 surface-0">
    <!-- Loading -->
    <div x-show="loading" class="flex-1 flex items-center justify-center">
        <div class="flex items-center gap-3 text-[var(--text-muted)]">
            <svg class="w-5 h-5 animate-spin text-[var(--accent)]" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="text-sm font-mono">Loading logs...</span>
        </div>
    </div>

    <!-- Error Alert -->
    <div x-show="error" x-cloak class="mx-4 mt-4">
        <div class="flex items-center gap-3 p-4 bg-red-500/10 border border-red-500/30 rounded-lg">
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-red-300" x-text="error"></p>
            <button @click="error = null" class="ml-auto text-red-400 hover:text-red-300 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Empty State -->
    <div x-show="!loading && !error && logs.length === 0" x-cloak class="flex-1 flex items-center justify-center">
        <div class="text-center">
            <svg class="w-16 h-16 mx-auto empty-state-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-4 text-sm font-medium text-[var(--text-primary)]">No logs found</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">Try adjusting your filters</p>
        </div>
    </div>

    <!-- Log Table -->
    <div x-show="!loading && logs.length > 0" x-cloak class="flex-1 overflow-auto custom-scrollbar">
        <table class="log-table w-full">
            <thead>
                <tr>
                    <th class="w-[3px] p-0"></th>
                    <th class="px-4 py-3 text-left w-40">Time</th>
                    <th class="px-4 py-3 text-left w-24">Level</th>
                    <th class="px-4 py-3 text-left">Message</th>
                    <th class="px-4 py-3 text-left w-28">Channel</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="log in logs" :key="log.id">
                    <tr class="log-row cursor-pointer"
                        :class="{
                            'selected': selectedLog?.id === log.id,
                            'opacity-50': log.status === 'resolved' || log.status === 'ignored'
                        }"
                        @click="selectLog(log)">
                        <td class="p-0 relative">
                            <div class="level-indicator h-full absolute inset-y-0 left-0" :class="'level-' + log.level"></div>
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
                            <span class="text-sm text-[var(--text-muted)] tabular-nums whitespace-nowrap cursor-help font-mono"
                                x-text="formatRelativeTime(log.occurred_at)"></span>
                            <div class="absolute left-0 bottom-full mb-1 px-2 py-1 text-xs bg-[var(--surface-3)] text-[var(--text-primary)] rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none z-20 font-mono"
                                x-text="formatFullDateTime(log.occurred_at)"></div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="level-badge" :class="'level-' + log.level" x-text="log.level"></span>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm text-[var(--text-primary)] truncate" :style="{ maxWidth: getMessagePreviewWidth() + 'px' }" x-text="log.message_preview || log.message"></p>
                            <p x-show="log.source" class="mt-0.5 text-xs text-[var(--text-muted)] truncate font-mono" x-text="formatSource(log.source, log.source_line)"></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs text-[var(--text-muted)] truncate block max-w-[120px] font-mono" :title="log.channel" x-text="log.channel"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div x-show="!loading && logs.length > 0" x-cloak
        class="flex items-center justify-between px-4 py-3 surface-1 border-t border-[var(--border)]">
        <p class="text-sm text-[var(--text-muted)] font-mono">
            Showing <span class="font-medium text-[var(--text-primary)]" x-text="((meta.current_page - 1) * meta.per_page) + 1"></span>
            to <span class="font-medium text-[var(--text-primary)]" x-text="Math.min(meta.current_page * meta.per_page, meta.total)"></span>
            of <span class="font-medium text-[var(--accent)]" x-text="meta.total?.toLocaleString()"></span>
        </p>
        <div class="flex items-center gap-1">
            <button @click="prevPage()" :disabled="meta.current_page <= 1"
                class="btn-ghost h-8 px-3 rounded text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed">
                Previous
            </button>
            <span class="px-3 text-sm text-[var(--text-muted)] font-mono">
                <span x-text="meta.current_page" class="text-[var(--accent)]"></span> / <span x-text="meta.last_page"></span>
            </span>
            <button @click="nextPage()" :disabled="meta.current_page >= meta.last_page"
                class="btn-ghost h-8 px-3 rounded text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed">
                Next
            </button>
        </div>
    </div>
</div>
