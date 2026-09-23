<!-- Issue Panel — the group's triage state and its recent occurrences (#29) -->
<div x-show="selectedGroup && !selectedLog" x-cloak
    class="logscope-detail-panel flex-shrink-0 surface-1 border-l border-[var(--border)] flex flex-col overflow-hidden relative"
    :style="screenWidth >= 768 ? { width: (detailPanelWidth || getDefaultPanelWidth()) + 'px' } : {}"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-[0.98]"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-[0.98]">
    <!-- Resize Handle -->
    <div class="absolute left-0 top-0 bottom-0 w-1 cursor-col-resize hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors z-10 hidden md:block"
        :class="isResizing ? 'bg-[var(--accent)] shadow-[0_0_10px_var(--accent-glow)]' : 'bg-transparent hover:bg-[rgba(var(--accent-rgb),0.5)]'"
        @mousedown.prevent="startResize($event)"></div>

    <!-- Panel Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--border)]">
        <h3 class="font-semibold text-[var(--text-primary)]">Issue</h3>
        <button @click="closeGroupPanel()"
            class="p-1 rounded text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors"
            title="Close panel">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <template x-if="selectedGroup">
        <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-4">
            <!-- Regression notice. The count alone doesn't say that this was
                 closed and came back, which is the thing worth acting on. -->
            <div x-show="selectedGroup.regressed_at" x-cloak
                class="flex items-start gap-3 p-3 rounded-lg bg-orange-500/10 border border-orange-500/30">
                <svg class="w-5 h-5 text-orange-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 005 19z"/>
                </svg>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-orange-300">This issue came back</p>
                    <p class="mt-0.5 text-xs text-orange-200/70 font-mono">
                        Resolved, then happened again
                        <span x-text="formatRelativeTime(selectedGroup.regressed_at)"></span>
                    </p>
                </div>
            </div>

            <!-- Message -->
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="level-badge" :class="'level-' + selectedGroup.level" x-text="selectedGroup.level"></span>
                    <span class="text-xs text-[var(--text-muted)] font-mono" x-text="selectedGroup.channel"></span>
                </div>
                <p class="text-sm text-[var(--text-primary)] break-words whitespace-pre-wrap font-mono"
                    x-text="selectedGroup.sample_message"></p>
            </div>

            <!-- Meta -->
            <div class="grid grid-cols-3 gap-3">
                <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono">Events</p>
                    <p class="mt-1 text-sm font-medium text-[var(--text-primary)] tabular-nums font-mono"
                        x-text="selectedGroup.occurrence_count?.toLocaleString()"></p>
                </div>
                <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono">First seen</p>
                    <p class="mt-1 text-sm font-medium text-[var(--text-primary)] font-mono"
                        :title="formatFullDateTime(selectedGroup.first_seen_at)"
                        x-text="formatRelativeTime(selectedGroup.first_seen_at)"></p>
                </div>
                <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono">Last seen</p>
                    <p class="mt-1 text-sm font-medium text-[var(--text-primary)] font-mono"
                        :title="formatFullDateTime(selectedGroup.last_seen_at)"
                        x-text="formatRelativeTime(selectedGroup.last_seen_at)"></p>
                </div>
            </div>

            <!-- Status -->
            <template x-if="features.status">
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono mb-2">Status</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="status in statuses" :key="status.value">
                            <button @click="setGroupStatus(status.value)"
                                class="px-2.5 h-8 rounded-lg text-xs font-medium border transition-colors flex items-center gap-1.5"
                                :class="selectedGroup.status === status.value
                                    ? 'bg-[rgba(var(--accent-rgb),0.15)] text-[var(--accent)] border-[rgba(var(--accent-rgb),0.4)]'
                                    : 'bg-[var(--surface-2)] text-[var(--text-muted)] border-[var(--border)] hover:text-[var(--text-primary)]'">
                                <span class="w-2 h-2 rounded-full" :class="'bg-' + status.color + '-500'"></span>
                                <span x-text="status.label"></span>
                                <span x-show="status.shortcut" class="opacity-50 font-mono" x-text="status.shortcut"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Note -->
            <template x-if="features.notes">
                <div x-data="{ editing: false, noteText: '' }">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono mb-2">Note</p>
                    <template x-if="!editing">
                        <button @click="noteText = selectedGroup.note || ''; editing = true"
                            class="w-full text-left p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] hover:border-[var(--text-muted)] transition-colors">
                            <span x-show="selectedGroup.note" class="text-sm text-[var(--text-secondary)] whitespace-pre-wrap" x-text="selectedGroup.note"></span>
                            <span x-show="!selectedGroup.note" class="text-sm text-[var(--text-muted)] italic">Add a note…</span>
                        </button>
                    </template>
                    <template x-if="editing">
                        <div class="space-y-2">
                            <textarea x-model="noteText" rows="3"
                                class="w-full p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)]"></textarea>
                            <div class="flex gap-2">
                                <button @click="updateGroupNote(noteText); editing = false"
                                    class="h-8 px-3 rounded-lg text-sm font-medium bg-[rgba(var(--accent-rgb),0.15)] text-[var(--accent)] border border-[rgba(var(--accent-rgb),0.4)]">
                                    Save
                                </button>
                                <button @click="editing = false"
                                    class="h-8 px-3 rounded-lg text-sm font-medium btn-ghost">Cancel</button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Occurrences -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--text-muted)] font-mono">Recent occurrences</p>
                    <span class="text-[10px] text-[var(--text-muted)] font-mono"
                        x-text="groupEntries.length + ' of ' + (groupEntriesMeta.occurrence_count || 0).toLocaleString()"></span>
                </div>

                <div x-show="groupEntriesLoading && groupEntries.length === 0" class="py-4 text-center text-sm text-[var(--text-muted)] font-mono">
                    Loading…
                </div>

                <div class="space-y-1">
                    <template x-for="entry in groupEntries" :key="entry.id">
                        <button @click="selectedLog = entry; ensureLogDetailLoaded(entry)"
                            class="w-full text-left px-3 py-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] hover:border-[var(--text-muted)] transition-colors">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs text-[var(--text-muted)] tabular-nums font-mono shrink-0"
                                    :title="formatFullDateTime(entry.occurred_at)"
                                    x-text="formatRelativeTime(entry.occurred_at)"></span>
                                <span x-show="entry.trace_id" class="text-[10px] text-[var(--text-muted)] truncate font-mono" x-text="entry.trace_id"></span>
                            </div>
                            <p class="mt-0.5 text-xs text-[var(--text-secondary)] truncate" x-text="entry.message_preview || entry.message"></p>
                        </button>
                    </template>
                </div>

                <button x-show="groupEntriesMeta.has_next" @click="loadMoreGroupEntries()"
                    :disabled="groupEntriesLoading"
                    class="mt-2 w-full h-8 rounded-lg text-sm font-medium btn-ghost disabled:opacity-30">
                    <span x-text="groupEntriesLoading ? 'Loading…' : 'Load more'"></span>
                </button>
            </div>
        </div>
    </template>
</div>
