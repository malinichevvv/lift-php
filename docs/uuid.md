---
layout: page
title: UUID & ULID
nav_order: 12
---

# UUID & ULID

`Lift\Support\Uuid` generates cryptographically secure identifiers. All formats use `random_bytes()` for their random component.

---

## Formats at a glance

| Format | Length | Sortable | Use case |
|--------|--------|----------|----------|
| UUID v4 | 36 chars | No | General purpose, maximum compatibility |
| UUID v7 | 36 chars | Yes | Database PKs, distributed IDs, logs |
| ULID | 26 chars | Yes | Human-readable, URL-safe, compact |

---

## UUID v4 — random

122 bits of cryptographic randomness. No time component — maximally opaque.

```php
use Lift\Support\Uuid;

$id = Uuid::v4();
// e.g. "550e8400-e29b-41d4-a716-446655440000"
```

---

## UUID v7 — time-ordered

The first 48 bits encode a millisecond-precision Unix timestamp. Subsequent UUIDv7 values sort lexicographically, making them ideal for **database primary keys** (B-tree friendly, no index fragmentation).

```php
$id = Uuid::v7();
// e.g. "018f8e0d-1c2a-7xxx-xxxx-xxxxxxxxxxxx"
//       ^^^^^^^^ ms timestamp prefix

// With a specific timestamp (useful for tests)
$id = Uuid::v7(ms: 1_718_700_000_000);
```

### Database schema

```sql
-- MySQL / MariaDB
CREATE TABLE users (
    id       BINARY(16)   NOT NULL PRIMARY KEY DEFAULT (UUID_TO_BIN(UUID(), 1)),
    -- or store as CHAR(36) if BINARY(16) is too inconvenient
    name     VARCHAR(255) NOT NULL
);
```

Store as `BINARY(16)` using the binary helpers:

```php
// Store
$binary = Uuid::toBinary(Uuid::v7());

// Retrieve
$uuid = Uuid::fromBinary($row['id']);
```

---

## ULID — Universally Unique Lexicographically Sortable Identifier

26-character Crockford base32 string. 10-char time prefix (ms precision) + 16-char random suffix.

**Advantages over UUID:**
- 26 vs 36 characters — more compact
- No dashes — cleaner in URLs, logs, and CLI output
- Case-insensitive
- Excludes ambiguous characters (I, L, O, U)

```php
$id = Uuid::ulid();
// e.g. "01ARZ3NDEKTSV4RRFFQ69G5FAV"

// Specific timestamp
$id = Uuid::ulid(ms: 1_718_700_000_000);
```

---

## Validation

```php
Uuid::isValid('550e8400-e29b-41d4-a716-446655440000'); // true
Uuid::isValid('not-a-uuid');                           // false

Uuid::isValidUlid('01ARZ3NDEKTSV4RRFFQ69G5FAV'); // true
Uuid::isValidUlid('INVALID');                     // false
```

`isValid()` accepts UUID versions 1–8 (case-insensitive).

---

## Binary encoding

Store UUIDs efficiently in a `BINARY(16)` column (half the storage of `CHAR(36)`):

```php
// On write
$binary = Uuid::toBinary($uuid);  // 16 raw bytes

// On read
$uuid = Uuid::fromBinary($binary);  // back to "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
```

---

## Choosing the right format

```
New project, DB primary keys?   → UUID v7  (sortable, B-tree friendly)
URL slugs, log correlation IDs? → ULID     (compact, URL-safe)
Compatibility with existing UUIDs? → UUID v4
```
