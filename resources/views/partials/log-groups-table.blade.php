<!-- Grouped List — one row per issue, however many times it fired (#29) -->
<div x-show="viewMode === 'grouped'" x-cloak class="flex-1 flex flex-col min-w-0 surface-0">
    <!-- Loading -->
    <div x-show="loading" class="flex-1 flex items-center justify-center">
        <div class="flex items-center gap-3 text-[var(--text-muted)]">
            <svg class="w-5 h-5 animate-spin text-[var(--accent)]" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="text-sm font-mono">Loading issues...</span>
        </div>
    </div>

    <!-- Empty State -->
    <div x-show="!loading && !error && groups.length === 0" x-cloak class="flex-1 flex items-center justify-center">
        <div class="text-center">
            <svg class="w-16 h-16 mx-auto empty-state-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            <p class="mt-4 text-sm font-medium text-[var(--text-primary)]">No issues found</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">Try adjusting your filters</p>
        </div>
    </div>

    <!-- Groups Table -->
    <div x-show="!loading && groups.length > 0" x-cloak class="flex-1 overflow-auto custom-scrollbar">
        <table class="log-table w-full">
            <thead>
                <tr>
                    <th class="w-[3px] p-0"></th>
                    <th class="px-2 py-3 text-left w-20 sm:px-4 sm:w-24">Level</th>
                    <th class="px-2 py-3 text-left sm:px-4">Issue</th>
                    <th class="px-2 py-3 text-right w-20 sm:px-4">Events</th>
                    <th class="px-4 py-3 text-left w-28 hidden md:table-cell">First seen</th>
                    <th class="px-4 py-3 text-left w-28 hidden sm:table-cell">Last seen</th>
                    <th class="px-4 py-3 text-left w-28 hidden lg:table-cell">Channel</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="group in groups" :key="group.id">
                    <tr class="log-row cursor-pointer"
                        :class="{
                            'selected': selectedGroup?.id === group.id,
                            'opacity-50': group.status === 'resolved' || group.status === 'ignored'
                        }"
                        @click="selectGroup(group)">
                        <td class="p-0 relative">
                            <div class="level-indicator h-full absolute inset-y-0 left-0" :class="'level-' + group.level"></div>
                            <div x-show="group.status && group.status !== 'open'" class="absolute top-1 left-1 w-3 h-3" :class="getStatusIconColor(group.status)" :title="getStatusLabel(group.status)">
                                <template x-if="group.status === 'resolved'">
                                    <svg fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                                <template x-if="group.status === 'investigating'">
                                    <svg fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                                <template x-if="group.status === 'ignored'">
                                    <svg fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                            </div>
                        </td>
                        <td class="px-2 py-3 sm:px-4">
                            <span class="level-badge" :class="'level-' + group.level" x-text="group.level"></span>
                        </td>
                        <td class="px-2 py-3 sm:px-4">
                            <div class="flex items-center gap-2">
                                <!-- A resolved issue that fired again. Without this it
                                     would read as something nobody has triaged yet. -->
                                <span x-show="group.regressed_at"
                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold font-mono uppercase tracking-wide bg-orange-500/20 text-orange-300 ring-1 ring-orange-500/40 shrink-0"
                                    title="Resolved, then happened again">
                                    Regressed
                                </span>
                                <p class="text-sm text-[var(--text-primary)] truncate"
                                    :style="{ maxWidth: getMessagePreviewWidth() + 'px' }"
                                    x-text="group.sample_message"></p>
                            </div>
                        </td>
                        <td class="px-2 py-3 sm:px-4 text-right">
                            <span class="text-sm font-medium tabular-nums font-mono text-[var(--text-secondary)]"
                                x-text="group.occurrence_count?.toLocaleString()"></span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <span class="text-sm text-[var(--text-muted)] tabular-nums whitespace-nowrap font-mono"
                                :title="formatFullDateTime(group.first_seen_at)"
                                x-text="formatRelativeTime(group.first_seen_at)"></span>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-[var(--text-muted)] tabular-nums whitespace-nowrap font-mono"
                                :title="formatFullDateTime(group.last_seen_at)"
                                x-text="formatRelativeTime(group.last_seen_at)"></span>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell">
                            <span class="text-xs text-[var(--text-muted)] truncate block max-w-[120px] font-mono" :title="group.channel" x-text="group.channel"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div x-show="!loading && groups.length > 0" x-cloak
        class="flex items-center justify-between px-3 sm:px-4 py-3 surface-1 border-t border-[var(--border)]">
        <p class="text-sm text-[var(--text-muted)] font-mono">
            <span class="font-medium text-[var(--text-primary)]"
                x-text="groupMeta.has_next_count ? groupMeta.count.toLocaleString() + '+' : groupMeta.count.toLocaleString()"></span>
            <span x-text="groupMeta.count === 1 ? 'issue' : 'issues'"></span>
        </p>
        <div class="flex items-center gap-1">
            <button @click="prevGroupPage()" :disabled="groupCursorStack.length === 0"
                class="btn-ghost h-8 px-2 sm:px-3 rounded text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1">
                <svg class="w-4 h-4 sm:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                <span class="hidden sm:inline">Previous</span>
            </button>
            <button @click="nextGroupPage()" :disabled="!groupMeta.has_next"
                class="btn-ghost h-8 px-2 sm:px-3 rounded text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1">
                <svg class="w-4 h-4 sm:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                <span class="hidden sm:inline">Next</span>
            </button>
        </div>
    </div>
</div>
