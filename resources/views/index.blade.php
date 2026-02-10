@extends('logscope::layout')

@section('content')
<div x-data="logScope()" x-init="init()" class="h-full flex"
    @keydown.escape.window="closePanel()"
    @keydown.window="handleKeydown($event)">

    @include('logscope::partials.sidebar')

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0">
        @include('logscope::partials.header')
        @include('logscope::partials.filters-bar')

        <!-- Content Area -->
        <div class="flex-1 flex overflow-hidden">
            @include('logscope::partials.log-table')
            @include('logscope::partials.detail-panel')
        </div>
    </div>

    @include('logscope::partials.toast')
    @include('logscope::partials.dialog-delete')
    @include('logscope::partials.dialog-shortcuts')
</div>

<script>
window.logScopeConfig = {
    quickFilters: @json($quickFilters),
    features: @json($features),
    jsonViewer: @json($jsonViewer),
    statuses: @json($statuses),
    shortcuts: @json($shortcuts),
    channels: @json($channels),
    forbiddenRedirect: @json(config('logscope.routes.forbidden_redirect', '/')),
    unauthenticatedRedirect: @json(config('logscope.routes.unauthenticated_redirect', '/login')),
    routes: {
        logs: '{{ route('logscope.logs') }}',
        stats: '{{ route('logscope.stats') }}',
        apiBase: '{{ url(config('logscope.routes.prefix', 'logscope')) }}/api'
    }
};
</script>
{{ \LogScope\LogScope::appJs() }}
@endsection
