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
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="cancelDelete()"></div>
    <!-- Dialog -->
    <div class="relative glass-panel rounded-xl shadow-2xl max-w-sm w-full mx-4 p-6"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95">
        <div class="flex items-center gap-3 mb-4">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-500/20 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">Delete Log Entry</h3>
                <p class="text-sm text-[var(--text-muted)]">This action cannot be undone.</p>
            </div>
        </div>
        <div class="flex gap-3">
            <button @click="cancelDelete()"
                class="btn-ghost flex-1 h-10 rounded-lg text-sm font-medium">
                Cancel
            </button>
            <button @click="deleteLog()"
                class="flex-1 h-10 rounded-lg text-sm font-medium text-white bg-red-500 hover:bg-red-600 shadow-lg shadow-red-500/30 transition-colors">
                Delete
            </button>
        </div>
    </div>
</div>
