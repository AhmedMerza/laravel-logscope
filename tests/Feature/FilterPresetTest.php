<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\Models\FilterPreset;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__ . '/../../database/migrations']);
});

it('creates a filter preset', function () {
    $preset = FilterPreset::createFromFilters(
        'Errors Only',
        ['levels' => ['error', 'critical']],
        'Shows only error and critical logs'
    );

    expect($preset)
        ->name->toBe('Errors Only')
        ->description->toBe('Shows only error and critical logs')
        ->filters->toHaveKey('levels')
        ->is_default->toBeFalse();
});

it('sets sort order automatically', function () {
    $preset1 = FilterPreset::createFromFilters('First', []);
    $preset2 = FilterPreset::createFromFilters('Second', []);
    $preset3 = FilterPreset::createFromFilters('Third', []);

    expect($preset1->sort_order)->toBe(1);
    expect($preset2->sort_order)->toBe(2);
    expect($preset3->sort_order)->toBe(3);
});

it('sets a preset as default', function () {
    $preset1 = FilterPreset::createFromFilters('First', []);
    $preset2 = FilterPreset::createFromFilters('Second', []);

    $preset1->setAsDefault();
    expect($preset1->fresh()->is_default)->toBeTrue();

    $preset2->setAsDefault();
    expect($preset1->fresh()->is_default)->toBeFalse();
    expect($preset2->fresh()->is_default)->toBeTrue();
});

it('retrieves default preset', function () {
    FilterPreset::createFromFilters('First', []);
    $default = FilterPreset::createFromFilters('Default', []);
    $default->setAsDefault();
    FilterPreset::createFromFilters('Third', []);

    $found = FilterPreset::default()->first();

    expect($found->name)->toBe('Default');
});

it('orders presets correctly', function () {
    $third = FilterPreset::create(['name' => 'Third', 'filters' => [], 'sort_order' => 3]);
    $first = FilterPreset::create(['name' => 'First', 'filters' => [], 'sort_order' => 1]);
    $second = FilterPreset::create(['name' => 'Second', 'filters' => [], 'sort_order' => 2]);

    $ordered = FilterPreset::ordered()->pluck('name')->toArray();

    expect($ordered)->toBe(['First', 'Second', 'Third']);
});

it('applies level filter to log query', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'Error']);
    LogEntry::createEntry(['level' => 'warning', 'message' => 'Warning']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Info']);

    $preset = FilterPreset::createFromFilters('Errors', ['levels' => ['error']]);
    $query = LogEntry::query();
    $preset->applyTo($query);

    expect($query->count())->toBe(1);
    expect($query->first()->level)->toBe('error');
});

it('applies exclude level filter', function () {
    LogEntry::createEntry(['level' => 'debug', 'message' => 'Debug']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Info']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'Error']);

    $preset = FilterPreset::createFromFilters('No Debug', ['exclude_levels' => ['debug']]);
    $query = LogEntry::query();
    $preset->applyTo($query);

    expect($query->count())->toBe(2);
});

it('applies search filter', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'User logged in']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Payment processed']);

    $preset = FilterPreset::createFromFilters('User logs', ['search' => 'User']);
    $query = LogEntry::query();
    $preset->applyTo($query);

    expect($query->count())->toBe(1);
});

it('applies multiple filters', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'User error', 'environment' => 'production']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'System error', 'environment' => 'production']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'User error', 'environment' => 'staging']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'User info', 'environment' => 'production']);

    $preset = FilterPreset::createFromFilters('Production User Errors', [
        'levels' => ['error'],
        'environments' => ['production'],
        'search' => 'User',
    ]);

    $query = LogEntry::query();
    $preset->applyTo($query);

    expect($query->count())->toBe(1);
});
