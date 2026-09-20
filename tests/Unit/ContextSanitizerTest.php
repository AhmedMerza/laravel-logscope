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

    it('adds a configured key to the defaults on request input, ignoring case', function () {
        // prompt_tokens survives the default 'token' because of the shipped
        // exclusion list, not because configuring a key dropped the default
        // — that is the swap #79 made. The exclusion is the escape hatch now.
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

    it('redacts plural and acronym spellings (#77 regression)', function () {
        // Whole-word matching passed every other test in this block and still
        // stored these in clear. They are the reason matching is a fragment.
        $keys = [
            'access_tokens', 'refresh_tokens', 'api_keys', 'card_numbers', 'passwords',
            'APIToken', 'SSNNumber', 'IDToken', 'HTTPSecret', 'CVVCode',
        ];

        $result = (new ContextSanitizer)->sanitize(array_fill_keys($keys, 'SECRET'));

        expect($result)->toBe(array_fill_keys($keys, '[REDACTED]'));
    });

    it('keeps keys named in the exclusion list', function () {
        $context = [
            'prompt_tokens' => 150,
            'completion_tokens' => 50,
            'total_tokens' => 200,
            'token_count' => 12,
            'tokenizer' => 'cl100k',
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('does not let an exclusion cancel an unrelated secret in the same key (#77)', function () {
        // Checking "contains an exclusion" before "contains a secret" meant
        // prompt_tokens_password matched both and was stored in the clear.
        $keys = [
            'prompt_tokens_password',
            'stripe_secret_prompt_tokens_meta',
            'usage.prompt_tokens.debug_api_key',
        ];

        $result = (new ContextSanitizer)->sanitize(array_fill_keys($keys, 'SECRET'));

        expect($result)->toBe(array_fill_keys($keys, '[REDACTED]'));
    });

    it('keeps ordinary keys that only look sensitive with their separators removed (#77)', function () {
        // class_name collapses to 'classname', which contains 'ssn';
        // cv_video collapses to 'cvvideo', which contains 'cvv'. Matching a
        // one-word fragment inside a single word is what prevents this.
        $context = [
            'class_name' => 'App\\Jobs\\SyncOrders',
            'business_name' => 'Acme',
            'address_name' => 'HQ',
            'process_notes' => 'ok',
            'cv_video' => 'intro.mp4',
            'classSnapshot' => 'before',
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('redacts the defaults when every configured key is blank (#77, #79)', function () {
        // explode(',', env('LOGSCOPE_SENSITIVE_KEYS', '')) yields [''] when
        // the variable is unset. Filtering that to an empty list left a
        // matcher with no fragments, which redacts nothing at all; #77 added
        // a fallback for it. Merging retired the fallback rather than fixing
        // it — the defaults are in the list unconditionally now — so this
        // pins the outcome, which has to hold either way.
        config(['logscope.context.sensitive_keys' => ['', '   ', '---']]);

        $result = (new ContextSanitizer)->sanitize(['password' => 'hunter2', 'user_id' => 7]);

        expect($result['password'])->toBe('[REDACTED]')
            ->and($result['user_id'])->toBe(7);
    });

    it('ignores an exclusion too short to be anything but a fail-open', function () {
        // An exclusion removes whole words, so 'e' would remove almost every
        // word of every key.
        config(['logscope.context.sensitive_keys_except' => ['e']]);

        $result = (new ContextSanitizer)->sanitize(['secret' => 'x', 'password' => 'y']);

        expect($result['secret'])->toBe('[REDACTED]')
            ->and($result['password'])->toBe('[REDACTED]');
    });

    it('redacts an absurdly long key rather than searching it', function () {
        // Key length is client-chosen. Truncating before matching would let
        // a long prefix hide the fragment, so over-long keys redact on sight.
        $key = str_repeat('a', 300);

        expect((new ContextSanitizer)->sanitize([$key => 'v'])[$key])->toBe('[REDACTED]');
    });

    it('adds configured exclusions to the defaults rather than replacing them', function () {
        config(['logscope.context.sensitive_keys_except' => ['token_budget']]);

        $result = (new ContextSanitizer)->sanitize([
            'token_budget' => 4096,
            'prompt_tokens' => 150,
            'access_token' => 'secret',
        ]);

        expect($result['token_budget'])->toBe(4096)
            ->and($result['prompt_tokens'])->toBe(150)
            ->and($result['access_token'])->toBe('[REDACTED]');
    });

    it('ignores blank entries instead of letting them match everything', function () {
        // A stray comma in an env-driven list yields ''. In sensitive_keys that
        // would redact the whole context; in the exclusion list it would cancel
        // redaction entirely — a silent fail-open.
        config([
            'logscope.context.sensitive_keys' => ['', '   ', 'password'],
            'logscope.context.sensitive_keys_except' => ['', '  '],
        ]);

        $result = (new ContextSanitizer)->sanitize(['password' => 'hunter2', 'user_id' => 7]);

        expect($result['password'])->toBe('[REDACTED]')
            ->and($result['user_id'])->toBe(7);
    });

    it('replaces a resource with a marker on the shared path', function () {
        // json_encode() cannot represent a resource, and an unencodable value
        // costs the whole context column rather than the one field. The
        // handler's deleted copy had this branch; the shared sanitizer did
        // not until it became the only one (#77).
        $handle = fopen('php://memory', 'r');

        $result = (new ContextSanitizer)->sanitize(['handle' => $handle, 'ok' => 1]);
        fclose($handle);

        expect($result['handle'])->toBe('[Resource]')
            ->and($result['ok'])->toBe(1);
    });

    it('keeps the usage counters of the common LLM APIs', function () {
        // A developer who sees these redacted reaches for the exclusion list
        // and adds 'tokens', which takes access_tokens with it. Shipping the
        // counters is what stops that.
        $context = [
            'prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3,
            'input_tokens' => 4, 'output_tokens' => 5, 'max_tokens' => 6,
            'tokens_used' => 7, 'token_count' => 8,
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('matches a fragment split by a camelCase boundary inside it', function () {
        // passWord splits to pass + word, which no single word contains.
        // Joining a run and comparing for equality recovers it without
        // reopening the gluing (classname contains 'ssn' but never equals it).
        $result = (new ContextSanitizer)->sanitize(['passWord' => 'hunter2', 'myPassWord' => 'x']);

        expect($result['passWord'])->toBe('[REDACTED]')
            ->and($result['myPassWord'])->toBe('[REDACTED]');
    });

    it('covers every spelling whichever way a configured entry is written', function () {
        config(['logscope.context.sensitive_keys' => ['cardnumber']]);

        $result = (new ContextSanitizer)->sanitize([
            'cardnumber' => 'a', 'card_number' => 'b', 'cardNumber' => 'c', 'card.number' => 'd',
        ]);

        expect($result)->toBe([
            'cardnumber' => '[REDACTED]', 'card_number' => '[REDACTED]',
            'cardNumber' => '[REDACTED]', 'card.number' => '[REDACTED]',
        ]);
    });

    it('survives config shapes that are not a list of strings (#77)', function () {
        // Each of these produced a matcher that redacted nothing at all:
        // a comma-joined string never exploded, an assoc flag map whose
        // VALUES were read, and a non-string entry.
        $shapes = [
            'comma-joined' => ['password, token, secret'],
            'raw string' => 'password,token',
            'assoc flags' => ['password' => true, 'token' => true],
            'non-string' => [0],
        ];

        foreach ($shapes as $label => $configured) {
            config(['logscope.context.sensitive_keys' => $configured]);

            $result = (new ContextSanitizer)->sanitize(['password' => 'hunter2']);

            expect($result['password'])->toBe('[REDACTED]', "shape: {$label}");
        }
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

    it('adds a configured key to the defaults rather than replacing them (#79)', function () {
        // The whole of #79: adding one key used to drop the other eleven,
        // silently, in the one setting where failing open means secrets in
        // clear. A configured key and a surviving default, together.
        config(['logscope.context.sensitive_keys' => ['PIN']]);

        $result = (new ContextSanitizer)->sanitize(['pin_code' => '1234', 'password' => 'hunter2']);

        expect($result['pin_code'])->toBe('[REDACTED]')
            ->and($result['password'])->toBe('[REDACTED]');
    });

    it('ignores a configured key that repeats a default (#79)', function () {
        // Writing out the full list you want is the obvious thing to do once
        // entries merge, and it overlaps the defaults by construction. The
        // matcher does not care; the effective list doctor prints does.
        config(['logscope.context.sensitive_keys' => ['password', 'PIN']]);

        $sanitizer = new ContextSanitizer;

        expect($sanitizer->effectiveSensitiveKeys())
            ->toContain('password')
            ->toContain('pin')
            ->and(array_count_values($sanitizer->effectiveSensitiveKeys())['password'])->toBe(1);
    });

    it('leaves list positions alone', function () {
        // Integer keys are list positions, never names. Configure a numeral as
        // sensitive so this fails if shouldRedactKey()'s is_string guard goes.
        config(['logscope.context.sensitive_keys' => ['1']]);

        $context = ['items' => ['a', 'b', 'c']];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });
});

describe('compound keys split across array levels', function () {
    it('redacts a compound name whose halves are one level apart (#80)', function () {
        // card[number] is what Laravel makes of a bracketed form field and
        // the shape Stripe and Braintree hand back, so this is how a card
        // number actually reaches the log table. Matching the leaf key
        // alone, 'number' contains no fragment and it was stored in clear.
        $result = (new ContextSanitizer)->sanitize([
            'card' => ['number' => '4111111111111111', 'exp_month' => 12],
            'api' => ['key' => 'sk-live-51H9xQ'],
            'credit' => ['card' => '4111'],
        ]);

        expect($result['card']['number'])->toBe('[REDACTED]')
            ->and($result['api']['key'])->toBe('[REDACTED]')
            ->and($result['credit']['card'])->toBe('[REDACTED]')
            ->and($result['card']['exp_month'])->toBe(12);
    });

    it('does not let joining levels invent a match (#80)', function () {
        // The hazard the path carries: gluing two innocent words makes them
        // adjacent. class + name is 'classname', which contains 'ssn'. Only
        // the multi-word fragments are matched against a path, so the
        // one-word ones cannot fire on a join — flat class_name is kept for
        // the same reason (#77).
        $context = [
            'class' => ['name' => 'App\\Jobs\\SyncOrders'],
            'cv' => ['video' => 'intro.mp4'],
            'process' => ['notes' => 'ok'],
            'business' => ['name' => 'Acme'],
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('steps over list positions when joining the path (#80)', function () {
        // A list of cards puts an integer between the two halves. It is a
        // position, not part of the field's name, so it must not break the
        // join the way another word would.
        $result = (new ContextSanitizer)->sanitize([
            'card' => [['number' => '4111'], ['number' => '4222']],
        ]);

        expect($result['card'][0]['number'])->toBe('[REDACTED]')
            ->and($result['card'][1]['number'])->toBe('[REDACTED]');
    });

    it('steps over a position PHP did not cast to an int (#81 review)', function () {
        // PHP casts an array key to int only when it is a CANONICAL decimal,
        // so card[0][number] arrives as an int while card[01][number],
        // card[+1][number] and card[1.0][number] arrive as strings. Treating
        // those as words split card from number and stored the card number
        // in clear — from ordinary bracket syntax, no crafting needed.
        $result = (new ContextSanitizer)->sanitize([
            'card' => [
                '01' => ['number' => '4111'],
                '+1' => ['number' => '4222'],
                '1.0' => ['number' => '4333'],
                ' 2' => ['number' => '4444'],
            ],
        ]);

        expect($result['card']['01']['number'])->toBe('[REDACTED]')
            ->and($result['card']['+1']['number'])->toBe('[REDACTED]')
            ->and($result['card']['1.0']['number'])->toBe('[REDACTED]')
            ->and($result['card'][' 2']['number'])->toBe('[REDACTED]');
    });

    it('steps over a non-canonical position in a query string too (#81 review)', function () {
        $url = (new ContextSanitizer)->sanitizeUrl('https://x.test/p?card[01][number]=4111');

        expect(urldecode($url))->toContain('card[01][number]=[REDACTED]');
    });

    it('needs the two halves adjacent, so a named level between them breaks it', function () {
        // The design limit, pinned deliberately: only a POSITION is stepped
        // over. Any name-like key between the halves ends the adjacency the
        // compound needs, and that is what makes card[0x1A][number] a
        // non-match rather than a bypass — it behaves exactly like
        // card[holder][number], which never claimed to redact. Listing
        // `number` covers these if an app needs them.
        $context = [
            'card' => [
                'holder' => ['number' => '4111'],
                '0x1A' => ['number' => '4222'],
                '2nd' => ['number' => '4333'],
            ],
        ];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('carries the path into an expanded object (#80)', function () {
        $card = new stdClass;
        $card->number = '4111111111111111';
        $card->brand = 'visa';

        $result = (new ContextSanitizer)->sanitize(['card' => $card]);

        expect($result['card']['data']['number'])->toBe('[REDACTED]')
            ->and($result['card']['data']['brand'])->toBe('visa');
    });

    it('redacts a bracketed form field on the request input (#80)', function () {
        $request = Request::create('/pay', 'POST', [
            'card' => ['number' => '4111111111111111'],
            'amount' => '500',
        ]);

        $input = (new ContextSanitizer)->sanitize(['request' => $request])['request']['input'];

        expect($input['card']['number'])->toBe('[REDACTED]')
            ->and($input['amount'])->toBe('500');
    });

    it('redacts a bracketed query parameter in a URL (#80)', function () {
        $url = (new ContextSanitizer)->sanitizeUrl(
            'https://x.test/pay?card[number]=4111&api[key]=sk-live&class[name]=Foo'
        );

        expect(urldecode($url))
            ->toContain('card[number]=[REDACTED]')
            ->toContain('api[key]=[REDACTED]')
            ->toContain('class[name]=Foo');
    });

    it('redacts the whole subtree under a sensitive parent', function () {
        // The property the path bound rests on: a parent that matches is
        // replaced outright, so a path reaching a child is only ever built
        // from ancestors already judged harmless.
        $result = (new ContextSanitizer)->sanitize([
            'password' => ['confirmation' => 'hunter2', 'strength' => 3],
        ]);

        expect($result['password'])->toBe('[REDACTED]');
    });

    it('still matches a compound name inside one key', function () {
        // The path must not have displaced the original single-key match.
        $keys = ['card_number', 'cardNumber', 'card.number', 'api_key', 'credit_card'];

        $result = (new ContextSanitizer)->sanitize(array_fill_keys($keys, 'SECRET'));

        expect($result)->toBe(array_fill_keys($keys, '[REDACTED]'));
    });

    it('matches a configured compound of more than two words across levels', function () {
        config(['logscope.context.sensitive_keys' => ['billing_card_number']]);

        $result = (new ContextSanitizer)->sanitize([
            'billing' => ['card' => ['number' => '4111', 'brand' => 'visa']],
        ]);

        expect($result['billing']['card']['number'])->toBe('[REDACTED]')
            ->and($result['billing']['card']['brand'])->toBe('visa');
    });

    it('carries the path into every object-expansion branch (#81 review)', function () {
        // The fallback branch had a test; JsonSerializable, Arrayable and
        // Jsonable take different routes through sanitizeObject() and were
        // threaded in the same commit, untested.
        $arrayable = new class implements Illuminate\Contracts\Support\Arrayable
        {
            public function toArray(): array
            {
                return ['number' => '4111', 'brand' => 'visa'];
            }
        };

        $jsonSerializable = new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['key' => 'sk-live-1'];
            }
        };

        $jsonable = new class implements Illuminate\Contracts\Support\Jsonable
        {
            public function toJson($options = 0): string
            {
                return '{"number":"4333"}';
            }
        };

        $result = (new ContextSanitizer)->sanitize([
            'card' => $arrayable,
            'api' => $jsonSerializable,
            'debit' => ['card' => $jsonable],
        ]);

        expect($result['card']['number'])->toBe('[REDACTED]')
            ->and($result['card']['brand'])->toBe('visa')
            ->and($result['api']['key'])->toBe('[REDACTED]')
            ->and($result['debit']['card']['number'])->toBe('[REDACTED]');
    });
});

describe('compound keys and the request boundary (#81 review)', function () {
    it('carries the context key into a logged Request', function () {
        // ['api' => ['key' => …]] and ['api' => $dto] both redact, so
        // ['api' => $request] with ?key= must too. It did not: the Request
        // branch of sanitizeValue() dropped the path it was handed.
        $request = Illuminate\Http\Request::create('/x?key=SECRET', 'GET');

        $result = (new ContextSanitizer)->sanitize(['api' => $request]);

        expect($result['api']['query']['key'])->toBe('[REDACTED]');
    });

    it('carries the context key into a logged Request body', function () {
        $request = Illuminate\Http\Request::create('/pay', 'POST', ['number' => '4111', 'brand' => 'visa']);

        $result = (new ContextSanitizer)->sanitize(['card' => $request]);

        expect($result['card']['input']['number'])->toBe('[REDACTED]')
            ->and($result['card']['input']['brand'])->toBe('visa');
    });

    it('redacts the url of that same entry, not only its query', function () {
        // Redacting `query` while leaving `url` intact leaves the secret
        // readable one field away, in the same row.
        $request = Illuminate\Http\Request::create('/x?key=SECRET', 'GET');

        $result = (new ContextSanitizer)->sanitize(['api' => $request]);

        expect(urldecode($result['api']['url']))->toContain('key=[REDACTED]')
            ->and($result['api']['url'])->not->toContain('SECRET');
    });
});

describe('path threading guards (#81 review)', function () {
    it('keeps exactly the last longestPhrase - 1 characters of the path', function () {
        // The off-by-one test. The cap has to keep L-1 ancestor characters:
        // a surviving path can never hold a whole fragment (it would have
        // been redacted before the recursion got here), so a match still to
        // come must take at least one character from the leaf.
        //
        // 'cardnumber' is 10, so the cap is 9. The ancestors below contribute
        // exactly 9 characters after truncation — 'cardnumbe' — and the leaf
        // 'r' completes it. At a cap of 8 the path would truncate to
        // 'ardnumbe' and this would silently stop redacting.
        config(['logscope.context.sensitive_keys' => ['card_numbe_r']]);

        $result = (new ContextSanitizer)->sanitize([
            'zz' => ['card' => ['numbe' => ['r' => 'X', 'ok' => 1]]],
        ]);

        expect($result['zz']['card']['numbe']['r'])->toBe('[REDACTED]')
            ->and($result['zz']['card']['numbe']['ok'])->toBe(1);
    });

    it('still matches when a long ancestor is truncated away', function () {
        // 44 ancestor characters against the default cap of 19, so the
        // substr() really does discard here.
        $key = str_repeat('z', 40);

        $result = (new ContextSanitizer)->sanitize([$key => ['card' => ['number' => 'X']]]);

        expect($result[$key]['card']['number'])->toBe('[REDACTED]');
    });

    it('matches at the deepest level the depth cap still walks', function () {
        $result = (new ContextSanitizer)->sanitize([
            'a' => ['b' => ['c' => ['d' => ['card' => ['number' => 'X']]]]],
        ]);

        expect($result['a']['b']['c']['d']['card']['number'])->toBe('[REDACTED]');
    });

    it('redacts at the deepest level walked, and discards past it', function () {
        // Asserting only that SECRET is absent could not tell redaction apart
        // from the depth cap throwing the subtree away — both are
        // SECRET-free. Name which one happens on each side of the boundary.
        $sanitizer = new ContextSanitizer;

        $walked = $sanitizer->sanitize([
            'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['card_number' => 'SECRET']]]]]],
        ]);

        $capped = $sanitizer->sanitize([
            'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['card_number' => 'SECRET']]]]]]],
        ]);

        expect($walked['a']['b']['c']['d']['e']['f']['card_number'])->toBe('[REDACTED]')
            ->and($capped['a']['b']['c']['d']['e']['f']['g'])->toBe('[Max depth exceeded]')
            ->and(json_encode($walked))->not->toContain('SECRET')
            ->and(json_encode($capped))->not->toContain('SECRET');
    });

    it('returns the context untouched when redaction is off', function () {
        // The output contract only. shouldRedactKey() and redactSensitive()
        // both short-circuit on the same flag, so nothing here would notice
        // childPath()'s own guard going missing — that is the next test.
        config(['logscope.context.redact_sensitive' => false]);

        $context = ['card' => ['number' => '4111']];

        expect((new ContextSanitizer)->sanitize($context))->toBe($context);
    });

    it('does not tokenize a key when redaction is off', function () {
        // Reflection because the guard has no observable output: with
        // redaction off the context comes back untouched either way. What it
        // protects is real though — childPath() carries no MAX_KEY_LENGTH
        // check, that lives in isSensitiveKey(), which this config never
        // reaches. Without the guard a client-chosen key of any length was
        // regex-split on every log line.
        config(['logscope.context.redact_sensitive' => false]);

        $sanitizer = new ContextSanitizer;
        $childPath = new ReflectionMethod($sanitizer, 'childPath');
        $childPath->setAccessible(true);

        expect($childPath->invoke($sanitizer, '', 'card', ['number' => '4111']))->toBe('')
            ->and($childPath->invoke($sanitizer, '', str_repeat('a', 300), ['x' => 1]))->toBe('');
    });

    it('still threads a path when every configured key is single-word (#79)', function () {
        // This used to pin the opposite: configure only 'pin', get no phrase
        // list, and childPath() short-circuits. Merging made that state
        // unreachable through config — the defaults always carry
        // password_confirmation, api_key, credit_card and card_number — so
        // what is worth pinning now is that adding a single word does not
        // cost the compound defaults the path their cross-level match needs.
        config(['logscope.context.sensitive_keys' => ['pin']]);

        $sanitizer = new ContextSanitizer;
        $childPath = new ReflectionMethod($sanitizer, 'childPath');
        $childPath->setAccessible(true);

        expect($childPath->invoke($sanitizer, '', 'card', ['number' => '4111']))->toBe('card');
    });

    it('keeps a compound default spanning levels alongside a configured word (#79)', function () {
        config(['logscope.context.sensitive_keys' => ['pin']]);

        $result = (new ContextSanitizer)->sanitize([
            'card' => ['number' => '4111'],
            'my' => ['pin' => '1234'],
        ]);

        // card_number is a default and still spans levels; pin is the addition.
        expect($result['card']['number'])->toBe('[REDACTED]')
            ->and($result['my']['pin'])->toBe('[REDACTED]');
    });

    it('lets a multi-word exclusion cancel the flat key only, as documented', function () {
        // config/logscope.php promises exactly this asymmetry. Nothing
        // pinned it, and it is the kind of thing a later change silently
        // flips in either direction.
        config(['logscope.context.sensitive_keys_except' => ['credit_card']]);

        $result = (new ContextSanitizer)->sanitize([
            'credit_card' => 'visa-1111',
            'credit' => ['card' => '4111'],
        ]);

        expect($result['credit_card'])->toBe('visa-1111')
            ->and($result['credit']['card'])->toBe('[REDACTED]');
    });

    it('treats a one-word exclusion the same flat and nested', function () {
        // A single-word exclusion removes every word containing it, which
        // the config comment warns about. The point here is that the split
        // spelling behaves like the flat one rather than diverging.
        config(['logscope.context.sensitive_keys_except' => ['card']]);

        $result = (new ContextSanitizer)->sanitize([
            'card_number' => '4111',
            'card' => ['number' => '4222'],
        ]);

        expect($result['card_number'])->toBe('4111')
            ->and($result['card']['number'])->toBe('4222');
    });

    it('does not let an unrelated exclusion on an ancestor disable a deeper match', function () {
        config(['logscope.context.sensitive_keys_except' => ['tokenizer']]);

        $result = (new ContextSanitizer)->sanitize([
            'tokenizer' => ['card' => ['number' => '4111']],
        ]);

        expect($result['tokenizer']['card']['number'])->toBe('[REDACTED]');
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
