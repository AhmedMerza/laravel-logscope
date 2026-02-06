<!-- Toast Notification -->
<div x-show="toast.visible" x-cloak
    style="position: fixed; bottom: 24px; right: 24px; z-index: 9999;"
    class="max-w-md"
    x-transition:enter="ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2 scale-95"
    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
    x-transition:leave="ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
    x-transition:leave-end="opacity-0 translate-y-2 scale-95">
    <div class="toast flex items-center gap-3 px-5 py-4 rounded-xl"
        :class="{
            'bg-red-500 text-white border border-red-400': toast.type === 'error',
            'bg-amber-500 text-white border border-amber-400': toast.type === 'warning',
            'bg-[var(--accent)] text-white border border-[var(--accent)]': toast.type === 'success',
            'bg-cyan-500 text-white border border-cyan-400': toast.type === 'info'
        }">
        <!-- Icon -->
        <div class="flex-shrink-0">
            <template x-if="toast.type === 'error'">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </template>
            <template x-if="toast.type === 'warning'">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </template>
            <template x-if="toast.type === 'success'">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </template>
            <template x-if="toast.type === 'info'">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </template>
        </div>
        <!-- Message -->
        <p class="text-sm font-semibold" x-text="toast.message"></p>
        <!-- Close button -->
        <button @click="hideToast()" class="flex-shrink-0 ml-auto -mr-1 p-1 rounded hover:bg-white/20 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</div>
