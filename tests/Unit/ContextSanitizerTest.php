<?php

declare(strict_types=1);

use LogScope\Services\ContextSanitizer;

enum TestBackedEnum: string
{
    case Active = 'active';
    case Pending = 'pending';
}

enum TestUnitEnum
{
    case Red;
    case Green;
    case Blue;
}

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
            ->and($result['error']['line'])->toBeInt()
            ->and($result['error']['trace'])->toBeArray();
    });

    it('strips args from the trace slice to avoid leaking sensitive arguments', function () {
        $exception = new RuntimeException('boom');
        $context = ['error' => $exception];

        $result = $this->sanitizer->sanitize($context);

        expect($result['error']['trace'])->toBeArray();
        foreach ($result['error']['trace'] as $frame) {
            expect($frame)->not->toHaveKey('args');
        }
    });

    it('remaps file/line in sanitized exception for ArgumentCountError', function () {
        // Throw via a closure so we can ask reflection where the `new` lives.
        // Robust under formatter reflows — line is read from the closure itself.
        $thrower = fn () => new \LogScope\Tests\Fixtures\ServiceWithRequiredArg;
        $callerLine = (new ReflectionFunction($thrower))->getStartLine();

        try {
            $thrower();
        } catch (\ArgumentCountError $e) {
            $exception = $e;
        }

        $result = $this->sanitizer->sanitize(['error' => $exception]);

        expect($result['error']['file'])->toContain('ContextSanitizerTest.php')
            ->and($result['error']['line'])->toBe($callerLine)
            ->and($result['error']['trace'])->toBeArray()->not->toBeEmpty();
    });

    it('converts stdClass to structured object with data', function () {
        $object = new stdClass;
        $object->status = 'ok';
        $object->total = 12.5;
        $context = ['obj' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['obj'])->toBeArray()
            ->and($result['obj']['_type'])->toBe('object')
            ->and($result['obj']['class'])->toBe('stdClass')
            ->and($result['obj']['data'])->toBe(['status' => 'ok', 'total' => 12.5]);
    });

    it('converts empty stdClass to structured object with empty data', function () {
        $object = new stdClass;
        $context = ['obj' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['obj'])->toBeArray()
            ->and($result['obj']['_type'])->toBe('object')
            ->and($result['obj']['class'])->toBe('stdClass')
            ->and($result['obj']['data'])->toBe([]);
    });

    it('converts DateTime to formatted string', function () {
        $object = new DateTime('2024-01-15 10:30:00');
        $context = ['date' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['date'])->toBeString()
            ->and($result['date'])->toContain('2024-01-15 10:30:00');
    });

    it('converts BackedEnum to structured array with value', function () {
        $context = ['status' => TestBackedEnum::Active];

        $result = $this->sanitizer->sanitize($context);

        expect($result['status'])->toBeArray()
            ->and($result['status']['_type'])->toBe('enum')
            ->and($result['status']['name'])->toBe('Active')
            ->and($result['status']['value'])->toBe('active');
    });

    it('converts UnitEnum to structured array without value', function () {
        $context = ['color' => TestUnitEnum::Red];

        $result = $this->sanitizer->sanitize($context);

        expect($result['color'])->toBeArray()
            ->and($result['color']['_type'])->toBe('enum')
            ->and($result['color']['name'])->toBe('Red')
            ->and($result['color'])->not->toHaveKey('value');
    });

    it('converts Stringable objects to string', function () {
        $object = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'hello world';
            }
        };
        $context = ['label' => $object];

        $result = $this->sanitizer->sanitize($context);

        expect($result['label'])->toBe('hello world');
    });

    it('prefers JsonSerializable over Stringable', function () {
        $object = new class implements JsonSerializable, Stringable
        {
            public function jsonSerialize(): mixed
            {
                return ['key' => 'value'];
            }

            public function __toString(): string
            {
                return 'flat';
            }
        };

        $result = $this->sanitizer->sanitize(['obj' => $object]);

        expect($result['obj'])->toBe(['key' => 'value']);
    });

    it('recursively sanitizes nested objects in fallback', function () {
        $outer = new stdClass;
        $inner = new stdClass;
        $inner->id = 1;
        $outer->child = $inner;

        $result = $this->sanitizer->sanitize(['data' => $outer]);

        expect($result['data']['data']['child'])->toBeArray()
            ->and($result['data']['data']['child']['_type'])->toBe('object')
            ->and($result['data']['data']['child']['class'])->toBe('stdClass')
            ->and($result['data']['data']['child']['data'])->toBe(['id' => 1]);
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
            ->and($result['user']['_type'])->toBe('object')
            ->and($result['user']['class'])->toBe('stdClass');
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

    it('uses caller file for ArgumentCountError instead of constructor declaration', function () {
        try {
            new \LogScope\Tests\Fixtures\ServiceWithRequiredArg;
        } catch (\ArgumentCountError $e) {
            $exception = $e;
        }

        $result = $this->sanitizer->extractSource(['exception' => $exception]);

        // PHP's getFile() points at ServiceWithRequiredArg.php (constructor declaration).
        // We want the user-code call site (this test file) instead.
        expect($result)->toContain('ContextSanitizerTest.php')
            ->and($result)->not->toContain('ServiceWithRequiredArg.php');
    });

    it('uses caller file for argument-validation TypeError', function () {
        try {
            new \LogScope\Tests\Fixtures\ServiceWithRequiredArg(123); // strict_types=1 → TypeError
        } catch (\TypeError $e) {
            $exception = $e;
        }

        // Sanity: this is PHP's "Argument #N must be of type ..." flavor.
        expect($exception->getMessage())->toContain('Argument #');

        $result = $this->sanitizer->extractSource(['exception' => $exception]);

        expect($result)->toContain('ContextSanitizerTest.php')
            ->and($result)->not->toContain('ServiceWithRequiredArg.php');
    });

    it('does NOT remap return-type TypeError to caller (getFile() is already correct)', function () {
        try {
            \LogScope\Tests\Fixtures\ServiceWithRequiredArg::returnsBadType();
        } catch (\TypeError $e) {
            $exception = $e;
        }

        // Sanity: PHP says "Return value must be of type ..." — no "Argument #".
        expect($exception->getMessage())->not->toContain('Argument #');

        $result = $this->sanitizer->extractSource(['exception' => $exception]);

        // The bad `return` lives in the fixture file. We must not silently
        // remap it to the caller (this test file).
        expect($result)->toContain('ServiceWithRequiredArg.php');
    });

    it('does NOT remap user-thrown TypeError to caller', function () {
        try {
            \LogScope\Tests\Fixtures\ServiceWithRequiredArg::userThrowsTypeError();
        } catch (\TypeError $e) {
            $exception = $e;
        }

        $result = $this->sanitizer->extractSource(['exception' => $exception]);

        // The `throw` site is in the fixture; it's the right location.
        expect($result)->toContain('ServiceWithRequiredArg.php');
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

    it('uses caller line for ArgumentCountError instead of constructor declaration', function () {
        // Throw via a closure so reflection can tell us the line of `new`,
        // independent of any formatter reflows in the surrounding test body.
        $thrower = fn () => new \LogScope\Tests\Fixtures\ServiceWithRequiredArg;
        $callerLine = (new ReflectionFunction($thrower))->getStartLine();

        try {
            $thrower();
        } catch (\ArgumentCountError $e) {
            $exception = $e;
        }

        $result = $this->sanitizer->extractSourceLine(['exception' => $exception]);

        expect($result)->toBe($callerLine);
    });
});
