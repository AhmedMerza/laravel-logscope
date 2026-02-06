<!-- Detail Panel -->
<div x-show="selectedLog" x-cloak
    class="flex-shrink-0 surface-1 border-l border-[var(--border)] flex flex-col overflow-hidden relative"
    :style="{ width: (detailPanelWidth || getDefaultPanelWidth()) + 'px' }"
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="translate-x-4"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="translate-x-4">
    <!-- Resize Handle -->
    <div class="absolute left-0 top-0 bottom-0 w-1 cursor-col-resize hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors z-10"
        :class="isResizing ? 'bg-[var(--accent)] shadow-[0_0_10px_var(--accent-glow)]' : 'bg-transparent hover:bg-[rgba(var(--accent-rgb),0.5)]'"
        @mousedown.prevent="startResize($event)"></div>
    <!-- Panel Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--border)]">
        <h3 class="font-semibold text-[var(--text-primary)]">Log Details</h3>
        <div class="flex items-center gap-1">
            <button x-show="detailPanelWidth" @click="resetPanelWidth()"
                class="p-1 rounded text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors"
                title="Reset panel width">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
            </button>
            <button @click="closePanel()"
                class="p-1 rounded text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors"
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
            <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                <p class="section-header mb-1">Level</p>
                <p class="mt-1">
                    <span class="level-badge" :class="'level-' + selectedLog?.level" x-text="selectedLog?.level"></span>
                </p>
            </div>
            <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] overflow-hidden">
                <p class="section-header mb-1">Channel</p>
                <p class="mt-1 text-sm font-medium font-mono text-[var(--text-primary)] truncate" :title="selectedLog?.channel" x-text="selectedLog?.channel || '-'"></p>
            </div>
            <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                <p class="section-header mb-1">Time</p>
                <p class="mt-1 text-sm font-medium text-[var(--text-primary)] font-mono" x-text="formatRelativeTime(selectedLog?.occurred_at)"></p>
                <p class="mt-0.5 text-xs text-[var(--text-muted)] font-mono" x-text="formatTime(selectedLog?.occurred_at)"></p>
            </div>
        </div>

        <!-- Source -->
        <div x-show="selectedLog?.source">
            <p class="section-header mb-2">Source</p>
            <div class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] font-mono text-sm text-cyan-400 break-all"
                x-text="formatSource(selectedLog?.source, selectedLog?.source_line)"></div>
        </div>

        <!-- Message -->
        <div>
            <p class="section-header mb-2">Message</p>
            <pre class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-sm text-[var(--text-primary)] whitespace-pre-wrap break-words overflow-x-auto"
                x-text="selectedLog?.message"></pre>
        </div>

        <!-- Context -->
        <div x-show="selectedLog?.context && Object.keys(selectedLog?.context || {}).length > 0">
            <p class="section-header mb-2">Context</p>
            <pre class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-sm overflow-x-auto json-highlight"
                x-ref="jsonContext"
                @click="handleJsonToggle($event)"
                x-html="renderJsonContext()"></pre>
        </div>

        <!-- Request Context -->
        <div x-show="selectedLog?.trace_id || selectedLog?.user_id || selectedLog?.ip_address || selectedLog?.url">
            <p class="section-header mb-2">Request Context</p>
            <div class="space-y-2">
                <!-- Trace ID -->
                <div x-show="selectedLog?.trace_id">
                    <button @click="filterByTraceId(selectedLog?.trace_id)"
                        class="w-full p-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] border-l-4 border-l-violet-500 hover:bg-[var(--surface-3)] text-left break-all transition-colors group">
                        <span class="text-xs text-[var(--text-muted)] block">Trace ID</span>
                        <span x-text="selectedLog?.trace_id" class="text-xs font-mono text-violet-400 group-hover:text-violet-300"></span>
                    </button>
                </div>
                <!-- User ID -->
                <div x-show="selectedLog?.user_id">
                    <button @click="filterByUserId(selectedLog?.user_id)"
                        class="w-full p-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] border-l-4 border-l-cyan-500 hover:bg-[var(--surface-3)] text-left transition-colors group">
                        <span class="text-xs text-[var(--text-muted)] block">User ID</span>
                        <span x-text="selectedLog?.user_id" class="text-sm font-mono text-cyan-400 group-hover:text-cyan-300"></span>
                    </button>
                </div>
                <!-- IP Address -->
                <div x-show="selectedLog?.ip_address">
                    <button @click="filterByIpAddress(selectedLog?.ip_address)"
                        class="w-full p-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] border-l-4 border-l-amber-500 hover:bg-[var(--surface-3)] text-left transition-colors group">
                        <span class="text-xs text-[var(--text-muted)] block">IP Address</span>
                        <span x-text="selectedLog?.ip_address" class="text-sm font-mono text-amber-400 group-hover:text-amber-300"></span>
                    </button>
                </div>
                <!-- HTTP Method & URL -->
                <div x-show="selectedLog?.http_method || selectedLog?.url" class="p-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                    <span class="text-xs text-[var(--text-muted)] block mb-1">Request</span>
                    <div class="flex items-center gap-2 text-sm">
                        <span x-show="selectedLog?.http_method"
                            class="px-2 py-0.5 rounded text-xs font-bold font-mono"
                            :class="{
                                'bg-[rgba(var(--accent-rgb),0.2)] text-[var(--accent)]': selectedLog?.http_method === 'GET',
                                'bg-blue-500/20 text-blue-400': selectedLog?.http_method === 'POST',
                                'bg-amber-500/20 text-amber-400': selectedLog?.http_method === 'PUT' || selectedLog?.http_method === 'PATCH',
                                'bg-red-500/20 text-red-400': selectedLog?.http_method === 'DELETE',
                                'bg-[var(--surface-3)] text-[var(--text-secondary)]': !['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(selectedLog?.http_method)
                            }"
                            x-text="selectedLog?.http_method"></span>
                        <span x-show="selectedLog?.url" class="font-mono text-[var(--text-secondary)] break-all text-xs" x-text="selectedLog?.url"></span>
                    </div>
                </div>
                <!-- User Agent -->
                <div x-show="selectedLog?.user_agent" class="p-2 rounded-lg bg-[var(--surface-2)] border border-[var(--border)]">
                    <span class="text-xs text-[var(--text-muted)] block">User Agent</span>
                    <span class="text-xs font-mono text-[var(--text-muted)] break-all" x-text="selectedLog?.user_agent"></span>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div x-show="features.status && selectedLog?.status && selectedLog?.status !== 'open'">
            <p class="section-header mb-2">Status</p>
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
                <p class="mt-1 text-xs font-mono" :class="getStatusMutedTextClass(selectedLog?.status)">
                    <span x-text="formatRelativeTime(selectedLog?.status_changed_at)"></span>
                    <span x-show="selectedLog?.status_changed_by"> by <span x-text="selectedLog?.status_changed_by"></span></span>
                </p>
            </div>
        </div>

        <!-- Note -->
        <div x-show="features.notes" x-data="{ editing: false, noteText: '' }" x-effect="if (selectedLog) { editing = false; noteText = ''; }"
            @focus-note.window="editing = true; noteText = selectedLog?.note || ''; $nextTick(() => $refs.noteInput?.focus())">
            <p class="section-header mb-2">Note</p>
            <div x-show="!editing" @click="editing = true; noteText = selectedLog?.note || ''; $nextTick(() => $refs.noteInput.focus())"
                class="p-3 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] min-h-[60px] cursor-pointer hover:bg-[var(--surface-3)] transition-colors">
                <p x-show="selectedLog?.note" class="text-sm text-[var(--text-secondary)] whitespace-pre-wrap" x-text="selectedLog?.note"></p>
                <p x-show="!selectedLog?.note" class="text-sm text-[var(--text-muted)] italic">Click to add a note...</p>
            </div>
            <div x-show="editing" class="space-y-2">
                <textarea x-model="noteText" x-ref="noteInput"
                    class="w-full p-3 rounded-lg bg-[var(--surface-2)] text-sm text-[var(--text-primary)] border border-[var(--border)] focus:outline-none focus:ring-2 focus:ring-[rgba(var(--accent-rgb),0.5)] focus:border-[var(--accent)] resize-none"
                    rows="3"
                    placeholder="Add a note about this log entry..."></textarea>
                <div class="flex gap-2">
                    <button @click="updateNote(noteText); editing = false"
                        class="btn-primary px-3 py-1.5 rounded text-xs">
                        Save
                    </button>
                    <button @click="editing = false"
                        class="btn-ghost px-3 py-1.5 rounded text-xs">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel Footer -->
    <div class="flex items-center gap-2 px-4 py-3 border-t border-[var(--border)]">
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
                    class="absolute bottom-full left-0 right-0 mb-1 glass-panel rounded-lg shadow-xl overflow-hidden z-50">
                    <template x-for="status in statuses" :key="status.value">
                        <button @click="setStatus(status.value); statusOpen = false"
                            class="w-full px-3 py-2 text-sm text-left hover:bg-[var(--surface-2)] flex items-center gap-2"
                            :class="{ 'bg-[rgba(var(--accent-rgb),0.1)]': selectedLog?.status === status.value }">
                            <span class="w-2 h-2 rounded-full" :class="'bg-' + status.color + '-500'"></span>
                            <span x-text="status.label" class="text-[var(--text-secondary)]"></span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
        <button @click="confirmDelete()"
            class="flex-1 h-9 rounded-lg text-sm font-medium text-red-400 bg-red-500/10 hover:bg-red-500/20 border border-red-500/30 transition-colors">
            Delete
        </button>
        <button @click="closePanel()"
            class="btn-ghost flex-1 h-9 rounded-lg text-sm font-medium">
            Close
        </button>
    </div>
</div>
