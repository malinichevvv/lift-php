<?php

declare(strict_types=1);

namespace Lift\Database;

/**
 * Fluent SQL query builder.
 *
 * All user values are passed as bound parameters — never interpolated into SQL.
 * Column and table names go through {@see Grammar::wrap()} which only quotes
 * plain identifiers and leaves raw expressions untouched.
 *
 * ```php
 * $users = $db->table('users')
 *     ->select('id', 'name', 'email')
 *     ->where('active', 1)
 *     ->where('age', '>=', 18)
 *     ->orderBy('name')
 *     ->limit(20)
 *     ->get();
 * ```
 */
final class QueryBuilder
{
    private const array OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE', 'ILIKE'];

    /** @var string[] */
    private array $selects = ['*'];
    private bool $isDistinct = false;
    /** @var array<int, array<string, mixed>> */
    private array $joins = [];
    /** @var array<int, array<string, mixed>> */
    private array $wheres = [];
    /** @var string[] */
    private array $groups = [];
    /** @var array<int, array<string, mixed>> */
    private array $havings = [];
    /** @var array<int, array<string, string>> */
    private array $orders = [];
    private ?int $limitVal  = null;
    private ?int $offsetVal = null;
    /** @var 'update'|'share'|null */
    private ?string $lock = null;
    private bool $lockSkipLocked = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly Grammar    $grammar,
        private readonly string     $table,
    ) {}

    // -----------------------------------------------------------------
    // SELECT modifiers
    // -----------------------------------------------------------------

    public function select(string ...$columns): self
    {
        $this->selects = $columns ?: ['*'];
        return $this;
    }

    public function addSelect(string ...$columns): self
    {
        array_push($this->selects, ...$columns);
        return $this;
    }

    public function distinct(): self
    {
        $this->isDistinct = true;
        return $this;
    }

    // -----------------------------------------------------------------
    // JOINs
    // -----------------------------------------------------------------

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $typeUpper = strtoupper($type);
        if (!in_array($typeUpper, ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'FULL', 'FULL OUTER'], true)) {
            throw new \InvalidArgumentException("Invalid JOIN type: [{$type}]");
        }
        $this->joins[] = ['type' => $typeUpper, 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    // -----------------------------------------------------------------
    // WHERE
    // -----------------------------------------------------------------

    /**
     * Add a WHERE condition.
     *
     * Two-argument form: `where('column', $value)` → `column = ?`
     * Three-argument form: `where('column', '>=', $value)` → `column >= ?`
     * Null value: `where('column', null)` → `column IS NULL`
     */
    public function where(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): self
    {
        // Detect 2-argument form: second arg is the value, not an operator
        $isTwoArg = $value === null
            && !in_array(is_string($operator) ? strtoupper($operator) : '', self::OPERATORS, true);

        if ($isTwoArg) {
            if ($operator === null) {
                return $this->whereNull($column, $boolean);
            }
            $value    = $operator;
            $operator = '=';
        } elseif ($value === null) {
            return $this->whereNull($column, $boolean);
        }

        $opUpper = strtoupper((string) $operator);
        if (!in_array($opUpper, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid WHERE operator: [{$operator}]");
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $opUpper,
            'value'    => $value,
            'boolean'  => strtoupper($boolean),
        ];
        return $this;
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if (empty($values)) {
            // Empty IN() — always false for IN, always true for NOT IN
            if (!$not) {
                $this->wheres[] = ['type' => 'raw', 'sql' => '0 = 1', 'bindings' => [], 'boolean' => strtoupper($boolean)];
            }
            return $this;
        }
        $this->wheres[] = [
            'type'    => $not ? 'not_in' : 'in',
            'column'  => $column,
            'values'  => $values,
            'boolean' => strtoupper($boolean),
        ];
        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'boolean' => strtoupper($boolean)];
        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = ['type' => 'not_null', 'column' => $column, 'boolean' => strtoupper($boolean)];
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type'    => 'between',
            'column'  => $column,
            'min'     => $min,
            'max'     => $max,
            'boolean' => strtoupper($boolean),
        ];
        return $this;
    }

    /** Add a raw WHERE expression with explicit bindings. */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => $bindings, 'boolean' => strtoupper($boolean)];
        return $this;
    }

    // -----------------------------------------------------------------
    // GROUP / HAVING / ORDER
    // -----------------------------------------------------------------

    public function groupBy(string ...$columns): self
    {
        array_push($this->groups, ...$columns);
        return $this;
    }

    public function having(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value    = $operator;
            $operator = '=';
        }
        $opUpper = strtoupper((string) $operator);
        if (!in_array($opUpper, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid HAVING operator: [{$operator}]");
        }
        $this->havings[] = ['column' => $column, 'operator' => $opUpper, 'value' => $value];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orders[] = ['column' => $column, 'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'];
        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    // -----------------------------------------------------------------
    // LIMIT / OFFSET
    // -----------------------------------------------------------------

    public function limit(int $value): self
    {
        $this->limitVal = max(0, $value);
        return $this;
    }

    public function offset(int $value): self
    {
        $this->offsetVal = max(0, $value);
        return $this;
    }

    public function skip(int $value): self
    {
        return $this->offset($value);
    }

    public function take(int $value): self
    {
        return $this->limit($value);
    }

    // -----------------------------------------------------------------
    // Pessimistic locking
    // -----------------------------------------------------------------

    /**
     * Lock the selected rows exclusively for the duration of the transaction.
     *
     * Appends `FOR UPDATE [SKIP LOCKED]` to the SELECT. On SQLite the clause is
     * silently omitted — use a transaction with `BEGIN EXCLUSIVE` instead.
     *
     * ```php
     * $db->transaction(function () use ($db) {
     *     $job = $db->table('jobs')
     *         ->where('status', 'pending')
     *         ->orderBy('id')
     *         ->limit(1)
     *         ->forUpdate(skipLocked: true)
     *         ->first();
     *     if ($job) {
     *         $db->table('jobs')->where('id', $job['id'])->update(['status' => 'running']);
     *     }
     * });
     * ```
     */
    public function forUpdate(bool $skipLocked = false): self
    {
        $this->lock           = 'update';
        $this->lockSkipLocked = $skipLocked;
        return $this;
    }

    /**
     * Acquire a shared lock on the selected rows.
     *
     * Appends `FOR SHARE` (PostgreSQL) or `LOCK IN SHARE MODE` (MySQL) to the SELECT.
     * Other readers can still acquire shared locks; writers are blocked.
     */
    public function sharedLock(bool $skipLocked = false): self
    {
        $this->lock           = 'share';
        $this->lockSkipLocked = $skipLocked;
        return $this;
    }

    // -----------------------------------------------------------------
    // Execution — reads
    // -----------------------------------------------------------------

    /** Execute SELECT and return all rows. */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->getBindings());
    }

    /** Execute SELECT LIMIT 1 and return the first row or null. */
    public function first(): ?array
    {
        return $this->limit(1)->connection->selectOne($this->toSql(), $this->getBindings());
    }

    /**
     * Return a single column value from the first matching row.
     *
     * @return mixed Null if no row matched.
     */
    public function value(string $column): mixed
    {
        return $this->select($column)->limit(1)->connection->value($this->toSql(), $this->getBindings());
    }

    /** Return an array of values from a single column. */
    public function pluck(string $column): array
    {
        $rows = $this->select($column)->get();
        return array_column($rows, $column);
    }

    // -----------------------------------------------------------------
    // Aggregates
    // -----------------------------------------------------------------

    public function count(string $column = '*'): int
    {
        $col  = $column === '*' ? '*' : $this->grammar->wrap($column);
        $row  = $this->selectRaw("COUNT({$col}) as aggregate")->first();
        return (int) ($row['aggregate'] ?? 0);
    }

    public function sum(string $column): float
    {
        $row = $this->selectRaw("SUM({$this->grammar->wrap($column)}) as aggregate")->first();
        return (float) ($row['aggregate'] ?? 0);
    }

    public function avg(string $column): float
    {
        $row = $this->selectRaw("AVG({$this->grammar->wrap($column)}) as aggregate")->first();
        return (float) ($row['aggregate'] ?? 0);
    }

    public function min(string $column): float
    {
        return (float) ($this->selectRaw("MIN({$this->grammar->wrap($column)}) as aggregate")->first()['aggregate'] ?? 0);
    }

    public function max(string $column): float
    {
        return (float) ($this->selectRaw("MAX({$this->grammar->wrap($column)}) as aggregate")->first()['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    /**
     * Paginate results.
     *
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int, from: int, to: int}
     */
    /**
     * Execute the query and return a {@see Paginator} for the given page.
     *
     * The total row count is fetched with a separate `COUNT(*)` query.
     *
     * ```php
     * $page = $db->table('posts')
     *     ->where('published', 1)
     *     ->orderBy('created_at', 'DESC')
     *     ->paginate(page: 2, perPage: 10, path: '/posts');
     *
     * return Response::json($page); // auto-serialises to pagination envelope
     * ```
     *
     * @param string $path  Base URL used by {@see Paginator::links()} for generating `<a href>` tags.
     */
    public function paginate(int $page = 1, int $perPage = 15, string $path = ''): Paginator
    {
        $page    = max(1, $page);
        $total   = $this->count();
        $items   = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return new Paginator($items, $total, $perPage, $page, $path);
    }

    /**
     * Process results in chunks to avoid loading all rows into memory.
     */
    public function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $results = $this->limit($size)->offset(($page - 1) * $size)->get();
            if (empty($results)) {
                break;
            }
            if ($callback($results, $page) === false) {
                break;
            }
            $page++;
        } while (count($results) === $size);
    }

    /**
     * Return a generator that yields one row at a time.
     *
     * Unlike {@see get()} which loads all rows into memory at once, `cursor()`
     * streams results row-by-row — memory stays near-constant for large tables.
     *
     * ```php
     * foreach ($db->table('events')->where('processed', 0)->cursor() as $row) {
     *     processEvent($row);
     * }
     * ```
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        return $this->connection->selectCursor($this->toSql(), $this->getBindings());
    }

    // -----------------------------------------------------------------
    // Execution — writes
    // -----------------------------------------------------------------

    /**
     * Insert a row and return the last insert ID (or false on failure).
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string|false
    {
        $cols   = array_map(fn($c) => $this->grammar->wrap($c), array_keys($data));
        $marks  = array_fill(0, count($data), '?');
        $sql    = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->grammar->wrap($this->table),
            implode(', ', $cols),
            implode(', ', $marks),
        );
        $this->connection->execute($sql, array_values($data));
        return $this->connection->lastInsertId();
    }

    /**
     * Bulk-insert multiple rows.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertMany(array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $cols  = array_map(fn($c) => $this->grammar->wrap($c), array_keys($rows[0]));
        $mark  = '(' . implode(', ', array_fill(0, count($rows[0]), '?')) . ')';
        $marks = implode(', ', array_fill(0, count($rows), $mark));
        $sql   = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->grammar->wrap($this->table),
            implode(', ', $cols),
            $marks,
        );
        $bindings = array_merge(...array_map('array_values', $rows));
        $this->connection->execute($sql, $bindings);
    }

    /**
     * Update rows matching the current WHERE conditions.
     *
     * @param array<string, mixed> $data
     * @return int Number of affected rows.
     */
    public function update(array $data): int
    {
        $sets = array_map(fn($c) => $this->grammar->wrap($c) . ' = ?', array_keys($data));
        $sql  = sprintf('UPDATE %s SET %s', $this->grammar->wrap($this->table), implode(', ', $sets));

        $bindings = array_values($data);

        if ($this->wheres) {
            $sql      .= ' WHERE ' . $this->compileWheres();
            $bindings  = array_merge($bindings, $this->whereBindings());
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Delete rows matching the current WHERE conditions.
     *
     * @return int Number of deleted rows.
     */
    public function delete(): int
    {
        $sql = sprintf('DELETE FROM %s', $this->grammar->wrap($this->table));

        $bindings = [];
        if ($this->wheres) {
            $sql      .= ' WHERE ' . $this->compileWheres();
            $bindings  = $this->whereBindings();
        }

        return $this->connection->execute($sql, $bindings);
    }

    // -----------------------------------------------------------------
    // SQL compilation
    // -----------------------------------------------------------------

    /** Compile the query to a SQL string (without executing). */
    public function toSql(): string
    {
        $sql = 'SELECT ';

        if ($this->isDistinct) {
            $sql .= 'DISTINCT ';
        }

        $cols = array_map(fn($c) => $c === '*' ? '*' : $this->grammar->wrap($c), $this->selects);
        $sql .= implode(', ', $cols);

        $sql .= ' FROM ' . $this->grammar->wrap($this->table);

        foreach ($this->joins as $j) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s %s %s',
                $j['type'],
                $this->grammar->wrap($j['table']),
                $this->grammar->wrap($j['first']),
                $j['operator'],
                $this->grammar->wrap($j['second']),
            );
        }

        if ($this->wheres) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        if ($this->groups) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($g) => $this->grammar->wrap($g), $this->groups));
        }

        if ($this->havings) {
            $parts = [];
            foreach ($this->havings as $h) {
                $parts[] = $this->grammar->wrap($h['column']) . ' ' . $h['operator'] . ' ?';
            }
            $sql .= ' HAVING ' . implode(' AND ', $parts);
        }

        if ($this->orders) {
            $parts = array_map(
                fn($o) => $this->grammar->wrap($o['column']) . ' ' . $o['direction'],
                $this->orders,
            );
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($this->limitVal !== null) {
            $sql .= ' LIMIT ' . $this->limitVal;
        }

        if ($this->offsetVal !== null) {
            $sql .= ' OFFSET ' . $this->offsetVal;
        }

        if ($this->lock !== null) {
            $sql .= $this->grammar->compileLock($this->lock, $this->lockSkipLocked);
        }

        return $sql;
    }

    /** All bound values in the order they appear in {@see toSql()}. */
    public function getBindings(): array
    {
        $b = $this->whereBindings();
        foreach ($this->havings as $h) {
            $b[] = $h['value'];
        }
        return $b;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function selectRaw(string $expression): self
    {
        $clone          = clone $this;
        $clone->selects = [$expression];
        return $clone;
    }

    private function compileWheres(): string
    {
        $parts = [];
        foreach ($this->wheres as $i => $w) {
            $prefix  = $i === 0 ? '' : $w['boolean'] . ' ';
            $parts[] = $prefix . $this->compileWhere($w);
        }
        return implode(' ', $parts);
    }

    private function compileWhere(array $w): string
    {
        return match ($w['type']) {
            'basic'    => $this->grammar->wrap($w['column']) . ' ' . $w['operator'] . ' ?',
            'null'     => $this->grammar->wrap($w['column']) . ' IS NULL',
            'not_null' => $this->grammar->wrap($w['column']) . ' IS NOT NULL',
            'in'       => $this->grammar->wrap($w['column']) . ' IN (' . implode(', ', array_fill(0, count($w['values']), '?')) . ')',
            'not_in'   => $this->grammar->wrap($w['column']) . ' NOT IN (' . implode(', ', array_fill(0, count($w['values']), '?')) . ')',
            'between'  => $this->grammar->wrap($w['column']) . ' BETWEEN ? AND ?',
            'raw'      => $w['sql'],
            default    => '1=1',
        };
    }

    private function whereBindings(): array
    {
        $b = [];
        foreach ($this->wheres as $w) {
            match ($w['type']) {
                'basic'              => $b[] = $w['value'],
                'in', 'not_in'       => array_push($b, ...$w['values']),
                'between'            => [$b[] = $w['min'], $b[] = $w['max']],
                'raw'                => array_push($b, ...$w['bindings']),
                default              => null,
            };
        }
        return $b;
    }
}
