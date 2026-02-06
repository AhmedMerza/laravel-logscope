<!DOCTYPE html>
@php
    $theme = config('logscope.theme', []);
    $levelColors = $theme['levels'] ?? [];
    $primaryColor = $theme['primary'] ?? '#10b981';
    $darkModeDefault = $theme['dark_mode_default'] ?? true;
    $fonts = $theme['fonts'] ?? ['sans' => 'Outfit', 'mono' => 'JetBrains Mono'];

    // Parse hex to RGB for glow effect
    $hex = ltrim($primaryColor, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $faviconColor = urlencode($primaryColor);

    // Build font CSS values
    $fontSans = $fonts['sans'] ? "'{$fonts['sans']}', " : '';
    $fontMono = $fonts['mono'] ? "'{$fonts['mono']}', " : '';
@endphp
<html lang="en" x-data="{ darkMode: localStorage.getItem('logscope-dark') !== null ? localStorage.getItem('logscope-dark') === 'true' : {{ $darkModeDefault ? 'true' : 'false' }} }"
    x-init="$watch('darkMode', val => localStorage.setItem('logscope-dark', val))"
    :class="darkMode ? 'dark' : 'light'">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LogScope</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='4' fill='{{ $faviconColor }}'/><path d='M8 10h16M8 16h12M8 22h14' stroke='%23ffffff' stroke-width='2.5' stroke-linecap='round'/></svg>">

    {{-- Google Fonts (configurable via logscope.theme.fonts) --}}
    @if($fonts['sans'] || $fonts['mono'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @php
        $fontFamilies = [];
        if ($fonts['sans']) $fontFamilies[] = urlencode($fonts['sans']) . ':wght@400;500;600;700';
        if ($fonts['mono']) $fontFamilies[] = urlencode($fonts['mono']) . ':wght@400;500;600;700';
    @endphp
    <link href="https://fonts.googleapis.com/css2?family={{ implode('&family=', $fontFamilies) }}&display=swap" rel="stylesheet">
    @endif

    {{ \LogScope\LogScope::css() }}
    <style>
        :root {
            --color-debug: {{ $levelColors['debug']['bg'] ?? '#64748b' }};
            --color-info: {{ $levelColors['info']['bg'] ?? '#06b6d4' }};
            --color-notice: {{ $levelColors['notice']['bg'] ?? '#8b5cf6' }};
            --color-warning: {{ $levelColors['warning']['bg'] ?? '#f59e0b' }};
            --color-error: {{ $levelColors['error']['bg'] ?? '#ef4444' }};
            --color-critical: {{ $levelColors['critical']['bg'] ?? '#dc2626' }};
            --color-alert: {{ $levelColors['alert']['bg'] ?? '#f97316' }};
            --color-emergency: {{ $levelColors['emergency']['bg'] ?? '#be123c' }};

            --font-mono: {{ $fontMono }}ui-monospace, SFMono-Regular, monospace;
            --font-sans: {{ $fontSans }}system-ui, -apple-system, sans-serif;

            --surface-0: #0a0a0b;
            --surface-1: #111113;
            --surface-2: #18181b;
            --surface-3: #27272a;
            --border: #3f3f46;
            --text-primary: #fafafa;
            --text-secondary: #a1a1aa;
            --text-muted: #71717a;
            --accent: {{ $primaryColor }};
            --accent-rgb: {{ $r }}, {{ $g }}, {{ $b }};
            --accent-glow: rgba({{ $r }}, {{ $g }}, {{ $b }}, 0.4);
        }

        .light {
            --surface-0: #ffffff;
            --surface-1: #f8fafc;
            --surface-2: #f1f5f9;
            --surface-3: #e2e8f0;
            --border: #cbd5e1;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: var(--border) transparent;
        }

        *::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
        }

        *::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 3px;
        }

        *::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
    </style>
</head>
<body class="h-full font-sans antialiased"
    style="font-family: var(--font-sans); background-color: var(--surface-0); color: var(--text-primary);">
    @yield('content')
    {{ \LogScope\LogScope::js() }}
</body>
</html>
