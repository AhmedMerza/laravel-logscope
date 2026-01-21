<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('logscope-dark') === 'true' }"
    x-init="$watch('darkMode', val => localStorage.setItem('logscope-dark', val))"
    :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LogScope</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%232563eb'/><path d='M8 10h16M8 16h12M8 22h14' stroke='white' stroke-width='2.5' stroke-linecap='round'/></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        slate: {
                            850: '#172033',
                        }
                    }
                }
            }
        }
    </script>
    @php
        $theme = config('logscope.theme', []);
        $levelColors = $theme['levels'] ?? [];
    @endphp
    <style>
        [x-cloak] { display: none !important; }

        :root {
            --color-debug: {{ $levelColors['debug']['bg'] ?? '#64748b' }};
            --color-info: {{ $levelColors['info']['bg'] ?? '#0ea5e9' }};
            --color-notice: {{ $levelColors['notice']['bg'] ?? '#8b5cf6' }};
            --color-warning: {{ $levelColors['warning']['bg'] ?? '#eab308' }};
            --color-error: {{ $levelColors['error']['bg'] ?? '#ef4444' }};
            --color-critical: {{ $levelColors['critical']['bg'] ?? '#dc2626' }};
            --color-alert: {{ $levelColors['alert']['bg'] ?? '#ea580c' }};
            --color-emergency: {{ $levelColors['emergency']['bg'] ?? '#7f1d1d' }};
        }

        html, body { height: 100%; }
        body { font-family: 'Inter', system-ui, sans-serif; }

        /* Level colors */
        .level-debug { --level-color: var(--color-debug); }
        .level-info { --level-color: var(--color-info); }
        .level-notice { --level-color: var(--color-notice); }
        .level-warning { --level-color: var(--color-warning); }
        .level-error { --level-color: var(--color-error); }
        .level-critical { --level-color: var(--color-critical); }
        .level-alert { --level-color: var(--color-alert); }
        .level-emergency { --level-color: var(--color-emergency); }

        .level-indicator {
            width: 3px;
            background-color: var(--level-color);
        }

        .level-badge {
            background-color: var(--level-color);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .level-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--level-color);
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #475569; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #64748b; }

        /* Slide panel */
        .slide-panel {
            transition: transform 0.25s ease-out;
        }
        .slide-panel.closed {
            transform: translateX(100%);
        }

        /* Focus ring */
        .focus-ring:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
        }

        /* Log row */
        .log-row {
            transition: background-color 0.1s ease;
        }
        .log-row:hover {
            background-color: rgba(59, 130, 246, 0.04);
        }
        .dark .log-row:hover {
            background-color: rgba(59, 130, 246, 0.08);
        }
        .log-row.selected {
            background-color: rgba(59, 130, 246, 0.08);
        }
        .dark .log-row.selected {
            background-color: rgba(59, 130, 246, 0.15);
        }
    </style>
</head>
<body class="h-full bg-slate-200 dark:bg-slate-800 text-gray-900 dark:text-gray-100">
    @yield('content')
</body>
</html>
