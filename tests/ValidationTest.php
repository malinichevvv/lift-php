<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Translation\Translator;
use Lift\Validation\RuleInterface;
use Lift\Validation\ValidationException;
use Lift\Validation\Validator;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    // -----------------------------------------------------------------
    // Required
    // -----------------------------------------------------------------

    public function testRequiredPassesWhenPresent(): void
    {
        $v = new Validator(['name' => 'Alice'], ['name' => 'required']);
        self::assertTrue($v->passes());
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $v = new Validator([], ['name' => 'required']);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('name', $v->errors());
    }

    public function testRequiredFailsWhenEmpty(): void
    {
        $v = new Validator(['name' => ''], ['name' => 'required']);
        self::assertTrue($v->fails());
    }

    // -----------------------------------------------------------------
    // Nullable
    // -----------------------------------------------------------------

    public function testNullableSkipsSubsequentRulesWhenEmpty(): void
    {
        $v = new Validator(['age' => ''], ['age' => 'nullable|integer']);
        self::assertTrue($v->passes());
    }

    public function testNullablePassesWhenValuePresent(): void
    {
        $v = new Validator(['age' => '25'], ['age' => 'nullable|integer']);
        self::assertTrue($v->passes());
    }

    // -----------------------------------------------------------------
    // String / Integer / Float / Boolean / Array
    // -----------------------------------------------------------------

    public function testStringRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello'], ['v' => 'string']))->passes());
        self::assertTrue((new Validator(['v' => 42],      ['v' => 'string']))->fails());
    }

    public function testIntegerRule(): void
    {
        self::assertTrue((new Validator(['v' => 42],      ['v' => 'integer']))->passes());
        self::assertTrue((new Validator(['v' => '42'],    ['v' => 'integer']))->passes());
        self::assertTrue((new Validator(['v' => 'abc'],   ['v' => 'integer']))->fails());
    }

    public function testFloatRule(): void
    {
        self::assertTrue((new Validator(['v' => 3.14],  ['v' => 'float']))->passes());
        self::assertTrue((new Validator(['v' => '3.14'], ['v' => 'numeric']))->passes());
        self::assertTrue((new Validator(['v' => 'abc'],  ['v' => 'numeric']))->fails());
    }

    public function testBooleanRule(): void
    {
        self::assertTrue((new Validator(['v' => true],  ['v' => 'boolean']))->passes());
        self::assertTrue((new Validator(['v' => 'yes'], ['v' => 'bool']))->fails());
    }

    public function testArrayRule(): void
    {
        self::assertTrue((new Validator(['v' => [1,2]], ['v' => 'array']))->passes());
        self::assertTrue((new Validator(['v' => 'x'],   ['v' => 'array']))->fails());
    }

    // -----------------------------------------------------------------
    // Email, URL, IP
    // -----------------------------------------------------------------

    public function testEmailRule(): void
    {
        self::assertTrue((new Validator(['e' => 'user@example.com'], ['e' => 'email']))->passes());
        self::assertTrue((new Validator(['e' => 'notanemail'],        ['e' => 'email']))->fails());
    }

    public function testUrlRule(): void
    {
        self::assertTrue((new Validator(['u' => 'https://example.com'], ['u' => 'url']))->passes());
        self::assertTrue((new Validator(['u' => 'not-a-url'],            ['u' => 'url']))->fails());
    }

    public function testIpRules(): void
    {
        self::assertTrue((new Validator(['ip' => '192.168.1.1'],   ['ip' => 'ip']))->passes());
        self::assertTrue((new Validator(['ip' => '::1'],           ['ip' => 'ipv6']))->passes());
        self::assertTrue((new Validator(['ip' => 'not-ip'],        ['ip' => 'ip']))->fails());
        self::assertTrue((new Validator(['ip' => '192.168.1.1'],   ['ip' => 'ipv4']))->passes());
    }

    // -----------------------------------------------------------------
    // Min / Max (numeric)
    // -----------------------------------------------------------------

    public function testMinRule(): void
    {
        self::assertTrue((new Validator(['v' => 10], ['v' => 'min:5']))->passes());
        self::assertTrue((new Validator(['v' => 3],  ['v' => 'min:5']))->fails());
    }

    public function testMaxRule(): void
    {
        self::assertTrue((new Validator(['v' => 5],  ['v' => 'max:10']))->passes());
        self::assertTrue((new Validator(['v' => 15], ['v' => 'max:10']))->fails());
    }

    public function testBetweenRule(): void
    {
        self::assertTrue((new Validator(['v' => 5],  ['v' => 'between:1,10']))->passes());
        self::assertTrue((new Validator(['v' => 15], ['v' => 'between:1,10']))->fails());
    }

    // -----------------------------------------------------------------
    // Min/Max length (strings)
    // -----------------------------------------------------------------

    public function testMinLengthRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello'], ['v' => 'min_length:3']))->passes());
        self::assertTrue((new Validator(['v' => 'hi'],    ['v' => 'min_length:3']))->fails());
    }

    public function testMaxLengthRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hi'],    ['v' => 'max_length:5']))->passes());
        self::assertTrue((new Validator(['v' => 'toolong'], ['v' => 'max_length:5']))->fails());
    }

    // -----------------------------------------------------------------
    // In / Not In
    // -----------------------------------------------------------------

    public function testInRule(): void
    {
        self::assertTrue((new Validator(['r' => 'admin'], ['r' => 'in:admin,user,mod']))->passes());
        self::assertTrue((new Validator(['r' => 'guest'], ['r' => 'in:admin,user,mod']))->fails());
    }

    public function testNotInRule(): void
    {
        self::assertTrue((new Validator(['r' => 'guest'], ['r' => 'not_in:admin,user']))->passes());
        self::assertTrue((new Validator(['r' => 'admin'], ['r' => 'not_in:admin,user']))->fails());
    }

    // -----------------------------------------------------------------
    // Regex
    // -----------------------------------------------------------------

    public function testRegexRule(): void
    {
        self::assertTrue((new Validator(['v' => 'abc123'], ['v' => 'regex:/^[a-z0-9]+$/']))->passes());
        self::assertTrue((new Validator(['v' => 'ABC'],    ['v' => 'regex:/^[a-z0-9]+$/']))->fails());
    }

    // -----------------------------------------------------------------
    // Confirmed
    // -----------------------------------------------------------------

    public function testConfirmedRule(): void
    {
        $data = ['password' => 'secret', 'password_confirmation' => 'secret'];
        self::assertTrue((new Validator($data, ['password' => 'confirmed']))->passes());

        $bad = ['password' => 'secret', 'password_confirmation' => 'wrong'];
        self::assertTrue((new Validator($bad, ['password' => 'confirmed']))->fails());
    }

    // -----------------------------------------------------------------
    // Same / Different
    // -----------------------------------------------------------------

    public function testSameRule(): void
    {
        self::assertTrue((new Validator(['a' => 'x', 'b' => 'x'], ['a' => 'same:b']))->passes());
        self::assertTrue((new Validator(['a' => 'x', 'b' => 'y'], ['a' => 'same:b']))->fails());
    }

    public function testDifferentRule(): void
    {
        self::assertTrue((new Validator(['a' => 'x', 'b' => 'y'], ['a' => 'different:b']))->passes());
        self::assertTrue((new Validator(['a' => 'x', 'b' => 'x'], ['a' => 'different:b']))->fails());
    }

    // -----------------------------------------------------------------
    // Date / Alpha / Digits / JSON
    // -----------------------------------------------------------------

    public function testDateRule(): void
    {
        self::assertTrue((new Validator(['d' => '2024-01-15'], ['d' => 'date']))->passes());
        self::assertTrue((new Validator(['d' => 'notadate'],   ['d' => 'date']))->fails());
    }

    public function testAlphaRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello'],  ['v' => 'alpha']))->passes());
        self::assertTrue((new Validator(['v' => 'hello1'], ['v' => 'alpha']))->fails());
    }

    public function testAlphaNumRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello1'], ['v' => 'alpha_num']))->passes());
        self::assertTrue((new Validator(['v' => 'h e l'],  ['v' => 'alpha_num']))->fails());
    }

    public function testDigitsRule(): void
    {
        self::assertTrue((new Validator(['v' => '12345'],  ['v' => 'digits']))->passes());
        self::assertTrue((new Validator(['v' => '123.45'], ['v' => 'digits']))->fails());
    }

    public function testJsonRule(): void
    {
        self::assertTrue((new Validator(['v' => '{"a":1}'], ['v' => 'json']))->passes());
        self::assertTrue((new Validator(['v' => 'notjson'], ['v' => 'json']))->fails());
    }

    // -----------------------------------------------------------------
    // Dot notation (nested data)
    // -----------------------------------------------------------------

    public function testDotNotationAccess(): void
    {
        $data = ['user' => ['name' => 'Alice', 'email' => 'alice@example.com']];
        $v    = new Validator($data, [
            'user.name'  => 'required|string',
            'user.email' => 'required|email',
        ]);
        self::assertTrue($v->passes());
    }

    public function testDotNotationFailure(): void
    {
        $data = ['user' => ['name' => '']];
        $v    = new Validator($data, ['user.name' => 'required']);
        self::assertTrue($v->fails());
    }

    // -----------------------------------------------------------------
    // Wildcard (array items)
    // -----------------------------------------------------------------

    public function testWildcardRule(): void
    {
        $data = ['tags' => ['php', 'python', 'go']];
        $v    = new Validator($data, ['tags.*' => 'string']);
        self::assertTrue($v->passes());
    }

    public function testWildcardFailure(): void
    {
        $data = ['tags' => ['php', 42, 'go']];
        $v    = new Validator($data, ['tags.*' => 'string']);
        self::assertTrue($v->fails());
    }

    // -----------------------------------------------------------------
    // Multiple errors
    // -----------------------------------------------------------------

    public function testMultipleErrors(): void
    {
        $v = new Validator([], [
            'name'  => 'required',
            'email' => 'required|email',
        ]);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('name',  $v->errors());
        self::assertArrayHasKey('email', $v->errors());
    }

    // -----------------------------------------------------------------
    // Validated() throws on failure
    // -----------------------------------------------------------------

    public function testValidatedThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator([], ['name' => 'required']))->validated();
    }

    public function testValidatedReturnsData(): void
    {
        $data      = ['name' => 'Alice', 'email' => 'alice@example.com'];
        $validated = (new Validator($data, ['name' => 'required', 'email' => 'required|email']))->validated();
        self::assertArrayHasKey('name', $validated);
    }

    // -----------------------------------------------------------------
    // ValidationException
    // -----------------------------------------------------------------

    public function testValidationExceptionHoldsErrors(): void
    {
        $e = ValidationException::withErrors(['name' => ['The name field is required.']]);
        self::assertSame(['name' => ['The name field is required.']], $e->errors());
    }

    // -----------------------------------------------------------------
    // Custom rule classes (RuleInterface)
    // -----------------------------------------------------------------

    public function testCustomRuleInterfacePasses(): void
    {
        $rule = new class implements RuleInterface {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return str_starts_with($value, '+');
            }
            public function message(): string { return 'The :attribute must start with +.'; }
        };

        $v = new Validator(['phone' => '+79001234567'], ['phone' => ['required', $rule]]);
        self::assertTrue($v->passes());
    }

    public function testCustomRuleInterfaceFails(): void
    {
        $rule = new class implements RuleInterface {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return str_starts_with($value, '+');
            }
            public function message(): string { return 'The :attribute must start with +.'; }
        };

        $v = new Validator(['phone' => '89001234567'], ['phone' => ['required', $rule]]);
        self::assertTrue($v->fails());
        self::assertStringContainsString('Phone', $v->errors()['phone'][0]);
    }

    public function testCustomRuleInterfaceMessagePlaceholder(): void
    {
        $rule = new class implements RuleInterface {
            public function passes(string $field, mixed $value, array $data): bool { return false; }
            public function message(): string { return 'The :attribute is always bad.'; }
        };

        $v = new Validator(['score' => 5], ['score' => [$rule]]);
        self::assertStringContainsString('Score', $v->errors()['score'][0]);
    }

    // -----------------------------------------------------------------
    // Inline closure rules
    // -----------------------------------------------------------------

    public function testClosureRulePasses(): void
    {
        $rule = static function (string $field, mixed $value, array $data, \Closure $fail): void {
            if ($value < 0) {
                $fail("{$field} must be non-negative.");
            }
        };

        $v = new Validator(['amount' => 10], ['amount' => ['required', $rule]]);
        self::assertTrue($v->passes());
    }

    public function testClosureRuleFails(): void
    {
        $rule = static function (string $field, mixed $value, array $data, \Closure $fail): void {
            if ($value < 0) {
                $fail('Amount must be non-negative.');
            }
        };

        $v = new Validator(['amount' => -5], ['amount' => ['required', $rule]]);
        self::assertTrue($v->fails());
        self::assertSame('Amount must be non-negative.', $v->errors()['amount'][0]);
    }

    // -----------------------------------------------------------------
    // Static extend()
    // -----------------------------------------------------------------

    public function testStaticExtendWithClosure(): void
    {
        Validator::extend(
            'starts_plus',
            static fn ($field, $value, $data) => is_string($value) && str_starts_with($value, '+'),
            'The :attribute must begin with +.',
        );

        $pass = new Validator(['p' => '+123'], ['p' => 'starts_plus']);
        $fail = new Validator(['p' => '123'],  ['p' => 'starts_plus']);

        self::assertTrue($pass->passes());
        self::assertTrue($fail->fails());
        self::assertStringContainsString('begin with +', $fail->errors()['p'][0]);

        Validator::resetExtensions();
    }

    public function testStaticExtendWithRuleInterface(): void
    {
        $impl = new class implements RuleInterface {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return is_string($value) && strlen($value) === 13 && str_starts_with($value, '978');
            }
            public function message(): string { return 'The :attribute must be a valid ISBN-13.'; }
        };

        Validator::extend('isbn13', $impl);

        $good = new Validator(['isbn' => '9780306406157'], ['isbn' => 'isbn13']);
        $bad  = new Validator(['isbn' => '123'],            ['isbn' => 'isbn13']);

        self::assertTrue($good->passes());
        self::assertTrue($bad->fails());
        self::assertStringContainsString('ISBN-13', $bad->errors()['isbn'][0]);

        Validator::resetExtensions();
    }

    // -----------------------------------------------------------------
    // Custom error messages
    // -----------------------------------------------------------------

    public function testCustomMessageByFieldAndRule(): void
    {
        $v = new Validator(
            [],
            ['email' => 'required|email'],
            ['email.required' => 'Please enter your email address.'],
        );

        self::assertTrue($v->fails());
        self::assertSame('Please enter your email address.', $v->errors()['email'][0]);
    }

    public function testCustomMessageByRuleOnly(): void
    {
        $v = new Validator(
            ['name' => '', 'email' => ''],
            ['name' => 'required', 'email' => 'required'],
            ['required' => 'This field cannot be blank.'],
        );

        self::assertTrue($v->fails());
        self::assertSame('This field cannot be blank.', $v->errors()['name'][0]);
        self::assertSame('This field cannot be blank.', $v->errors()['email'][0]);
    }

    public function testCustomMessageSupportsPlaceholder(): void
    {
        $v = new Validator(
            ['age' => 5],
            ['age' => 'min:18'],
            ['age.min' => ':attribute must be at least :min years old.'],
        );

        self::assertSame('Age must be at least 18 years old.', $v->errors()['age'][0]);
    }

    public function testFieldRuleMessageTakesPriorityOverRuleMessage(): void
    {
        $v = new Validator(
            [],
            ['name' => 'required'],
            [
                'required'       => 'Generic required message.',
                'name.required'  => 'Name-specific required message.',
            ],
        );

        self::assertSame('Name-specific required message.', $v->errors()['name'][0]);
    }

    // -----------------------------------------------------------------
    // Translator — localization
    // -----------------------------------------------------------------

    public function testTranslatorEnglishLocale(): void
    {
        $t = new Translator('en');
        $v = new Validator([], ['email' => 'required'], [], $t);

        self::assertStringContainsString('required', $v->errors()['email'][0]);
    }

    public function testTranslatorRussianLocale(): void
    {
        $t = new Translator('ru');
        $v = new Validator([], ['email' => 'required'], [], $t);

        self::assertStringContainsString('обязательно', $v->errors()['email'][0]);
    }

    public function testTranslatorRussianFieldLabel(): void
    {
        $t = new Translator('ru');
        $v = new Validator(['name' => 42], ['name' => 'string'], [], $t);

        self::assertStringContainsString('Name', $v->errors()['name'][0]);
    }

    public function testTranslatorAddMessagesOverridesFile(): void
    {
        $t = new Translator('en');
        $t->addMessages('en', ['required' => 'Field :attribute is mandatory!']);

        $v = new Validator([], ['login' => 'required'], [], $t);
        self::assertSame('Field Login is mandatory!', $v->errors()['login'][0]);
    }

    public function testTranslatorFallbackToEnglish(): void
    {
        // 'zz' locale has no file; should fall back to 'en'
        $t = new Translator('zz', 'en');
        $v = new Validator([], ['x' => 'required'], [], $t);

        self::assertStringContainsString('required', $v->errors()['x'][0]);
    }

    public function testTranslatorCustomPath(): void
    {
        $dir = sys_get_temp_dir() . '/lift_test_lang_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/xx.php', "<?php return ['required' => 'Pflichtfeld :attribute.'];");

        $t = new Translator('xx', 'en', [$dir]);
        $v = new Validator([], ['name' => 'required'], [], $t);

        self::assertSame('Pflichtfeld Name.', $v->errors()['name'][0]);

        unlink($dir . '/xx.php');
        rmdir($dir);
    }

    // -----------------------------------------------------------------
    // Pluralization
    // -----------------------------------------------------------------

    public function testPluralizationSingularEnglish(): void
    {
        $t = new Translator('en');
        // max_length:1 with 'hello' fails; count = 1 → singular "character"
        $v = new Validator(['s' => 'hello'], ['s' => 'max_length:1'], [], $t);

        self::assertStringContainsString('character', $v->errors()['s'][0]);
        self::assertStringNotContainsString('characters', $v->errors()['s'][0]);
    }

    public function testPluralizationPluralEnglish(): void
    {
        $t = new Translator('en');
        // min_length:5 with 'hi' fails; count = 5 → plural "characters"
        $v = new Validator(['s' => 'hi'], ['s' => 'min_length:5'], [], $t);

        self::assertStringContainsString('characters', $v->errors()['s'][0]);
    }

    public function testPluralizationIntervalRussian(): void
    {
        $t = new Translator('ru');

        // count=1 from max_length:1, value 'ab' → {1} form → «символ»
        $v1 = new Validator(['s' => 'ab'], ['s' => 'max_length:1'], [], $t);
        self::assertStringContainsString('символ', $v1->errors()['s'][0]);

        // count=3 → [2,4] form → «символов»
        $v3 = new Validator(['s' => 'hi'], ['s' => 'min_length:3'], [], $t);
        self::assertStringContainsString('символов', $v3->errors()['s'][0]);

        // count=10 → [5,*] form → «символов»
        $v10 = new Validator(['s' => 'short'], ['s' => 'min_length:10'], [], $t);
        self::assertStringContainsString('символов', $v10->errors()['s'][0]);
    }

    public function testTranslatorChoiceMethod(): void
    {
        $t = new Translator('en');
        $t->addMessages('en', [
            'items_count' => '{0} no items|{1} one item|[2,*] :count items',
        ]);

        self::assertSame('no items',  $t->choice('items_count', 0));
        self::assertSame('one item',  $t->choice('items_count', 1));
        self::assertSame('5 items',   $t->choice('items_count', 5));
    }

    public function testBuiltinPluralizationWithoutTranslator(): void
    {
        // No translator — built-in English fallback also pluralizes
        // max_length:1 with 'hello' → count=1 → singular
        $v1 = new Validator(['s' => 'hello'], ['s' => 'max_length:1']);
        self::assertStringContainsString('1 character', $v1->errors()['s'][0]);
        self::assertStringNotContainsString('characters', $v1->errors()['s'][0]);

        // min_length:5 with 'hi' → count=5 → plural
        $v5 = new Validator(['s' => 'hi'], ['s' => 'min_length:5']);
        self::assertStringContainsString('5 characters', $v5->errors()['s'][0]);
    }

    // -----------------------------------------------------------------
    // Presence rules: present, filled, sometimes
    // -----------------------------------------------------------------

    public function testPresentRulePassesWhenKeyExists(): void
    {
        $v = new Validator(['note' => ''], ['note' => 'present']);
        self::assertTrue($v->passes());
    }

    public function testPresentRuleFailsWhenKeyMissing(): void
    {
        $v = new Validator([], ['note' => 'present']);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('note', $v->errors());
    }

    public function testFilledPassesWhenKeyAbsent(): void
    {
        // 'filled' only fails when the key EXISTS but is empty
        $v = new Validator([], ['comment' => 'filled']);
        self::assertTrue($v->passes());
    }

    public function testFilledFailsWhenKeyExistsButEmpty(): void
    {
        $v = new Validator(['comment' => ''], ['comment' => 'filled']);
        self::assertTrue($v->fails());
    }

    public function testFilledPassesWhenValuePresent(): void
    {
        $v = new Validator(['comment' => 'hello'], ['comment' => 'filled']);
        self::assertTrue($v->passes());
    }

    public function testSometimesSkipsWhenKeyAbsent(): void
    {
        // 'sometimes' + 'required': skip entirely when key not in data
        $v = new Validator([], ['field' => 'sometimes|required|string']);
        self::assertTrue($v->passes());
    }

    public function testSometimesValidatesWhenKeyPresent(): void
    {
        $v = new Validator(['field' => 42], ['field' => 'sometimes|string']);
        self::assertTrue($v->fails());
    }

    // -----------------------------------------------------------------
    // Conditional required rules
    // -----------------------------------------------------------------

    public function testRequiredIfTriggered(): void
    {
        $v = new Validator(
            ['role' => 'admin'],
            ['secret' => 'required_if:role,admin'],
        );
        self::assertTrue($v->fails());
        self::assertArrayHasKey('secret', $v->errors());
    }

    public function testRequiredIfSkipped(): void
    {
        $v = new Validator(
            ['role' => 'user'],
            ['secret' => 'required_if:role,admin'],
        );
        self::assertTrue($v->passes());
    }

    public function testRequiredUnlessTriggered(): void
    {
        // 'required_unless:status,draft' → required when status ≠ 'draft'
        $v = new Validator(
            ['status' => 'published'],
            ['body' => 'required_unless:status,draft'],
        );
        self::assertTrue($v->fails());
    }

    public function testRequiredUnlessSkipped(): void
    {
        $v = new Validator(
            ['status' => 'draft'],
            ['body' => 'required_unless:status,draft'],
        );
        self::assertTrue($v->passes());
    }

    public function testRequiredWithTriggered(): void
    {
        // 'required_with:name' → required when 'name' is non-empty
        $v = new Validator(
            ['name' => 'Alice'],
            ['bio' => 'required_with:name'],
        );
        self::assertTrue($v->fails());
    }

    public function testRequiredWithSkipped(): void
    {
        $v = new Validator(
            [],
            ['bio' => 'required_with:name'],
        );
        self::assertTrue($v->passes());
    }

    public function testRequiredWithoutTriggered(): void
    {
        // 'required_without:phone' → required when 'phone' is absent/empty
        $v = new Validator(
            [],
            ['email' => 'required_without:phone'],
        );
        self::assertTrue($v->fails());
    }

    public function testRequiredWithoutSkipped(): void
    {
        $v = new Validator(
            ['phone' => '555-1234'],
            ['email' => 'required_without:phone'],
        );
        self::assertTrue($v->passes());
    }

    // -----------------------------------------------------------------
    // Prohibited rules
    // -----------------------------------------------------------------

    public function testProhibitedFailsWhenPresent(): void
    {
        $v = new Validator(['debug' => 'true'], ['debug' => 'prohibited']);
        self::assertTrue($v->fails());
    }

    public function testProhibitedPassesWhenAbsent(): void
    {
        $v = new Validator([], ['debug' => 'prohibited']);
        self::assertTrue($v->passes());
    }

    public function testProhibitedIfTriggered(): void
    {
        $v = new Validator(
            ['role' => 'user', 'admin_token' => 'secret'],
            ['admin_token' => 'prohibited_if:role,user'],
        );
        self::assertTrue($v->fails());
    }

    public function testProhibitedIfNotTriggered(): void
    {
        $v = new Validator(
            ['role' => 'admin', 'admin_token' => 'secret'],
            ['admin_token' => 'prohibited_if:role,user'],
        );
        self::assertTrue($v->passes());
    }

    public function testProhibitedUnlessTriggered(): void
    {
        // prohibited unless role=admin
        $v = new Validator(
            ['role' => 'user', 'admin_token' => 'secret'],
            ['admin_token' => 'prohibited_unless:role,admin'],
        );
        self::assertTrue($v->fails());
    }

    public function testProhibitedUnlessNotTriggered(): void
    {
        $v = new Validator(
            ['role' => 'admin', 'admin_token' => 'secret'],
            ['admin_token' => 'prohibited_unless:role,admin'],
        );
        self::assertTrue($v->passes());
    }

    // -----------------------------------------------------------------
    // Array rules: list, distinct, min_items, max_items
    // -----------------------------------------------------------------

    public function testListRule(): void
    {
        self::assertTrue((new Validator(['v' => [1, 2, 3]],       ['v' => 'list']))->passes());
        self::assertTrue((new Validator(['v' => ['a' => 1]],       ['v' => 'list']))->fails());
        self::assertTrue((new Validator(['v' => [0 => 'a', 2 => 'b']], ['v' => 'list']))->fails());
    }

    public function testDistinctRule(): void
    {
        self::assertTrue((new Validator(['v' => ['a', 'b', 'c']], ['v' => 'distinct']))->passes());
        self::assertTrue((new Validator(['v' => ['a', 'b', 'a']], ['v' => 'distinct']))->fails());
    }

    public function testMinItemsRule(): void
    {
        self::assertTrue((new Validator(['v' => [1, 2, 3]], ['v' => 'min_items:2']))->passes());
        self::assertTrue((new Validator(['v' => [1]],       ['v' => 'min_items:2']))->fails());
    }

    public function testMaxItemsRule(): void
    {
        self::assertTrue((new Validator(['v' => [1, 2]],    ['v' => 'max_items:3']))->passes());
        self::assertTrue((new Validator(['v' => [1, 2, 3, 4]], ['v' => 'max_items:3']))->fails());
    }

    // -----------------------------------------------------------------
    // New string / number rules
    // -----------------------------------------------------------------

    public function testUuidRule(): void
    {
        self::assertTrue((new Validator(['v' => '550e8400-e29b-41d4-a716-446655440000'], ['v' => 'uuid']))->passes());
        self::assertTrue((new Validator(['v' => 'not-a-uuid'],                            ['v' => 'uuid']))->fails());
    }

    public function testAcceptedRule(): void
    {
        foreach (['yes', 'on', '1', 1, true, 'true'] as $ok) {
            self::assertTrue((new Validator(['v' => $ok], ['v' => 'accepted']))->passes(), "accepted failed for: " . var_export($ok, true));
        }
        self::assertTrue((new Validator(['v' => 'no'], ['v' => 'accepted']))->fails());
    }

    public function testDeclinedRule(): void
    {
        foreach (['no', 'off', '0', 0, false, 'false'] as $ok) {
            self::assertTrue((new Validator(['v' => $ok], ['v' => 'declined']))->passes(), "declined failed for: " . var_export($ok, true));
        }
        self::assertTrue((new Validator(['v' => 'yes'], ['v' => 'declined']))->fails());
    }

    public function testMultipleOfRule(): void
    {
        self::assertTrue((new Validator(['v' => 12], ['v' => 'multiple_of:3']))->passes());
        self::assertTrue((new Validator(['v' => 10], ['v' => 'multiple_of:3']))->fails());
    }

    public function testDigitsBetweenRule(): void
    {
        self::assertTrue((new Validator(['v' => '12345'],  ['v' => 'digits_between:3,6']))->passes());
        self::assertTrue((new Validator(['v' => '12'],     ['v' => 'digits_between:3,6']))->fails());
        self::assertTrue((new Validator(['v' => '1234567'],['v' => 'digits_between:3,6']))->fails());
        self::assertTrue((new Validator(['v' => 'abc'],    ['v' => 'digits_between:3,6']))->fails());
    }

    public function testMacAddressRule(): void
    {
        self::assertTrue((new Validator(['v' => '00:1B:44:11:3A:B7'], ['v' => 'mac_address']))->passes());
        self::assertTrue((new Validator(['v' => '00-1B-44-11-3A-B7'], ['v' => 'mac_address']))->passes());
        self::assertTrue((new Validator(['v' => 'not-a-mac'],          ['v' => 'mac_address']))->fails());
    }

    public function testNotRegexRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello123'], ['v' => 'not_regex:/^\d+$/']))->passes());
        self::assertTrue((new Validator(['v' => '12345'],    ['v' => 'not_regex:/^\d+$/']))->fails());
    }

    public function testLowercaseRule(): void
    {
        self::assertTrue((new Validator(['v' => 'hello'],  ['v' => 'lowercase']))->passes());
        self::assertTrue((new Validator(['v' => 'Hello'],  ['v' => 'lowercase']))->fails());
        self::assertTrue((new Validator(['v' => 'привет'], ['v' => 'lowercase']))->passes());
    }

    public function testUppercaseRule(): void
    {
        self::assertTrue((new Validator(['v' => 'HELLO'],  ['v' => 'uppercase']))->passes());
        self::assertTrue((new Validator(['v' => 'Hello'],  ['v' => 'uppercase']))->fails());
    }

    // -----------------------------------------------------------------
    // Combined scenarios (realistic use-cases)
    // -----------------------------------------------------------------

    public function testNestedArrayWithMinItems(): void
    {
        $data  = ['tags' => ['php', 'mysql']];
        $rules = ['tags' => 'required|array|list|min_items:1|distinct', 'tags.*' => 'string'];
        self::assertTrue((new Validator($data, $rules))->passes());
    }

    public function testNestedArrayDistinctFailure(): void
    {
        $data  = ['tags' => ['php', 'mysql', 'php']];
        $rules = ['tags' => 'array|distinct'];
        self::assertTrue((new Validator($data, $rules))->fails());
    }

    public function testRequiredIfWithCustomMessage(): void
    {
        $v = new Validator(
            ['publish' => '1'],
            ['title' => 'required_if:publish,1'],
            ['title.required_if' => 'Title is needed when publishing.'],
        );
        self::assertSame('Title is needed when publishing.', $v->errors()['title'][0]);
    }
}
