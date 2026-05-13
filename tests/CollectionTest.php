<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Support\Collection;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    // -----------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------

    public function testMakeCreatesCollection(): void
    {
        $c = Collection::make([1, 2, 3]);
        self::assertSame([1, 2, 3], $c->all());
    }

    public function testEmptyCollection(): void
    {
        $c = new Collection();
        self::assertTrue($c->isEmpty());
        self::assertFalse($c->isNotEmpty());
        self::assertSame(0, $c->count());
    }

    // -----------------------------------------------------------------
    // Transformation
    // -----------------------------------------------------------------

    public function testMap(): void
    {
        $result = Collection::make([1, 2, 3])->map(fn($v) => $v * 2);
        self::assertSame([2, 4, 6], $result->all());
    }

    public function testFlatMap(): void
    {
        $result = Collection::make([1, 2, 3])->flatMap(fn($v) => [$v, $v * 10]);
        self::assertSame([1, 10, 2, 20, 3, 30], $result->all());
    }

    public function testFilter(): void
    {
        $result = Collection::make([1, 2, 3, 4])->filter(fn($v) => $v % 2 === 0);
        self::assertSame([2, 4], $result->values()->all());
    }

    public function testFilterWithNoCallbackRemovesFalsy(): void
    {
        $result = Collection::make([0, 1, '', 'a', null, false, true])->filter();
        self::assertSame([1, 'a', true], $result->all());
    }

    public function testReject(): void
    {
        $result = Collection::make([1, 2, 3, 4])->reject(fn($v) => $v % 2 === 0);
        self::assertSame([1, 3], $result->all());
    }

    public function testReduce(): void
    {
        $sum = Collection::make([1, 2, 3, 4])->reduce(fn($carry, $v) => $carry + $v, 0);
        self::assertSame(10, $sum);
    }

    // -----------------------------------------------------------------
    // Extraction
    // -----------------------------------------------------------------

    public function testFirst(): void
    {
        self::assertSame(1, Collection::make([1, 2, 3])->first());
        self::assertNull(Collection::make([])->first());
        self::assertSame('x', Collection::make([])->first(null, 'x'));
    }

    public function testFirstWithCallback(): void
    {
        $result = Collection::make([1, 2, 3])->first(fn($v) => $v > 1);
        self::assertSame(2, $result);
    }

    public function testLast(): void
    {
        self::assertSame(3, Collection::make([1, 2, 3])->last());
        self::assertNull(Collection::make([])->last());
    }

    public function testLastWithCallback(): void
    {
        $result = Collection::make([1, 2, 3])->last(fn($v) => $v < 3);
        self::assertSame(2, $result);
    }

    public function testTake(): void
    {
        self::assertSame([1, 2], Collection::make([1, 2, 3, 4])->take(2)->all());
    }

    public function testTakeNegative(): void
    {
        self::assertSame([3, 4], Collection::make([1, 2, 3, 4])->take(-2)->all());
    }

    public function testSkip(): void
    {
        self::assertSame([3, 4], Collection::make([1, 2, 3, 4])->skip(2)->all());
    }

    public function testChunk(): void
    {
        $chunks = Collection::make([1, 2, 3, 4, 5])->chunk(2);
        self::assertSame(3, $chunks->count());
        self::assertSame([1, 2], $chunks->first()->all());
    }

    // -----------------------------------------------------------------
    // Grouping / pluck / keyBy
    // -----------------------------------------------------------------

    public function testPluck(): void
    {
        $items = [['name' => 'Alice'], ['name' => 'Bob']];
        $result = Collection::make($items)->pluck('name');
        self::assertSame(['Alice', 'Bob'], $result->all());
    }

    public function testPluckWithIndexBy(): void
    {
        $items = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $result = Collection::make($items)->pluck('name', 'id');
        self::assertSame([1 => 'Alice', 2 => 'Bob'], $result->all());
    }

    public function testGroupBy(): void
    {
        $items = [
            ['type' => 'a', 'val' => 1],
            ['type' => 'b', 'val' => 2],
            ['type' => 'a', 'val' => 3],
        ];
        $groups = Collection::make($items)->groupBy('type');
        self::assertSame(2, $groups->count());
        self::assertSame(2, $groups->get('a')->count());
        self::assertSame(1, $groups->get('b')->count());
    }

    public function testKeyBy(): void
    {
        $items = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $result = Collection::make($items)->keyBy('id');
        self::assertSame('Alice', $result->get(1)['name']);
        self::assertSame('Bob', $result->get(2)['name']);
    }

    // -----------------------------------------------------------------
    // Sorting
    // -----------------------------------------------------------------

    public function testSortBy(): void
    {
        $items = [['n' => 3], ['n' => 1], ['n' => 2]];
        $result = Collection::make($items)->sortBy('n');
        self::assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $result->values()->all());
    }

    public function testSortByDesc(): void
    {
        $items = [['n' => 1], ['n' => 3], ['n' => 2]];
        $result = Collection::make($items)->sortByDesc('n');
        self::assertSame([['n' => 3], ['n' => 2], ['n' => 1]], $result->values()->all());
    }

    public function testSort(): void
    {
        $result = Collection::make([3, 1, 2])->sort();
        self::assertSame([1, 2, 3], $result->all());
    }

    public function testSortKeys(): void
    {
        $result = Collection::make(['b' => 2, 'a' => 1])->sortKeys();
        self::assertSame(['a' => 1, 'b' => 2], $result->all());
    }

    public function testReverse(): void
    {
        $result = Collection::make([1, 2, 3])->reverse();
        self::assertSame([3, 2, 1], $result->values()->all());
    }

    // -----------------------------------------------------------------
    // Set operations
    // -----------------------------------------------------------------

    public function testUnique(): void
    {
        $result = Collection::make([1, 2, 2, 3, 1])->unique();
        self::assertSame([1, 2, 3], $result->all());
    }

    public function testUniqueByKey(): void
    {
        $items = [['id' => 1], ['id' => 2], ['id' => 1]];
        $result = Collection::make($items)->unique('id');
        self::assertCount(2, $result->all());
    }

    public function testFlatten(): void
    {
        $result = Collection::make([[1, 2], [3, [4, 5]]])->flatten();
        self::assertSame([1, 2, 3, 4, 5], $result->all());
    }

    public function testFlattenWithDepth(): void
    {
        $result = Collection::make([[1, 2], [3, [4, 5]]])->flatten(1);
        self::assertSame([1, 2, 3, [4, 5]], $result->all());
    }

    public function testMerge(): void
    {
        $result = Collection::make([1, 2])->merge([3, 4]);
        self::assertSame([1, 2, 3, 4], $result->all());
    }

    public function testDiff(): void
    {
        $result = Collection::make([1, 2, 3])->diff([2]);
        self::assertSame([1, 3], $result->all());
    }

    public function testIntersect(): void
    {
        $result = Collection::make([1, 2, 3])->intersect([2, 3, 4]);
        self::assertSame([2, 3], $result->all());
    }

    // -----------------------------------------------------------------
    // Search / check
    // -----------------------------------------------------------------

    public function testContainsValue(): void
    {
        $c = Collection::make([1, 2, 3]);
        self::assertTrue($c->contains(2));
        self::assertFalse($c->contains(5));
    }

    public function testContainsCallback(): void
    {
        $c = Collection::make([1, 2, 3]);
        self::assertTrue($c->contains(fn($v) => $v > 2));
        self::assertFalse($c->contains(fn($v) => $v > 10));
    }

    public function testHas(): void
    {
        $c = Collection::make(['a' => 1]);
        self::assertTrue($c->has('a'));
        self::assertFalse($c->has('b'));
    }

    public function testWhere(): void
    {
        $items = [['active' => true, 'name' => 'Alice'], ['active' => false, 'name' => 'Bob']];
        $result = Collection::make($items)->where('active', true);
        self::assertCount(1, $result->all());
        self::assertSame('Alice', $result->first()['name']);
    }

    // -----------------------------------------------------------------
    // Aggregates
    // -----------------------------------------------------------------

    public function testSum(): void
    {
        self::assertSame(6, Collection::make([1, 2, 3])->sum());
    }

    public function testSumByKey(): void
    {
        $items = [['price' => 10.0], ['price' => 5.5]];
        self::assertSame(15.5, Collection::make($items)->sum('price'));
    }

    public function testAvg(): void
    {
        self::assertEqualsWithDelta(2.0, Collection::make([1, 2, 3])->avg(), 0.001);
    }

    public function testMin(): void
    {
        self::assertSame(1, Collection::make([3, 1, 2])->min());
    }

    public function testMax(): void
    {
        self::assertSame(3, Collection::make([1, 3, 2])->max());
    }

    public function testMinByKey(): void
    {
        $items = [['v' => 5], ['v' => 1], ['v' => 3]];
        self::assertSame(1, Collection::make($items)->min('v'));
    }

    public function testMaxByKey(): void
    {
        $items = [['v' => 5], ['v' => 1], ['v' => 3]];
        self::assertSame(5, Collection::make($items)->max('v'));
    }

    // -----------------------------------------------------------------
    // Access / export
    // -----------------------------------------------------------------

    public function testGet(): void
    {
        $c = Collection::make(['x' => 42]);
        self::assertSame(42, $c->get('x'));
        self::assertSame('default', $c->get('missing', 'default'));
    }

    public function testToJson(): void
    {
        $json = Collection::make(['a' => 1])->toJson();
        self::assertSame('{"a":1}', $json);
    }

    public function testJsonSerialize(): void
    {
        $c = Collection::make([1, 2]);
        self::assertSame('[1,2]', json_encode($c));
    }

    // -----------------------------------------------------------------
    // Mutable helpers
    // -----------------------------------------------------------------

    public function testPush(): void
    {
        $c = Collection::make([1])->push(2);
        self::assertSame([1, 2], $c->all());
    }

    public function testPut(): void
    {
        $c = Collection::make([])->put('key', 'value');
        self::assertSame('value', $c->get('key'));
    }

    public function testForget(): void
    {
        $c = Collection::make(['a' => 1, 'b' => 2])->forget('a');
        self::assertFalse($c->has('a'));
        self::assertTrue($c->has('b'));
    }

    public function testEachBreaksOnFalse(): void
    {
        $collected = [];
        Collection::make([1, 2, 3])->each(function ($v) use (&$collected) {
            $collected[] = $v;
            return $v < 2;
        });
        self::assertSame([1, 2], $collected);
    }

    public function testTransform(): void
    {
        $c = Collection::make([1, 2, 3])->transform(fn($v) => $v * 10);
        self::assertSame([10, 20, 30], $c->all());
    }

    // -----------------------------------------------------------------
    // Interfaces
    // -----------------------------------------------------------------

    public function testCountable(): void
    {
        self::assertCount(3, Collection::make([1, 2, 3]));
    }

    public function testIteratorAggregate(): void
    {
        $result = [];
        foreach (Collection::make([1, 2, 3]) as $v) {
            $result[] = $v;
        }
        self::assertSame([1, 2, 3], $result);
    }

    public function testArrayAccess(): void
    {
        $c = new Collection();
        $c[] = 'a';
        $c[1] = 'b';
        self::assertSame('a', $c[0]);
        self::assertSame('b', $c[1]);
        self::assertTrue(isset($c[0]));
        unset($c[0]);
        self::assertFalse(isset($c[0]));
    }

    public function testKeysAndValues(): void
    {
        $c = Collection::make(['a' => 1, 'b' => 2]);
        self::assertSame(['a', 'b'], $c->keys()->all());
        self::assertSame([1, 2], $c->values()->all());
    }

    public function testFlip(): void
    {
        $result = Collection::make(['a' => 1, 'b' => 2])->flip();
        self::assertSame([1 => 'a', 2 => 'b'], $result->all());
    }
}
