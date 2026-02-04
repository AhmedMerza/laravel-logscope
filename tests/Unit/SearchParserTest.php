<?php

use LogScope\Http\Controllers\LogController;

beforeEach(function () {
    $this->controller = new class extends LogController
    {
        public function testParseSearchSyntax(string $input): array
        {
            return $this->parseSearchSyntax($input);
        }

        public function testGetSearchableFields(): array
        {
            return $this->getSearchableFields();
        }
    };
});

it('parses plain text search', function () {
    $terms = $this->controller->testParseSearchSyntax('error');

    expect($terms)->toHaveCount(1);
    expect($terms[0])->toBe([
        'field' => 'any',
        'value' => 'error',
        'exclude' => false,
    ]);
});

it('parses field:value syntax', function () {
    $terms = $this->controller->testParseSearchSyntax('message:error');

    expect($terms)->toHaveCount(1);
    expect($terms[0])->toBe([
        'field' => 'message',
        'value' => 'error',
        'exclude' => false,
    ]);
});

it('parses negated field syntax', function () {
    $terms = $this->controller->testParseSearchSyntax('-message:debug');

    expect($terms)->toHaveCount(1);
    expect($terms[0])->toBe([
        'field' => 'message',
        'value' => 'debug',
        'exclude' => true,
    ]);
});

it('parses quoted values', function () {
    $terms = $this->controller->testParseSearchSyntax('message:"user logged in"');

    expect($terms)->toHaveCount(1);
    expect($terms[0])->toBe([
        'field' => 'message',
        'value' => 'user logged in',
        'exclude' => false,
    ]);
});

it('parses multiple terms', function () {
    $terms = $this->controller->testParseSearchSyntax('message:error level:warning');

    expect($terms)->toHaveCount(2);
    expect($terms[0]['field'])->toBe('message');
    expect($terms[0]['value'])->toBe('error');
    expect($terms[1]['field'])->toBe('level');
    expect($terms[1]['value'])->toBe('warning');
});

it('parses mixed field and plain text', function () {
    $terms = $this->controller->testParseSearchSyntax('message:error something');

    expect($terms)->toHaveCount(2);
    expect($terms[0]['field'])->toBe('message');
    expect($terms[1]['field'])->toBe('any');
    expect($terms[1]['value'])->toBe('something');
});

it('treats unknown fields as plain text', function () {
    $terms = $this->controller->testParseSearchSyntax('unknownfield:value');

    expect($terms)->toHaveCount(1);
    expect($terms[0]['field'])->toBe('any');
});

it('parses negated plain text', function () {
    $terms = $this->controller->testParseSearchSyntax('-debug');

    expect($terms)->toHaveCount(1);
    expect($terms[0])->toBe([
        'field' => 'any',
        'value' => 'debug',
        'exclude' => true,
    ]);
});

it('returns all searchable fields', function () {
    $fields = $this->controller->testGetSearchableFields();

    expect($fields)->toContain('message');
    expect($fields)->toContain('source');
    expect($fields)->toContain('context');
    expect($fields)->toContain('level');
    expect($fields)->toContain('channel');
    expect($fields)->toContain('user_id');
    expect($fields)->toContain('ip_address');
    expect($fields)->toContain('url');
    expect($fields)->toContain('trace_id');
    expect($fields)->toContain('http_method');
});
