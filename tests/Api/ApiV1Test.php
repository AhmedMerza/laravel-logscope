<?php

declare(strict_types=1);

// Token-authenticated v1 API (#64), through a real Sanctum bearer token.

use Illuminate\Support\Facades\Gate;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;
use LogScope\Tests\Fixtures\User;

const V1 = '/api/logscope/v1';

beforeEach(function () {
    Gate::define('viewLogScope', fn (User $user) => $user->email === 'dev@example.com');

    $this->user = User::create(['name' => 'Dev', 'email' => 'dev@example.com', 'password' => 'x']);
    $this->token = $this->user->createToken('phone')->plainTextToken;

    LogEntry::createEntry([
        'level' => 'error',
        'message' => 'User 1 not found',
        'channel' => 'stack',
        'occurred_at' => now(),
    ]);
    $this->entry = LogEntry::first();
    $this->group = LogGroup::first();
});

it('serves every v1 read endpoint with a token', function (string $path, array $structure) {
    $path = strtr($path, ['{entry}' => $this->entry->id, '{group}' => $this->group->id]);

    $this->withToken($this->token)->getJson(V1.$path)
        ->assertOk()
        ->assertJsonStructure($structure);
})->with([
    'config' => ['/config', ['data' => [
        'levels', 'channels', 'httpMethods', 'statuses', 'quickFilters',
        'features' => ['status', 'notes', 'search_syntax', 'regex'],
        'jsonViewer' => ['collapseThreshold', 'autoCollapseKeys'],
        'grouping' => ['enabled'],
        'theme' => ['primary', 'dark_mode_default', 'fonts', 'levels'],
    ]]],
    'logs' => ['/logs', [
        'data' => [['id', 'level', 'message', 'channel', 'status', 'occurred_at']],
        'meta' => ['has_next', 'next_cursor', 'per_page', 'count', 'has_next_count'],
    ]],
    'log' => ['/logs/{entry}', ['data' => ['id', 'level', 'message', 'context', 'status']]],
    'stats' => ['/stats', ['data' => ['total', 'by_level', 'today', 'this_hour']]],
    'groups' => ['/groups', [
        'data' => [['id', 'level', 'sample_message', 'status', 'occurrence_count', 'last_seen_at']],
        'meta' => ['has_next', 'next_cursor', 'per_page'],
    ]],
    'group' => ['/groups/{group}', ['data' => ['id', 'level', 'sample_message', 'status', 'occurrence_count', 'last_seen_at']]],
    'group entries' => ['/groups/{group}/entries', [
        'data' => [['id', 'level', 'message', 'occurred_at']],
        'meta' => ['has_next', 'next_cursor', 'per_page', 'occurrence_count'],
    ]],
]);

it('keeps browser-only data out of config', function () {
    $data = $this->withToken($this->token)->getJson(V1.'/config')->json('data');

    expect($data)->not->toHaveKeys(['shortcuts', 'failureBanner']);
});

it('sets an entry status without a CSRF token and records who changed it', function () {
    $this->withToken($this->token)
        ->patchJson(V1."/logs/{$this->entry->id}/status", ['status' => 'resolved'])
        ->assertOk();

    expect($this->entry->fresh())
        ->status->value->toBe('resolved')
        ->status_changed_by->toBe('Dev');
});

it('sets a group status without touching its entries', function () {
    $this->withToken($this->token)
        ->patchJson(V1."/groups/{$this->group->id}/status", ['status' => 'resolved'])
        ->assertOk();

    expect($this->group->fresh()->status->value)->toBe('resolved')
        ->and($this->entry->fresh()->status->value)->toBe('open');
});

it('returns a JSON 401 without a token, even when the client sends no Accept header', function () {
    $this->getJson(V1.'/logs')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

    // A plain GET would otherwise redirect to route('login'), which this app lacks.
    $this->get(V1.'/logs')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
});

it('returns a JSON 403 when the token user fails viewLogScope', function () {
    $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'x']);

    $this->withToken($other->createToken('phone')->plainTextToken)
        ->get(V1.'/logs')
        ->assertForbidden()
        ->assertJson(['message' => 'Unauthorized access to LogScope.']);
});

it('does not expose destructive endpoints', function () {
    // The paths exist for GET/PATCH only, so the web UI's verbs are refused.
    $this->withToken($this->token)->deleteJson(V1."/logs/{$this->entry->id}")->assertMethodNotAllowed();
    $this->withToken($this->token)->deleteJson(V1."/groups/{$this->group->id}")->assertMethodNotAllowed();
    $this->withToken($this->token)->postJson(V1.'/logs/clear')->assertMethodNotAllowed();

    expect(LogEntry::count())->toBe(1);
});
