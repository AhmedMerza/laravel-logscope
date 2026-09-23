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
    LogEntry::query()->delete();
    LogGroup::query()->delete();
});

afterEach(function () {
    LogScope::resetAuth();
});

/** Write n occurrences of one error. */
function occurrences(int $n, array $overrides = []): void
{
    for ($i = 0; $i < $n; $i++) {
        LogEntry::createEntry(array_merge([
            'level' => 'error',
            'message' => "User {$i} not found",
            'channel' => 'stack',
            'occurred_at' => now()->subSeconds($n - $i),
        ], $overrides));
    }
}

describe('listing', function () {
    it('returns one row per issue, not per occurrence', function () {
        occurrences(5);

        $response = $this->getJson('/logscope/api/groups');
        $response->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.occurrence_count'))->toBe(5);
        expect(LogEntry::count())->toBe(5);
    });

    it('hides resolved groups under the default filter', function () {
        occurrences(3);

        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');

        expect($this->getJson('/logscope/api/groups')->json('data'))->toHaveCount(0);
    });

    it('shows resolved groups when asked for explicitly', function () {
        occurrences(3);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');

        $response = $this->getJson('/logscope/api/groups?statuses[]=resolved');

        expect($response->json('data'))->toHaveCount(1);
    });

    it('brings a resolved group back once it regresses', function () {
        occurrences(3);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');

        expect($this->getJson('/logscope/api/groups')->json('data'))->toHaveCount(0);

        // It happens again.
        occurrences(1);

        $response = $this->getJson('/logscope/api/groups');

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.regressed_at'))->not->toBeNull();
    });

    it('keeps an ignored group hidden when it fires again', function () {
        occurrences(3);
        LogGroup::first()->setStatus(LogStatus::Ignored, 'ahmed');

        occurrences(1);

        expect($this->getJson('/logscope/api/groups')->json('data'))->toHaveCount(0);
    });

    it('filters by level', function () {
        occurrences(2);
        occurrences(2, ['level' => 'warning', 'message' => 'Disk almost full']);

        expect($this->getJson('/logscope/api/groups?levels[]=error')->json('data'))->toHaveCount(1);
        expect($this->getJson('/logscope/api/groups')->json('data'))->toHaveCount(2);
    });

    it('matches on the sample message', function () {
        occurrences(2);
        occurrences(2, ['message' => 'Gateway timed out']);

        $response = $this->getJson('/logscope/api/groups?search=Gateway');

        expect($response->json('data'))->toHaveCount(1);
    });

    it('pages with a cursor', function () {
        // Deliberately not "failure 1", "failure 2", …: those normalise to the
        // same fingerprint and would correctly collapse into a single group.
        $messages = ['Alpha broke', 'Beta broke', 'Gamma broke', 'Delta broke', 'Epsilon broke', 'Zeta broke'];

        foreach ($messages as $i => $message) {
            occurrences(1, ['message' => $message, 'occurred_at' => now()->subMinutes(10 - $i)]);
        }

        $first = $this->getJson('/logscope/api/groups?per_page=4');

        expect($first->json('data'))->toHaveCount(4);
        expect($first->json('meta.has_next'))->toBeTrue();

        $second = $this->getJson('/logscope/api/groups?per_page=4&cursor='.$first->json('meta.next_cursor'));

        expect($second->json('data'))->toHaveCount(2);
        expect($second->json('meta.has_next'))->toBeFalse();

        // No row served twice.
        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );
        expect(array_unique($ids))->toHaveCount(6);
    });

    it('ignores a malformed cursor rather than failing', function () {
        occurrences(2);

        $this->getJson('/logscope/api/groups?cursor=not-base64')->assertOk();
    });
});

describe('occurrences', function () {
    it('lists the occurrences in a group newest first', function () {
        occurrences(5);

        $group = LogGroup::first();
        $response = $this->getJson("/logscope/api/groups/{$group->id}/entries");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.occurrence_count'))->toBe(5);

        $times = array_column($response->json('data'), 'occurred_at');
        $sorted = $times;
        rsort($sorted);
        expect($times)->toBe($sorted);
    });

    it('pages occurrences', function () {
        occurrences(7);

        $group = LogGroup::first();
        $first = $this->getJson("/logscope/api/groups/{$group->id}/entries?per_page=3");

        expect($first->json('data'))->toHaveCount(3);
        expect($first->json('meta.has_next'))->toBeTrue();
    });

    it('404s for an unknown group', function () {
        $this->getJson('/logscope/api/groups/nope/entries')->assertNotFound();
    });
});

describe('triage', function () {
    it('sets a status on the group', function () {
        occurrences(3);
        $group = LogGroup::first();

        $this->patchJson("/logscope/api/groups/{$group->id}/status", ['status' => 'investigating'])
            ->assertOk();

        expect($group->fresh()->status)->toBe(LogStatus::Investigating);
    });

    it('rejects an unknown status', function () {
        occurrences(1);
        $group = LogGroup::first();

        $this->patchJson("/logscope/api/groups/{$group->id}/status", ['status' => 'banana'])
            ->assertStatus(422);
    });

    // Custom statuses from the 'statuses' config block have their own file,
    // tests/Feature/CustomStatusTest.php — they used to 500 on entries and
    // groups alike and are covered end to end there.

    it('clears the regression flag when triaged by hand', function () {
        occurrences(2);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');
        occurrences(1);

        $group = LogGroup::first();
        expect($group->regressed_at)->not->toBeNull();

        $this->patchJson("/logscope/api/groups/{$group->id}/status", ['status' => 'investigating'])
            ->assertOk();

        expect($group->fresh()->regressed_at)->toBeNull();
    });

    it('clears the regression flag on a bulk status change', function () {
        occurrences(2);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');
        occurrences(1);

        $group = LogGroup::first();

        $this->postJson('/logscope/api/groups/status-many', [
            'ids' => [$group->id],
            'status' => 'resolved',
        ])->assertOk();

        expect($group->fresh()->regressed_at)->toBeNull();
    });

    it('saves a note on the group', function () {
        occurrences(1);
        $group = LogGroup::first();

        $this->patchJson("/logscope/api/groups/{$group->id}/note", ['note' => 'upstream ticket #12'])
            ->assertOk();

        expect($group->fresh()->note)->toBe('upstream ticket #12');
    });

    it('refuses status changes when the feature is off', function () {
        config(['logscope.features.status' => false]);

        occurrences(1);
        $group = LogGroup::first();

        $this->patchJson("/logscope/api/groups/{$group->id}/status", ['status' => 'resolved'])
            ->assertStatus(403);
    });
});

describe('deletion', function () {
    it('deletes a group and all of its occurrences', function () {
        occurrences(4);
        occurrences(2, ['message' => 'Gateway timed out']);

        $group = LogGroup::query()->where('occurrence_count', 4)->first();

        $this->deleteJson("/logscope/api/groups/{$group->id}")->assertOk();

        expect(LogGroup::count())->toBe(1);
        expect(LogEntry::count())->toBe(2);
    });
});
