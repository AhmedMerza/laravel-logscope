<?php

declare(strict_types=1);

use LogScope\Services\ContextSanitizer;

beforeEach(function () {
    $this->sanitizer = new ContextSanitizer;
});

describe('sanitize', function () {
    it('passes through scalar values unchanged', function () {
        $context = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ];

        $result = $this->sanitizer->sanitize($context);

        expect($result)->toBe($context);
    });

    it('passes through arrays unchanged', function () {
        $context = [
            'items' => ['a', 'b', 'c'],
            'nested' => ['key' => 'value'],
        ];

        $result = $this->sanitizer->sanitize($context);

        expect($result)->toBe($context);
    });

    it('converts exceptions to safe array representation', function () {
        $exception = new RuntimeException('Test error', 123);
        $context = ['error' => $exception];

        $result = $this->sanitizer->sanitize($context);

        expect($result['error'])->toBeArray()
            ->and($result['error']['_type'])->toBe('exception')
            ->and($result['error']['class'])->toBe('RuntimeException')
            ->and($result['error']['message'])->toBe('Test error')
            ->and($result['error']['code'])->toBe(123)
            ->and($result['error']['file'])->toContain('ContextSanitizerTest.php')
            ->and($result['error']['line'])->toBeInt();
    });

    it('converts generic objects to class name string', function () {
        $object = new stdClass;
        $context = ['obj' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['obj'])->toBe('[Object: stdClass]');
    });

    it('converts custom objects to class name string', function () {
        $object = new DateTime;
        $context = ['date' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['date'])->toBe('[Object: DateTime]');
    });

    it('skips keys starting with double underscore', function () {
        $context = [
            '__internal' => 'should be skipped',
            '__psr_log' => 'also skipped',
            'normal' => 'kept',
        ];

        $result = $this->sanitizer->sanitize($context);

        expect($result)->toBe(['normal' => 'kept'])
            ->and($result)->not->toHaveKey('__internal')
            ->and($result)->not->toHaveKey('__psr_log');
    });

    it('skips keys starting with _logscope', function () {
        $context = [
            '_logscope_internal' => 'should be skipped',
            '_logscope_marker' => 'also skipped',
            'normal' => 'kept',
        ];

        $result = $this->sanitizer->sanitize($context);

        expect($result)->toBe(['normal' => 'kept'])
            ->and($result)->not->toHaveKey('_logscope_internal')
            ->and($result)->not->toHaveKey('_logscope_marker');
    });

    it('handles empty context', function () {
        $result = $this->sanitizer->sanitize([]);

        expect($result)->toBe([]);
    });

    it('handles mixed context with multiple types', function () {
        $exception = new InvalidArgumentException('Bad input');
        $context = [
            'message' => 'Something happened',
            'count' => 5,
            'error' => $exception,
            'user' => new stdClass,
            '__internal' => 'skip me',
        ];

        $result = $this->sanitizer->sanitize($context);

        expect($result)->toHaveKeys(['message', 'count', 'error', 'user'])
            ->and($result)->not->toHaveKey('__internal')
            ->and($result['message'])->toBe('Something happened')
            ->and($result['count'])->toBe(5)
            ->and($result['error']['_type'])->toBe('exception')
            ->and($result['user'])->toBe('[Object: stdClass]');
    });
});

describe('extractSource', function () {
    it('returns null when no exception in context', function () {
        $context = ['message' => 'hello'];

        $result = $this->sanitizer->extractSource($context);

        expect($result)->toBeNull();
    });

    it('returns null when exception key is not a Throwable', function () {
        $context = ['exception' => 'not an exception'];

        $result = $this->sanitizer->extractSource($context);

        expect($result)->toBeNull();
    });

    it('extracts file from exception', function () {
        $exception = new RuntimeException('Test');
        $context = ['exception' => $exception];

        $result = $this->sanitizer->extractSource($context);

        expect($result)->toContain('ContextSanitizerTest.php');
    });
});

describe('extractSourceLine', function () {
    it('returns null when no exception in context', function () {
        $context = ['message' => 'hello'];

        $result = $this->sanitizer->extractSourceLine($context);

        expect($result)->toBeNull();
    });

    it('returns null when exception key is not a Throwable', function () {
        $context = ['exception' => 'not an exception'];

        $result = $this->sanitizer->extractSourceLine($context);

        expect($result)->toBeNull();
    });

    it('extracts line from exception', function () {
        $exception = new RuntimeException('Test');
        $context = ['exception' => $exception];

        $result = $this->sanitizer->extractSourceLine($context);

        expect($result)->toBeInt()->toBeGreaterThan(0);
    });
});
