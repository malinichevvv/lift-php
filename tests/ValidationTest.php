<?php

declare(strict_types=1);

namespace Lift\Tests;

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
}
