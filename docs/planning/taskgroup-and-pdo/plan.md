# Plan: TaskGroup Refactor & phpdb-async PDO Support

## Status

| Phase | Status |
|---|---|
| Phase 1 — PostgresHandler TaskGroup refactor | **Planned** |
| Phase 2 — phpdb-async PDO Pool support | **Planned** — detail in `src/phpdb-async/docs/pdo-plan.md` |

---

## Phase 1 — Refactor `PostgresHandler::runConcurrently()` to use `Async\TaskGroup`

### Motivation

The current implementation in `runConcurrently()` manually accumulates coroutine
handles in an array and passes them to the `await_all_or_fail()` free function:

```php
$coroutines = [];
foreach ($sqls as $i => $sql) {
    $prof         = $profs[$i];
    $coroutines[] = spawn(fn() => $this->fetchRows($sql, $prof));
}
[$users, $products, $orders, $topProducts] = await_all_or_fail($coroutines);
```

Problems with this approach (as documented in the TrueAsync docs):
- The developer is responsible for ensuring every coroutine is awaited or cancelled.
- A forgotten coroutine keeps running and errors are silently lost.
- `await_all_or_fail` has no logical grouping — there is no single cancellable unit.
- Result indices are positional, requiring destructuring with a fixed order.

`Async\TaskGroup` solves all of these. It provides:
- Guaranteed lifecycle management (all tasks awaited or cancelled when the group is
  destroyed).
- Named results via `spawnWithKey()`, eliminating positional coupling.
- A single `all()->await()` call that returns a keyed array and rejects with
  `CompositeException` if any task fails.
- Future extensibility: add a concurrency limit or swap to `race()` / `any()` without
  restructuring the spawning code.

### Exact Changes

#### `src/App/src/Handler/PostgresHandler.php`

**Imports — remove:**
```php
use function Async\await_all_or_fail;
use function Async\spawn;
```

**Imports — add:**
```php
use Async\TaskGroup;
```

**`runConcurrently()` — replace body:**

Current:
```php
private function runConcurrently(array $profilers): array
{
    $sqls  = array_values(self::QUERIES);
    $profs = array_values($profilers);

    $coroutines = [];
    foreach ($sqls as $i => $sql) {
        $prof         = $profs[$i];
        $coroutines[] = spawn(fn() => $this->fetchRows($sql, $prof));
    }

    [$users, $products, $orders, $topProducts] = await_all_or_fail($coroutines);

    return compact('users', 'products', 'orders', 'topProducts');
}
```

Replacement:
```php
private function runConcurrently(array $profilers): array
{
    $group = new TaskGroup();

    $group->spawnWithKey('users',       fn() => $this->fetchRows(self::QUERIES['Users (top 20 by id)'],             $profilers['Users (top 20 by id)']));
    $group->spawnWithKey('products',    fn() => $this->fetchRows(self::QUERIES['Products (top 20 by price)'],      $profilers['Products (top 20 by price)']));
    $group->spawnWithKey('orders',      fn() => $this->fetchRows(self::QUERIES['Recent orders (top 20)'],          $profilers['Recent orders (top 20)']));
    $group->spawnWithKey('topProducts', fn() => $this->fetchRows(self::QUERIES['Top products by revenue (top 10)'], $profilers['Top products by revenue (top 10)']));

    return $group->all()->await();
}
```

`all()` returns `['users' => [...], 'products' => [...], 'orders' => [...], 'topProducts' => [...]]`
which is exactly the shape expected by `query()` and `renderResponse()` — no further
mapping needed.

**Constant comment — update `DEFAULT_MODES` doc-block:**
```
concurrent  — 4 queries spawned as coroutines via Async\TaskGroup::all(); HTML response
```

**Template comment** (`src/App/templates/app/postgres.phtml` line ~14):
```phtml
<!-- update description mentioning await_all_or_fail to mention TaskGroup -->
```

#### Files affected
| File | Change |
|---|---|
| `src/App/src/Handler/PostgresHandler.php` | Replace `runConcurrently()`, update imports, update comment |
| `src/App/templates/app/postgres.phtml` | Update UI label referencing `await_all_or_fail()` |
| `docs/load-testing/overview.md` | Update paragraph referencing `await_all_or_fail` as key primitive |

#### No behavioural change
- `runSequentially()` is untouched — it does not use coroutines.
- `fetchRows()` is untouched.
- The result shape `['users', 'products', 'orders', 'topProducts']` is identical.
- Error handling: `TaskGroup::all()` rejects with `CompositeException` (which is a
  `Throwable`), so the existing `catch (Throwable $e)` in `query()` handles it correctly.

---

## Phase 2 — phpdb-async PDO Pool support

### Status: Fully planned
See the detailed plan at `src/phpdb-async/docs/pdo-plan.md`.

### Summary

PDO Pool is architecturally distinct from the existing `Async\Pool` pgsql path.
The pool is baked into the `PDO` object itself via `PDO::ATTR_POOL_ENABLED => true` —
PHP core manages connection-to-coroutine binding transparently. There is no
`Async\Pool` object, no `acquire()`/`release()`, and no `suspend()` polling loop.

All existing `Pgsql/` code is **unchanged**. Vendor inspection of `php-db/phpdb-pgsql`
shows that `PhpDb\Pgsql\Pdo\Driver` already accepts a raw `PDO` instance, and
`PhpDb\Pgsql\AdapterPlatform` already accepts a `PdoDriverInterface`. New files are
minimal:

```
src/phpdb-async/src/
  Pdo/
    Driver.php                   ← extends PhpDb\Pgsql\Pdo\Driver; builds pool-enabled PDO
  Container/
    PdoAdapterFactory.php        ← builds Driver + platform + core Adapter
    PdoAbstractAdapterFactory.php← named PDO adapters (mirrors AbstractAdapterFactory)
```

`Statement`, `Result`, `Platform`, and `Connection` are all reused from existing vendor
classes — no custom implementations needed. The returned adapter is a plain
`PhpDb\Adapter\Adapter` (not `PhpDb\Async\Adapter`) because `PhpDb\Async\Adapter`
exists specifically to add `Async\Pool` slot management — which PDO Pool makes
redundant.

---

## Change Log

| Date | Change |
|---|---|
| 2026-04-20 | Plan created; Phase 1 fully specified; Phase 2 fully planned (see pdo-plan.md) |
