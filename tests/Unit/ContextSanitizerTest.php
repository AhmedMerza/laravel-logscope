<?php

declare(strict_types=1);

use Illuminate\Http\Request;
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

describe('request redaction', function () {
    it('redacts the Basic auth password Symfony copies into php-auth-pw', function () {
        $request = Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('bob:hunter2')]);

        $headers = $this->sanitizer->sanitize(['request' => $request])['request']['headers'];

        expect($headers['php-auth-pw'])->toBe(['[REDACTED]'])
            ->and($headers['authorization'])->toBe(['[REDACTED]']);
    });

    it('redacts custom credential headers by name fragment', function () {
        // Each header contains exactly one default fragment, so dropping any
        // fragment from the defaults fails this test.
        $request = Request::create('/', server: [
            'HTTP_X_API_KEY' => 'k',
            'HTTP_X_CSRF_TOKEN' => 't',
            'HTTP_X_CLIENT_SECRET' => 's',
            'HTTP_X_PASSWORD' => 'p',
            'HTTP_X_SESSION_ID' => 'i',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $headers = $this->sanitizer->sanitize(['request' => $request])['request']['headers'];

        expect($headers['x-api-key'])->toBe(['[REDACTED]'])
            ->and($headers['x-csrf-token'])->toBe(['[REDACTED]'])
            ->and($headers['x-client-secret'])->toBe(['[REDACTED]'])
            ->and($headers['x-password'])->toBe(['[REDACTED]'])
            ->and($headers['x-session-id'])->toBe(['[REDACTED]'])
            ->and($headers['accept'])->toBe(['application/json']);
    });

    it('adds configured sensitive headers to the defaults, ignoring case', function () {
        config(['logscope.context.sensitive_headers' => ['X-Tenant']]);
        $request = Request::create('/', server: ['HTTP_X_TENANT' => 'acme', 'HTTP_COOKIE' => 'session=abc']);

        $headers = (new ContextSanitizer)->sanitize(['request' => $request])['request']['headers'];

        expect($headers['x-tenant'])->toBe(['[REDACTED]'])
            ->and($headers['cookie'])->toBe(['[REDACTED]']);
    });

    it('replaces the default sensitive keys with configured ones, ignoring case', function () {
        // Replacing (not merging) is the escape hatch for default false positives:
        // the default 'token' would otherwise redact prompt_tokens.
        config(['logscope.context.sensitive_keys' => ['PIN']]);
        $request = Request::create('/', 'POST', ['pin' => '1234', 'prompt_tokens' => '150']);

        $input = (new ContextSanitizer)->sanitize(['request' => $request])['request']['input'];

        expect($input['pin'])->toBe('[REDACTED]')
            ->and($input['prompt_tokens'])->toBe('150');
    });
});

describe('context redaction', function () {
    it('redacts a sensitive key at the top level of logged context', function () {
        $result = (new ContextSanitizer)->sanitize(['password' => 'hunter2', 'user_id' => 7]);

        expect($result['password'])->toBe('[REDACTED]')
            ->and($result['user_id'])->toBe(7);
    });

    it('redacts sensitive keys nested inside a logged array', function () {
        $result = (new ContextSanitizer)->sanitize([
            'request' => ['card_number' => '4111', 'api_token' => 'secret-abc', 'amount' => 500],
        ]);

        expect($result['request']['card_number'])->toBe('[REDACTED]')
            ->and($result['request']['api_token'])->toBe('[REDACTED]')
            ->and($result['request']['amount'])->toBe(500);
    });

    it('redacts however the application spells the key', function () {
        $result = (new ContextSanitizer)->sanitize([
            'accessToken' => 'a',
            'user-password' => 'b',
            'API_KEY' => 'c',
            'credit.card.number' => 'd',
        ]);

        expect($result)->toBe([
            'accessToken' => '[REDACTED]',
            'user-password' => '[REDACTED]',
            'API_KEY' => '[REDACTED]',
            'credit.card.number' => '[REDACTED]',
        ]);
    });

    it('leaves keys that merely contain a sensitive word as a fragment', function () {
        // The false positives that kept redaction off this path: 'token' is a
        // segment of access_token but not of prompt_tokens, and 'ssn' is a
        // substring of lesson but not a word in it.
        $context = [
            'prompt_tokens' => 150,
            'total_tokens' => 400,
            'tokenizer' => 'cl100k',
            'lesson' => 'intro',
            'passwordless' => true,
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('redacts a sensitive property of an expanded object', function () {
        $user = new stdClass;
        $user->email = 'a@b.test';
        $user->password = 'hunter2';

        $result = (new ContextSanitizer)->sanitize(['user' => $user]);

        expect($result['user']['data']['password'])->toBe('[REDACTED]')
            ->and($result['user']['data']['email'])->toBe('a@b.test');
    });

    it('redacts nothing when redact_sensitive is off', function () {
        config(['logscope.context.redact_sensitive' => false]);

        $result = (new ContextSanitizer)->sanitize(['password' => 'hunter2']);

        expect($result['password'])->toBe('hunter2');
    });

    it('honours a configured key list on plain context, replacing the defaults', function () {
        config(['logscope.context.sensitive_keys' => ['PIN']]);

        $result = (new ContextSanitizer)->sanitize(['pin_code' => '1234', 'password' => 'hunter2']);

        expect($result['pin_code'])->toBe('[REDACTED]')
            ->and($result['password'])->toBe('hunter2');
    });

    it('leaves list positions alone', function () {
        $context = ['tokens' => ['a', 'b']];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
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

describe('toValidUtf8Deep', function () {
    it('cleans strings at every depth and leaves everything else alone', function () {
        // 0xB1 is a continuation byte with no lead byte — the shape a broken
        // client's User-Agent takes, and what json_encode() returns false on.
        $bad = 'agent '.chr(0xB1);

        $result = $this->sanitizer->toValidUtf8Deep([
            'trace_id' => 'plain-ascii',
            'user_agent' => $bad,
            'headers' => ['referer' => $bad],
            'user_id' => 42,
            'ip_address' => null,
        ]);

        expect(json_encode($result, JSON_UNESCAPED_UNICODE))->not->toBeFalse()
            ->and(mb_check_encoding($result['user_agent'], 'UTF-8'))->toBeTrue()
            ->and(mb_check_encoding($result['headers']['referer'], 'UTF-8'))->toBeTrue()
            // Substituted, not dropped.
            ->and($result['user_agent'])->toStartWith('agent ')
            ->and($result['trace_id'])->toBe('plain-ascii')
            ->and($result['user_id'])->toBe(42)
            ->and($result['ip_address'])->toBeNull();
    });

    it('cleans malformed bytes in keys, not only in values (#67)', function () {
        // #63 deliberately left keys alone: the only request-sourced keys in
        // its bag are header names kept by captureHeaders(), which matches an
        // allowlist exactly. #67 gave this method a second caller whose keys
        // carry no such guarantee — WriteLogEntry's payload can hold a
        // Request, and sanitizeHeaders() copies header names in verbatim.
        $result = $this->sanitizer->toValidUtf8Deep([
            'x-trace-'.chr(0xB1) => 'abc',
            'nested' => ['x-other-'.chr(0xB1) => 'def'],
        ]);

        expect(json_encode($result, JSON_UNESCAPED_UNICODE))->not->toBeFalse();

        foreach (array_keys($result) as $key) {
            expect(mb_check_encoding((string) $key, 'UTF-8'))->toBeTrue();
        }

        foreach (array_keys($result['nested']) as $key) {
            expect(mb_check_encoding((string) $key, 'UTF-8'))->toBeTrue();
        }
    });

    it('collapses two keys that clean to the same string, last value winning', function () {
        // The documented trade-off, pinned so it stays a decision rather than
        // becoming a surprise: json_encode() collapses them the same way, and
        // the alternative is losing the whole array.
        $result = $this->sanitizer->toValidUtf8Deep([
            'dup'.chr(0xB1) => 'first',
            'dup'.chr(0xB2) => 'second',
        ]);

        expect($result)->toHaveCount(1)
            ->and(array_values($result))->toBe(['second']);
    });
});
