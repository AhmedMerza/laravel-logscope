/**
 * LogScope Alpine.js Component
 *
 * This is the main Alpine.js component that powers the LogScope dashboard.
 * Configuration is passed via the window.logScopeConfig object.
 */
function logScope() {
    const config = window.logScopeConfig || {};

    return {
        // === STATE ===
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
        quickFilters: config.quickFilters || [],
        features: config.features || {},
        jsonViewer: config.jsonViewer || { collapseThreshold: 5, autoCollapseKeys: [] },
        jsonCollapseState: {},
        jsonRenderKey: 0,
        _lastJsonLogId: null,
        statuses: config.statuses || [],
        shortcuts: config.shortcuts || {},
        searches: [{ field: 'any', value: '', exclude: false }],
        searchMode: 'and',
        useRegex: false,
        // Toast notifications
        toast: { message: '', type: 'error', visible: false },
        toastTimeout: null,
        // Error handling
        errorRedirecting: false,
        forbiddenRedirect: config.forbiddenRedirect || '/',
        unauthenticatedRedirect: config.unauthenticatedRedirect || '/login',
        // Routes
        routes: config.routes || {},
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

        // === INITIALIZATION ===
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

        // === SEARCH & FILTERS ===
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

        // === API CALLS ===
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
                if (this.useRegex) {
                    params.append('regex', '1');
                }

                const response = await fetch(`${this.routes.logs}?${params}`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) {
                    this.handleApiError(response, 'fetching logs');
                    this.logs = [];
                    this.meta = { current_page: 1, last_page: 1, per_page: 50, total: 0 };
                    return;
                }

                const data = await response.json();
                this.logs = data.data;
                this.meta = data.meta;
                this.error = null;
                this.syncFiltersToUrl();
            } catch (error) {
                this.handleNetworkError(error, 'fetching logs');
            } finally {
                this.loading = false;
            }
        },

        async fetchStats() {
            try {
                const response = await fetch(this.routes.stats, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    this.handleApiError(response, 'fetching stats');
                    return;
                }
                const data = await response.json();
                this.stats = data.data;
            } catch (error) {
                this.handleNetworkError(error, 'fetching stats');
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
                const response = await fetch(`${this.routes.apiBase}/logs/${this.selectedLog.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                });
                if (!response.ok) {
                    this.handleApiError(response, 'deleting log');
                    return;
                }
                this.showDeleteDialog = false;
                this.selectedLog = null;
                this.showToast('Log deleted successfully', 'success', 2000);
                await Promise.all([this.fetchLogs(), this.fetchStats()]);
            } catch (error) {
                this.handleNetworkError(error, 'deleting log');
            }
        },

        async setStatus(status, note = null) {
            if (!this.selectedLog) return;
            try {
                const body = { status };
                if (note) body.note = note;

                const response = await fetch(`${this.routes.apiBase}/logs/${this.selectedLog.id}/status`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(body)
                });
                if (!response.ok) {
                    this.handleApiError(response, 'updating status');
                    return;
                }
                const data = await response.json();
                this.selectedLog = data.data;
                await this.fetchLogs();
            } catch (error) {
                this.handleNetworkError(error, 'updating status');
            }
        },

        async updateNote(note) {
            if (!this.selectedLog) return;
            try {
                const response = await fetch(`${this.routes.apiBase}/logs/${this.selectedLog.id}/note`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ note })
                });
                if (!response.ok) {
                    this.handleApiError(response, 'saving note');
                    return;
                }
                const data = await response.json();
                this.selectedLog = data.data;
            } catch (error) {
                this.handleNetworkError(error, 'saving note');
            }
        },

        // === STATUS HELPERS ===
        getStatusLabel(status) {
            const found = this.statuses.find(s => s.value === status);
            return found ? found.label : (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Open');
        },

        getStatusIconColor(status) {
            const colors = {
                'open': 'text-[var(--text-muted)]',
                'investigating': 'text-amber-400',
                'resolved': 'text-[var(--accent)]',
                'ignored': 'text-[var(--text-muted)]'
            };
            return colors[status] || 'text-[var(--text-muted)]';
        },

        getStatusBgClass(status) {
            const classes = {
                'investigating': 'bg-amber-500/10 border-amber-500/30',
                'resolved': 'bg-[rgba(var(--accent-rgb),0.1)] border-[rgba(var(--accent-rgb),0.3)]',
                'ignored': 'bg-[var(--surface-2)] border-[var(--border)]'
            };
            return classes[status] || 'bg-[var(--surface-2)] border-[var(--border)]';
        },

        getStatusTextClass(status) {
            const classes = {
                'investigating': 'text-amber-400',
                'resolved': 'text-[var(--accent)]',
                'ignored': 'text-[var(--text-muted)]'
            };
            return classes[status] || 'text-[var(--text-secondary)]';
        },

        getStatusMutedTextClass(status) {
            const classes = {
                'investigating': 'text-amber-500/70',
                'resolved': 'text-[var(--accent)]/70',
                'ignored': 'text-[var(--text-muted)]'
            };
            return classes[status] || 'text-[var(--text-muted)]';
        },

        getStatusButtonClass(status) {
            const classes = {
                'open': 'text-[var(--text-secondary)] bg-[var(--surface-2)] hover:bg-[var(--surface-3)] border border-[var(--border)]',
                'investigating': 'text-amber-400 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30',
                'resolved': 'text-[var(--accent)] bg-[rgba(var(--accent-rgb),0.1)] hover:bg-[rgba(var(--accent-rgb),0.2)] border border-[rgba(var(--accent-rgb),0.3)]',
                'ignored': 'text-[var(--text-muted)] bg-[var(--surface-2)] hover:bg-[var(--surface-3)] border border-[var(--border)]'
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

        // === JSON RENDERING ===
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

        // === UI HELPERS ===
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

        // Toast notifications
        showToast(message, type = 'error', duration = 4000) {
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toast = { message, type, visible: true };
            this.toastTimeout = setTimeout(() => {
                this.toast.visible = false;
            }, duration);
        },

        hideToast() {
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toast.visible = false;
        },

        // API error handling
        handleApiError(response, context = 'request') {
            // Prevent multiple redirects
            if (this.errorRedirecting) return;

            const status = response.status;

            // Auth errors - redirect
            if (status === 401 || status === 419) {
                this.errorRedirecting = true;
                this.showToast('Session expired. Redirecting...', 'warning', 3000);
                setTimeout(() => {
                    window.location.href = this.unauthenticatedRedirect || '/login';
                }, 2000);
                return;
            }

            if (status === 403) {
                this.errorRedirecting = true;
                this.showToast('Access denied. Redirecting...', 'warning', 3000);
                setTimeout(() => {
                    window.location.href = this.forbiddenRedirect || '/';
                }, 2000);
                return;
            }

            // Rate limiting
            if (status === 429) {
                this.showToast('Too many requests. Please wait a moment.', 'warning');
                return;
            }

            // Server errors
            if (status >= 500) {
                this.showToast('Server error. Please try again later.', 'error');
                return;
            }

            // Generic error
            this.showToast(`Failed to complete ${context}. Please try again.`, 'error');
        },

        // Network error handling
        handleNetworkError(error, context = 'request') {
            if (this.errorRedirecting) return;

            console.error(`Network error during ${context}:`, error);

            if (!navigator.onLine) {
                this.showToast('You appear to be offline. Please check your connection.', 'warning');
            } else {
                this.showToast('Connection error. Please try again.', 'error');
            }
        },

        // Formatting helpers
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

        resetFilters() {
            this.searches = [{ field: 'any', value: '', exclude: false }];
            this.searchMode = 'and';
            this.useRegex = false;
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
        },

        clearFilters() {
            this.resetFilters();
            this.fetchLogs();
        },

        applyQuickFilter(index) {
            this.resetFilters();
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

        // Keyboard navigation
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

// Make the function available globally
window.logScope = logScope;
