# AllCalls Loyalty Churn Predictor — Laravel + SynapCores AIDB

Take-home submission for AllCalls.io. A Laravel 12 application that ingests synthetic loyalty-program
data, trains a binary-classification model on SynapCores AIDB via its REST AutoML surface, and serves
predictions through a `/dashboard` Blade page and a `/api/members/at-risk` JSON endpoint. The core of
the project is a custom PHP SDK that handles authentication, JWT caching, typed exceptions, and 401
auto-retry — there is no official PHP client for SynapCores.

---

## Requirements

- PHP 8.2+ with the `pdo_sqlite` extension
- Composer 2.x
- SQLite (no server needed — the app writes to `database/database.sqlite`)
- SynapCores Community Edition running locally on `http://127.0.0.1:8080`
- An API key issued by `POST /v1/api-keys` on that SynapCores instance

### Starting SynapCores

Download the Community Edition binary from [synapcores.com](https://synapcores.com) and accept
the license on first run:

```bash
synapcores --accept-license     # one-time
synapcores                      # start the server (foreground)
# or:
synapcores -l debug             # start with debug logs
```

The binary reads `~/.synapcores/gateway.toml`. Port lives under `[server] listen_addr` and
defaults to `0.0.0.0:8080`. The brief's `synapcores --port 8080` flag does **not** exist on the
CE build — the only flags are `-c <config>`, `-l <log-level>`, `--show-license`,
`--accept-license`. See [docs/synapcores-quirks.md](docs/synapcores-quirks.md) for the full set
of discrepancies between docs and runtime.

Once the server is up, create your user and API key entirely through the web UI:

1. **Open `http://127.0.0.1:8080`** in a browser.
2. **Register the first user** with the on-screen registration form. The CE build does not ship
   with a default admin user; the UI is the only way to bootstrap an account.
3. **Log in** with those credentials.
4. **Go to Settings → API Keys**, click *Create API key*, give it any name, and choose
   permission **FullAccess**. Copy the `aidb_…` value the UI shows you — it is displayed once.

Paste that value into `.env` as `SYNAPCORES_API_KEY`. The SDK sends it as
`Authorization: ApiKey <key>` — neither `Bearer` nor `X-API-Key` works on this build.

> The REST endpoints `POST /v1/auth/login` and `POST /v1/api-keys` exist and the SDK uses them
> (the JWT path is fully implemented), but bootstrapping the *first* account via curl is not
> possible on the CE build — there is no unauthenticated user-registration endpoint exposed.

---

## Quickstart

```bash
git clone <repo-url> && cd allcalls-churn-synapcores
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

Edit `.env` and set your SynapCores API key:

```bash
SYNAPCORES_API_KEY=your_key_here
```

Then run the three pipeline commands in order:

```bash
php artisan synapcores:push-dataset   # upload loyalty_members to SynapCores
php artisan synapcores:train          # kick off AutoML training, poll until done
php artisan synapcores:predict        # score every member, persist to risk_predictions
php artisan serve                     # open http://127.0.0.1:8000/dashboard
```

The JSON endpoint is at `GET http://127.0.0.1:8000/api/members/at-risk`.

> **SynapCores must be running before you execute any `synapcores:*` command.** Obtain an API key
> with `POST /v1/api-keys` (field: `permission`, value: `FullAccess`). See [notes on CE quirks]
> (docs/synapcores-quirks.md) if you hit unexpected errors.

---

## Architecture at a glance

| Layer | What lives here |
|---|---|
| SQLite (`loyalty_members`) | 7 500 seeded members with a learnable churn signal |
| SQLite (`risk_predictions`) | Persisted output from `synapcores:predict`; the dashboard reads here |
| `app/Services/SynapCores/` | The SDK — the only component that talks to SynapCores over HTTP |
| `app/Services/ChurnRiskService.php` | Joins the two SQLite tables; no SynapCores call at request time |
| Controllers | Thin wrappers — delegate to `ChurnRiskService`, return a view or JSON |
| SynapCores REST API | `/v1/automl/datasets`, `/v1/automl/train`, `/v1/automl/jobs/{id}`, `/v1/automl/models/{id}/predict` |

The dashboard never calls SynapCores directly. At request time it reads the local `risk_predictions`
cache, which gives deterministic latency regardless of what the model server is doing.

---

## Design decisions

- **Predictions persisted to SQLite, not computed on demand.** Running inference on every dashboard
  load would couple UI latency to the model server. The `synapcores:predict` command writes results
  to `risk_predictions` so the dashboard is a cheap local read.

- **JWT caching + 401 auto-retry implemented even though ApiKey is the stable path.** The brief
  explicitly asked for it as a design criterion. `JwtAuth` caches the token with a 60-second safety
  margin below the actual `expires_in` value, and a single 401 on any request causes one
  invalidate + re-login + retry. A second consecutive 401 throws `AuthException` immediately — there
  is intentionally no loop.

- **Auth strategy selected by config, not by a code branch.** `SynapCoresServiceProvider` inspects
  `SYNAPCORES_API_KEY` first, then `SYNAPCORES_JWT_USERNAME`/`SYNAPCORES_JWT_PASSWORD`. Swapping
  strategies is a `.env` change; no code changes required.

- **REST AutoML instead of `CREATE EXPERIMENT` SQL.** The brief asked for the SQL extensions, and
  the SDK shape supports them behind `SYNAPCORES_USE_SQL_AUTOML=true`. That flag defaults to `false`
  because the CE build's embedded SQL engine is unstable on this machine — SELECT queries return
  `Operation timeout` regardless of syntax correctness (see [synapcores-quirks.md]
  (docs/synapcores-quirks.md) for the full diagnosis). The REST `/v1/automl/*` endpoints respond
  cleanly and produce the same trained model.

- **Typed exceptions per error shape, not a generic catch-all.** SynapCores uses two distinct wire
  formats: a flat `{"error":"...","description":"..."}` shape from the auth layer and a nested
  `{"error":{"code":"...","message":"..."},"meta":{...}}` shape from the query layer. Callers catch
  `AuthException` vs `QueryException` and can respond differently — rather than parsing a raw
  `Exception` message string.

---

## What I would do next

- Move `synapcores:train` polling into a queued job so the command returns immediately and training
  runs in the background; right now it blocks the terminal for 5+ minutes.
- Add an endpoint to surface per-member prediction history — `risk_predictions` already versioned by
  `predicted_at` and `model_id`, so the schema is ready.
- Add a Vite + Alpine sprinkle to the dashboard so tier filtering and manual re-predict work without
  a full page reload.
- Instrument every SDK call with a log counter (success, retry, exception class) so production
  operators can spot a degraded SynapCores instance before users do.
- Stand up a GitHub Actions workflow that runs `php artisan test --filter=SynapCoresClientTest` on
  every push; the 6 SDK tests use `Http::fake()` and need no live server.

---

## Cut corners

- **No prediction versioning on the dashboard.** `risk_predictions` keeps one row per
  `(loyalty_member_id, model_id)`; running `synapcores:predict` a second time with a new model
  writes new rows but old ones are never archived. A production system would namespace predictions
  by model version and surface the delta.

- **No auth on the API endpoints.** `GET /api/members/at-risk` is wide open. The brief stated this
  was out of scope, but a production deployment would gate it behind Sanctum token auth at minimum.

- **`RetentionOfferController` does not persist or deduplicate offers.** It derives a tier-based
  offer payload and returns it; nothing is written to the database. A real system would persist the
  offer, check whether one was already sent recently, and enqueue an email job.

- **The `SYNAPCORES_USE_SQL_AUTOML=true` path is implemented in the SDK shape but not exercised
  end-to-end.** The CE SQL engine on this build cannot complete a SELECT query reliably (see
  [synapcores-quirks.md](docs/synapcores-quirks.md)), so this path was quarantined rather than
  shipped broken.

- **Seeded data is synthetic.** The 7 500 members are generated by `LoyaltyMemberSeeder` with a
  deterministic churn signal (low visits + low spend + long absence = higher churn probability, plus
  noise). No real loyalty CSV was used.

---

## Troubleshooting

**`SynapCoresException: Operation timeout` on `synapcores:push-dataset`**
The CE SQL engine returns this string for unrelated failures including cold-start races and corrupted
metadata. The push-dataset command uses the REST `/v1/automl/datasets` endpoint, not SQL, so if you
see this error check that SynapCores is fully started and retry once. The SQL path (introspection
queries) may still surface the message; the dashboard and all three pipeline commands do not depend on
it. Full diagnosis is in [docs/synapcores-quirks.md](docs/synapcores-quirks.md).

**`401` from every request**
Check `SYNAPCORES_API_KEY` in `.env`. If you are using JWT credentials instead, verify
`SYNAPCORES_JWT_USERNAME` and `SYNAPCORES_JWT_PASSWORD` match what is stored in SynapCores. The CE
runtime requires field name `username` on `POST /v1/auth/login` — not `email` as the OpenAPI schema
states.

**`RuntimeException: No SynapCores credentials configured`** (or similar from `AuthException`)
The service provider found neither `SYNAPCORES_API_KEY` nor both JWT variables set. Set at least one
pair before running any `synapcores:*` command.

---

## Tests

Run `php artisan test --filter=SynapCoresClientTest` for the 6 SDK tests. All use `Http::fake()` and
require no live SynapCores instance.

---

## License

MIT — see [LICENSE](LICENSE).
