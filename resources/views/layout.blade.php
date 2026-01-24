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
    {{ \LogScope\LogScope::css() }}
    @php
        $theme = config('logscope.theme', []);
        $levelColors = $theme['levels'] ?? [];
    @endphp
    <style>
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
    </style>
</head>
<body class="h-full bg-slate-200 dark:bg-slate-800 text-gray-900 dark:text-gray-100">
    @yield('content')
    {{ \LogScope\LogScope::js() }}
</body>
</html>
