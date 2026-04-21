# k6 Load Testing — Dev Container Usage

k6 is installed automatically via `postCreateCommand` in `.devcontainer/devcontainer.json`.
All commands below run directly inside the dev container terminal.

---

## Prerequisites

1. Server must be running: `php bin/mezzio-async start`
2. Tables must be seeded before running postgres tests:
   ```bash
   curl "http://localhost:8080/postgres/pgsql?action=setup"
   curl "http://localhost:8080/postgres/pdo?action=setup"
   ```

---

## Available Scripts

| Script | Description | Endpoint(s) |
|---|---|---|
| `baseline.js` | 1 VU, 15s — functional smoke test | `/postgres/pgsql`, `/postgres/pdo` |
| `ramp-pgsql.js` | 1→10 VUs ramp — native pgsql adapter | `/postgres/pgsql` |
| `ramp-pdo.js` | 1→10 VUs ramp — PDO pool adapter | `/postgres/pdo` |
| `ramp-spawn.js` | 1→10 VUs ramp — spawn() isolation test | `/postgres/spawn` |
| `ramp.js` | 1→10 VUs ramp — both adapters per iteration | `/postgres/pgsql`, `/postgres/pdo` |
| `stress.js` | 25 VUs, 30s | `/postgres/pgsql`, `/postgres/pdo` |
| `soak.js` | 10 VUs, 5m | `/postgres/pgsql`, `/postgres/pdo` |
| `ping-baseline.js` | 10 VUs, 30s — server overhead only | `/ping` |

---

## Run Commands

### Smoke test
```bash
k6 run test/k6/baseline.js
```

### Ramp tests (single adapter — safe, recommended)
```bash
k6 run test/k6/ramp-pgsql.js
k6 run test/k6/ramp-pdo.js
```

### Save results to JSON
```bash
k6 run --out json=docs/load-testing/results/2026-04-21/ramp-pgsql.json test/k6/ramp-pgsql.js
k6 run --out json=docs/load-testing/results/2026-04-21/ramp-pdo.json   test/k6/ramp-pdo.js
```

### Ping baseline (no DB)
```bash
k6 run test/k6/ping-baseline.js
```

---

## Notes

- **`mode=concurrent`** (TaskGroup) and **`mode=stress`** trigger `zend_mm_heap corrupted`
  in the current TrueAsync extension build. All ramp scripts use `mode=baseline` (sequential)
  until the extension is patched. See `docs/load-testing/results/2026-04-21/taskgroup.md`.
- **Crash boundary:** ~80 simultaneous coroutines causes SIGABRT. Safe ceiling: 10 VUs × 4
  sequential queries. Do not run `ramp.js` (both adapters per iteration) above 5 VUs.
- Results directory: `docs/load-testing/results/YYYY-MM-DD/`
