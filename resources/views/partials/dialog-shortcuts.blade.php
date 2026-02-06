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
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showKeyboardHelp = false"></div>
    <!-- Dialog -->
    <div class="relative glass-panel rounded-xl shadow-2xl max-w-sm w-full mx-4 p-6"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-[var(--text-primary)]">Keyboard Shortcuts</h3>
            <button @click="showKeyboardHelp = false"
                class="p-1 rounded text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-2)] transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="space-y-2">
            <h4 class="section-header pt-0">Navigation</h4>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Navigate down</span>
                <kbd>j</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Navigate up</span>
                <kbd>k</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Open detail panel</span>
                <kbd>Enter</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Close panel</span>
                <kbd>Esc</kbd>
            </div>

            <template x-if="Object.keys(shortcuts).length > 0">
                <div>
                    <h4 class="section-header pt-3">Filter by Status</h4>
                    <template x-for="(status, key) in shortcuts" :key="key">
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-sm text-[var(--text-secondary)] capitalize" x-text="getStatusLabel(status)"></span>
                            <kbd x-text="key"></kbd>
                        </div>
                    </template>
                </div>
            </template>

            <h4 class="section-header pt-3">Actions</h4>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Focus search</span>
                <kbd>/</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Focus note</span>
                <kbd>n</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Clear filters</span>
                <kbd>c</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Toggle dark mode</span>
                <kbd>d</kbd>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--text-secondary)]">Show this help</span>
                <kbd>?</kbd>
            </div>
        </div>
    </div>
</div>
