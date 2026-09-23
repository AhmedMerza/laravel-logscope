<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\Enums\LogStatus;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    LogScope::auth(fn () => true);

    config(['logscope.statuses' => [
        'waiting' => ['label' => 'Waiting for Customer', 'color' => 'orange', 'closed' => false],
        'wontfix' => ['label' => 'Won\'t Fix', 'color' => 'slate', 'closed' => true],
    ]]);
});

afterEach(function () {
    LogScope::resetAuth();
});

function anEntry(): LogEntry
{
    return LogEntry::createEntry(['level' => 'error', 'message' => 'Boom', 'channel' => 'stack']);
}

describe('vocabulary', function () {
    it('accepts built-in and configured statuses', function () {
        expect(LogStatus::allValues())
            ->toContain('open', 'investigating', 'resolved', 'ignored', 'waiting', 'wontfix');
    });

    it('rejects a status nothing declares', function () {
        expect(fn () => LogStatus::valueOf('banana'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('reads closed-ness from config for custom statuses', function () {
        expect(LogStatus::isClosedValue('wontfix'))->toBeTrue();
        expect(LogStatus::isClosedValue('waiting'))->toBeFalse();
        expect(LogStatus::isClosedValue(LogStatus::Resolved))->toBeTrue();
        expect(LogStatus::isClosedValue(LogStatus::Open))->toBeFalse();
    });

    it('treats a custom status with no closed flag as open', function () {
        config(['logscope.statuses' => ['triage' => ['label' => 'Triage']]]);

        expect(LogStatus::isClosedValue('triage'))->toBeFalse();
    });
});

describe('entries', function () {
    // Previously a 500: getValidStatuses() accepted the value, then
    // LogStatus::from() threw on it.
    it('sets a custom status through the API', function () {
        $entry = anEntry();

        $this->patchJson("/logscope/api/logs/{$entry->id}/status", ['status' => 'waiting'])
            ->assertOk();

        expect($entry->fresh()->status)->toBe('waiting');
    });

    // The enum cast threw on read, so even a row written directly was a 500.
    it('reads a row already holding a custom status', function () {
        $entry = anEntry();
        LogEntry::query()->where('id', $entry->id)->update(['status' => 'waiting']);

        expect(LogEntry::find($entry->id)->status)->toBe('waiting');
        expect(LogEntry::find($entry->id)->needsAttention())->toBeTrue();
    });

    it('still returns the enum for built-in statuses', function () {
        $entry = anEntry();
        $entry->setStatus(LogStatus::Resolved, 'ahmed');

        expect($entry->fresh()->status)->toBe(LogStatus::Resolved);
        expect($entry->fresh()->isResolved())->toBeTrue();
        expect($entry->fresh()->needsAttention())->toBeFalse();
    });

    it('honours a closed custom status', function () {
        $entry = anEntry();
        $entry->setStatus('wontfix', 'ahmed');

        expect($entry->fresh()->needsAttention())->toBeFalse();
    });

    it('rejects an undeclared status', function () {
        expect(fn () => anEntry()->setStatus('banana'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('groups', function () {
    it('sets a custom status on a group through the API', function () {
        anEntry();
        $group = LogGroup::first();

        $this->patchJson("/logscope/api/groups/{$group->id}/status", ['status' => 'waiting'])
            ->assertOk();

        expect($group->fresh()->status)->toBe('waiting');
    });

    it('reads a group already holding a custom status', function () {
        anEntry();
        $group = LogGroup::first();
        LogGroup::query()->where('id', $group->id)->update(['status' => 'wontfix']);

        $group = LogGroup::find($group->id);

        expect($group->status)->toBe('wontfix');
        expect($group->needsAttention())->toBeFalse();
    });

    it('lists a group with a custom status without erroring', function () {
        anEntry();
        LogGroup::first()->setStatus('waiting', 'ahmed');

        $response = $this->getJson('/logscope/api/groups?statuses[]=waiting');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });

    // Only the built-in 'resolved' regresses. A custom closed status is left
    // alone, the same as Ignored.
    it('does not reopen a custom closed status when it fires again', function () {
        anEntry();
        LogGroup::first()->setStatus('wontfix', 'ahmed');

        anEntry();

        $group = LogGroup::first();

        expect($group->status)->toBe('wontfix');
        expect($group->regressed_at)->toBeNull();
        expect($group->occurrence_count)->toBe(2);
    });
});
