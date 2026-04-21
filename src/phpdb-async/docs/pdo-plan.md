# Plan: phpdb-async PDO Support

**Reference:** https://true-async.github.io/en/docs/components/pdo-pool.html  
**Status:** Planned — not yet implemented  
**Scope:** Additive only. All existing `Pgsql/` code is unchanged.

---

## Background & Architectural Difference

The existing pgsql path uses `Async\Pool` as the concurrency primitive:

```
Async\Pool (slot = AdapterInterface)
  └── PhpDb\Async\Pgsql\Connection (pg_send_query + suspend() polling)
```

`Adapter` manually calls `$pool->acquire()` / `$pool->release()` around every query,
and `ConnectionManager` builds independent adapter+connection instances per slot.

**PDO Pool works completely differently.** The pool is baked directly into the `PDO`
object via constructor attributes — `PDO::ATTR_POOL_ENABLED => true`. PHP core manages
the connection-to-coroutine binding transparently. There is no `Async\Pool` object to
manage, no `acquire()`/`release()` calls, and no `suspend()` polling loop.

---

## What Already Exists in the Vendor Packages

Inspecting `php-db/phpdb` and `php-db/phpdb-pgsql` reveals that almost everything
needed for the PDO path already exists:

| Class | Location | Notes |
|---|---|---|
| `PhpDb\Pgsql\Pdo\Driver` | `vendor/php-db/phpdb-pgsql/src/Pdo/Driver.php` | Extends `AbstractPdo`; constructor accepts `PDO\|PdoConnectionInterface` |
| `PhpDb\Pgsql\Pdo\Connection` | `vendor/php-db/phpdb-pgsql/src/Pdo/Connection.php` | Extends `AbstractPdoConnection`; builds PDO from params or accepts raw PDO |
| `PhpDb\Adapter\Driver\Pdo\Statement` | `vendor/php-db/phpdb/src/Adapter/Driver/Pdo/Statement.php` | Generic PDO statement wrapper |
| `PhpDb\Adapter\Driver\Pdo\Result` | `vendor/php-db/phpdb/src/Adapter/Driver/Pdo/Result.php` | Generic PDO result wrapper |
| `PhpDb\Pgsql\AdapterPlatform` | `vendor/php-db/phpdb-pgsql/src/AdapterPlatform.php` | Constructor accepts `DriverInterface\|PdoDriverInterface\|PDO` |

**Critically:** `PhpDb\Pgsql\Pdo\Driver.__construct()` signature is:

```php
public function __construct(
    (PdoConnectionInterface&PdoDriverAwareInterface)|PDO $connection,
    StatementInterface&PdoDriverAwareInterface $statementPrototype = new Statement(),
    ResultInterface $resultPrototype = new Result(),
)
```

It already accepts a raw `PDO` instance directly. When a raw `PDO` is passed, it skips
the `setDriver()` call on the connection — no connection wrapper needed.

`PhpDb\Pgsql\AdapterPlatform.__construct()` accepts `DriverInterface|PdoDriverInterface|PDO`,
so the existing platform works without any subclassing.

---

## Conclusion: Minimum New Code Required

A custom `PhpDb\Async\Pdo\Driver` subclass is potentially the **only new production
class needed**. It extends `PhpDb\Pgsql\Pdo\Driver` and takes responsibility for
constructing the pool-enabled `PDO` from connection parameters + pool config, so that
consumers never need to know about `PDO::ATTR_POOL_ENABLED`:

```php
// PhpDb\Async\Pdo\Driver
final class Driver extends \PhpDb\Pgsql\Pdo\Driver
{
    public function __construct(
        array $connectionConfig,
        array $poolConfig = [],
        StatementInterface&PdoDriverAwareInterface $statementPrototype = new Statement(),
        ResultInterface $resultPrototype = new Result(),
    ) {
        $pdo = new PDO(
            $connectionConfig['dsn'],
            $connectionConfig['username'] ?? null,
            $connectionConfig['password'] ?? null,
            [
                PDO::ATTR_ERRMODE                   => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_POOL_ENABLED              => true,
                PDO::ATTR_POOL_MIN                  => (int) ($poolConfig['min'] ?? 2),
                PDO::ATTR_POOL_MAX                  => (int) ($poolConfig['max'] ?? 10),
                PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => (int) ($poolConfig['healthcheck_interval'] ?? 30),
            ],
        );

        parent::__construct($pdo, $statementPrototype, $resultPrototype);
    }
}
```

The rest of the stack reuses existing vendor classes unchanged:

| Role | Class | Source |
|---|---|---|
| Statement | `PhpDb\Adapter\Driver\Pdo\Statement` | existing vendor |
| Result | `PhpDb\Adapter\Driver\Pdo\Result` | existing vendor |
| Platform | `PhpDb\Pgsql\AdapterPlatform` | existing vendor (accepts `PdoDriverInterface`) |
| Core adapter | `PhpDb\Adapter\Adapter($driver, $platform)` | existing vendor |

No `Async\Pool`, no `ConnectionManager`, no `acquire()`/`release()`, no `suspend()` loop.

---

## New Files

```
src/phpdb-async/src/
  Pdo/
    Driver.php                    ← extends PhpDb\Pgsql\Pdo\Driver; builds pool-enabled PDO
  Container/
    PdoAdapterFactory.php         ← DI factory; instantiates Driver + platform + Adapter
    PdoAbstractAdapterFactory.php ← named PDO adapters (mirrors AbstractAdapterFactory)
```

### `Pdo/Driver.php`

As shown above. Key points:
- `final class Driver extends \PhpDb\Pgsql\Pdo\Driver`
- Constructor accepts `array $connectionConfig` (with a `dsn` key) + `array $poolConfig`
- Builds the pool-enabled `PDO` and passes it to `parent::__construct()`
- Requires `true_async` extension — guard with `extension_loaded('true_async')` check,
  consistent with the existing pgsql path in `AdapterFactory`

### `Container/PdoAdapterFactory.php`

```php
public function __invoke(ContainerInterface $container): AdapterInterface
{
    if (! extension_loaded('true_async')) {
        throw new RuntimeException('The true_async extension is required ...');
    }

    $config           = $container->has('config') ? $container->get('config') : [];
    $adapterConfig    = $config['pdo-adapter'] ?? [];

    $driver   = new Pdo\Driver(
        $adapterConfig['connection'] ?? [],
        $adapterConfig['pool'] ?? [],
    );
    $platform = new AdapterPlatform($driver);   // PhpDb\Pgsql\AdapterPlatform
    return new CoreAdapter($driver, $platform); // PhpDb\Adapter\Adapter
}
```

- No `Async\Pool`, no `ConnectionManager`, no `primary` adapter
- Returns a plain `PhpDb\Adapter\Adapter` (not `PhpDb\Async\Adapter`)

### `Container/PdoAbstractAdapterFactory.php`

Mirrors `AbstractAdapterFactory` for named PDO adapters under a `'pdo-adapters'` config
key, enabling separate read/write pools if needed.

---

## Config Structure

```php
// config/autoload/pdo.local.php
return [
    'pdo-adapter' => [
        'connection' => [
            'dsn'      => 'pgsql:host=postgres;port=5432;dbname=phpdb_test',
            'username' => 'postgres',
            'password' => 'postgres',
        ],
        'pool' => [
            'min'                  => 2,
            'max'                  => 10,
            'healthcheck_interval' => 30,  // seconds; maps to PDO::ATTR_POOL_HEALTHCHECK_INTERVAL
        ],
    ],
];
```

The existing pgsql pool config (under `AdapterInterface::class`) remains unchanged.

---

## ConfigProvider Changes

`PhpDb\Async\ConfigProvider` gains PDO registrations alongside the existing pgsql ones:

```php
'factories' => [
    Adapter::class => Container\AdapterFactory::class,     // existing pgsql
    'pdo-adapter'  => Container\PdoAdapterFactory::class,  // new
],
'abstract_factories' => [
    Container\AbstractAdapterFactory::class,     // existing pgsql
    Container\PdoAbstractAdapterFactory::class,  // new
],
```

---

## Supported PDO Drivers

| Driver | Pool supported |
|---|---|
| `pdo_pgsql` | Yes |
| `pdo_mysql` | Yes |
| `pdo_sqlite` | Yes |
| `pdo_odbc` | No |

`PhpDb\Async\Pdo\Driver` is driver-agnostic — the `connection.dsn` prefix selects the
backend. No subclassing needed for MySQL or SQLite.

---

## What Does NOT Change

- `Adapter.php` — untouched (pgsql `Async\Pool` adapter)
- `Statement.php` — untouched (pgsql pool-slot-releasing wrapper)
- `Pgsql/Connection.php` — untouched
- `Container/ConnectionManager.php` — untouched
- `Container/AdapterFactory.php` — untouched
- `Container/AbstractAdapterFactory.php` — untouched
- `PostgresHandler` — continues to use the pgsql `Adapter` unchanged

---

## Open Questions (to resolve before implementation)

1. **DSN construction:** `PhpDb\Pgsql\Pdo\Connection` can build its own DSN from a
   flat host/port/database param map when a `dsn` key is absent. Decide whether
   `PhpDb\Async\Pdo\Driver` should accept the same flat map and build the DSN, or
   require a pre-built `dsn` string for simplicity.

2. **`extension_loaded` guard:** Pool constants are only defined when `true_async` is
   loaded. Decide whether to throw in the constructor if the extension is absent, or
   silently omit pool attributes so the class degrades to a non-pooled PDO in sync
   environments.

3. **`PostgresHandler` PDO mode:** A future `?adapter=pdo` query param could benchmark
   pgsql-native vs PDO-pool latency. Out of scope for initial implementation but keep
   the DI service name (`'pdo-adapter'`) in mind when designing the factory.

---

## Change Log

| Date | Change |
|---|---|
| 2026-04-20 | Plan created from PDO Pool docs; architectural analysis complete |
| 2026-04-20 | Revised after vendor inspection: `PhpDb\Pgsql\Pdo\Driver` already accepts raw PDO; only `Pdo\Driver` subclass + factory needed |
