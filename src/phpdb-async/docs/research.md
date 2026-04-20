# Research Notes: PhpDb\Pgsql\Statement — ParameterContainer Shallow-Clone Bug

## Summary

`PhpDb\Pgsql\Driver::createStatement()` clones the Statement prototype but does **not**
deep-clone its `ParameterContainer`. When multiple inserts are executed through
`TableGateway` against different tables in the same request, the shared container
accumulates keys across table boundaries, causing PostgreSQL's `pg_execute()` to receive
more bound parameters than the prepared statement declares placeholders for.

---

## Root Cause

### `Driver::createStatement()`

```php
// vendor/php-db/phpdb-pgsql/src/Driver.php
public function createStatement($sqlOrResource = null): StatementInterface&Statement
{
    $statement = clone $this->statementPrototype; // shallow clone
    // ...
}
```

`clone` copies the `Statement` object but **does not recursively clone properties**.
`$statement->parameterContainer` still references the **same** `ParameterContainer`
instance as `$this->statementPrototype->parameterContainer`.

### `AbstractPreparableSql::prepareStatement()`

```php
// vendor/php-db/phpdb/src/Sql/AbstractPreparableSql.php
$parameterContainer = $statementContainer->getParameterContainer();

if (! $parameterContainer instanceof ParameterContainer) {
    // Only creates a NEW ParameterContainer if there is NOT one already.
    $parameterContainer = new ParameterContainer();
    $statementContainer->setParameterContainer($parameterContainer);
}

$statementContainer->setSql(
    $this->buildSqlString($adapter->getPlatform(), $adapter->getDriver(), $parameterContainer)
);
```

Because the cloned statement **already has** a `ParameterContainer` (inherited from the
prototype), this guard never fires. `buildSqlString` appends new named keys (e.g.
`username`, `email`, `created_at` for the users table) into the **existing** container.

### Accumulation Across Tables

`TableGateway` instantiates a new `TableGateway` per table, but all share the same
`Driver` (and therefore the same `statementPrototype`). Execution order during seeding:

| Call | Keys Added to Shared ParameterContainer |
|------|-----------------------------------------|
| users INSERT (3 cols) | `username`, `email`, `created_at` |
| products INSERT (4 cols) | `name`, `price`, `stock`, `created_at` (already present — skipped; new keys added) |

After the second table, `getPositionalArray()` returns more values than the `$1…$N`
placeholders in the prepared SQL string, and PostgreSQL rejects the execute:

```
pg_execute(): Query failed: ERROR:  bind message supplies 6 parameters,
but prepared statement "statement21" requires 4
```

---

## Required Fix in `phpdb-pgsql`

Add `__clone()` to `PhpDb\Pgsql\Statement` so that each `Driver::createStatement()` call
receives a fully independent `ParameterContainer`:

```php
// vendor/php-db/phpdb-pgsql/src/Statement.php

public function __clone(): void
{
    $this->parameterContainer = clone $this->parameterContainer;
}
```

This is the minimal, correct fix. Deep-cloning `ParameterContainer` is safe because it
contains only scalar values (`data`, `positions`, `errata`, `maxLength`, `nameMapping`
arrays) — no nested objects that would require further recursion.

### Verification

After the fix, the following sequence must work without error:

```php
$gw1 = new TableGateway('bm_users',    $adapter);
$gw2 = new TableGateway('bm_products', $adapter);
$gw3 = new TableGateway('bm_orders',   $adapter);

$gw1->insert(['username' => 'alice', 'email' => 'alice@example.com', 'created_at' => '2026-01-01']);
$gw2->insert(['name' => 'Widget', 'price' => 9.99, 'stock' => 100, 'created_at' => '2026-01-01']);
$gw3->insert(['user_id' => 1, 'total' => 9.99, 'status' => 'pending', 'created_at' => '2026-01-01']);
```

Each call must prepare and execute with exactly the number of `$N` parameters declared in
its own INSERT SQL, regardless of any prior `TableGateway` calls in the same process.

---

## Workaround (Applied in `PostgresHandler`)

Until the upstream fix is released, `PostgresHandler::seed()` bypasses `TableGateway`
entirely and uses `AdapterInterface::QUERY_MODE_EXECUTE` with platform-quoted values:

```php
$p = $this->dbAdapter->getPlatform();

$this->dbAdapter->query(
    sprintf(
        'INSERT INTO bm_users (username, email, created_at) VALUES (%s, %s, %s)',
        $p->quoteValue($row['username']),
        $p->quoteValue($row['email']),
        $p->quoteValue($row['created_at']),
    ),
    AdapterInterface::QUERY_MODE_EXECUTE
);
```

`QUERY_MODE_EXECUTE` calls `Connection::execute()` (i.e. `pg_query()`) directly — no
prepared statement, no `ParameterContainer` involvement.

---

## Affected Package

- **Package:** `php-db/phpdb-pgsql`
- **File:** `src/Statement.php`
- **Class:** `PhpDb\Pgsql\Statement`
- **Fix:** Add `public function __clone(): void { $this->parameterContainer = clone $this->parameterContainer; }`
