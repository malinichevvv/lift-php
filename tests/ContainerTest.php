<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Container\Container;
use Lift\Exception\ContainerException;
use Lift\Exception\ContainerNotFoundException;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = new Container();
    }

    public function testBindAndResolve(): void
    {
        $this->c->bind(FakeService::class, fn() => new FakeService('hello'));
        $svc = $this->c->make(FakeService::class);
        self::assertSame('hello', $svc->value);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->c->singleton(FakeService::class, fn() => new FakeService((string) random_int(1, 9999)));
        $a = $this->c->make(FakeService::class);
        $b = $this->c->make(FakeService::class);
        self::assertSame($a, $b);
    }

    public function testInstanceRegistration(): void
    {
        $obj = new FakeService('registered');
        $this->c->instance(FakeService::class, $obj);
        self::assertSame($obj, $this->c->make(FakeService::class));
    }

    public function testAutowiring(): void
    {
        $dep = $this->c->make(FakeDependency::class);
        self::assertInstanceOf(FakeService::class, $dep->service);
    }

    public function testHas(): void
    {
        self::assertTrue($this->c->has(FakeService::class));
        self::assertFalse($this->c->has('NonExistentClass'));
    }

    public function testInterfaceBinding(): void
    {
        $this->c->bind(FakeInterface::class, FakeImplementation::class);
        $impl = $this->c->make(FakeInterface::class);
        self::assertInstanceOf(FakeImplementation::class, $impl);
    }

    public function testCircularDependencyThrows(): void
    {
        $this->c->bind(CircularA::class, fn(Container $c) => new CircularA($c->make(CircularB::class)));
        $this->c->bind(CircularB::class, fn(Container $c) => new CircularB($c->make(CircularA::class)));
        $this->expectException(ContainerException::class);
        $this->c->make(CircularA::class);
    }

    public function testNotFoundThrows(): void
    {
        $this->expectException(ContainerNotFoundException::class);
        $this->c->make('Totally\Nonexistent\Class');
    }

    public function testCallInjectsClosure(): void
    {
        $this->c->instance(FakeService::class, new FakeService('injected'));
        $result = $this->c->call(fn(FakeService $s) => $s->value);
        self::assertSame('injected', $result);
    }

    public function testOverridesArePrioritised(): void
    {
        $result = $this->c->make(FakeService::class, ['value' => 'overridden']);
        self::assertSame('overridden', $result->value);
    }
}

// ---- Fixtures --------------------------------------------------------

class FakeService
{
    public function __construct(public readonly string $value = 'default') {}
}

interface FakeInterface {}

class FakeImplementation implements FakeInterface {}

class FakeDependency
{
    public function __construct(public readonly FakeService $service) {}
}

class CircularA
{
    public function __construct(public readonly mixed $b) {}
}

class CircularB
{
    public function __construct(public readonly mixed $a) {}
}
