<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LogScope - Log Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .log-debug { @apply bg-gray-100 text-gray-700; }
        .log-info { @apply bg-blue-100 text-blue-800; }
        .log-notice { @apply bg-cyan-100 text-cyan-800; }
        .log-warning { @apply bg-yellow-100 text-yellow-800; }
        .log-error { @apply bg-red-100 text-red-800; }
        .log-critical { @apply bg-red-200 text-red-900; }
        .log-alert { @apply bg-orange-200 text-orange-900; }
        .log-emergency { @apply bg-red-300 text-red-950; }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {}
            }
        }
    </script>
</head>
<body class="h-full">
    <div class="min-h-full">
        <!-- Header -->
        <nav class="bg-gray-800">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <span class="text-white text-xl font-bold">LogScope</span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-300 text-sm" x-data x-text="new Date().toLocaleString()"></span>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main content -->
        <main>
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
