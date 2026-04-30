@if (! empty($failureBanner))
    <div
        x-data="{
            banner: window.logScopeConfig.failureBanner,
            async dismiss() {
                this.banner = null;
                try {
                    await fetch(window.logScopeConfig.routes.dismissFailures, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': window.logScopeConfig.csrfToken,
                            'Accept': 'application/json',
                        },
                    });
                } catch (e) {
                    // best-effort — banner already hidden client-side
                }
            }
        }"
        x-show="banner"
        x-cloak
        class="px-4 py-2 bg-red-500/10 border-b border-red-500/30 text-sm font-mono flex items-start gap-3"
        role="alert"
    >
        <svg class="w-4 h-4 mt-0.5 shrink-0 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
        </svg>
        <div class="flex-1 min-w-0">
            <div class="text-red-300">
                LogScope has had
                <strong x-text="banner?.count + ' write failure' + (banner?.count === 1 ? '' : 's')"></strong>
                recently. Last error:
                <span class="text-red-200" x-text="'[' + (banner?.last_class ?? 'Unknown') + ']'"></span>
                <span class="text-red-200/80" x-text="banner?.last_message"></span>
            </div>
            <div class="text-red-400/70 text-xs mt-0.5">
                Source: <span x-text="banner?.last_where || 'unknown'"></span>
                @ <span x-text="banner?.last_at"></span>
                · Check your server / php-fpm error log for full details.
            </div>
        </div>
        <button
            type="button"
            @click="dismiss()"
            class="ml-auto text-red-400 hover:text-red-300 shrink-0"
            aria-label="Dismiss banner"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
@endif
