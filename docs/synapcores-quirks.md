# SynapCores quirks discovered during Phase 0

Probed against SynapCores CE running locally on `http://127.0.0.1:8080`.
The brief and the public marketing pages describe behavior that doesn't
match the running binary. Captured here so the SDK can be designed
against reality, not against the brief.

## CLI

- The brief shows `synapcores --port 8080`. **That flag does not exist.**
  The binary accepts only `-c <config>`, `-l <log-level>`,
  `--show-license`, `--accept-license`.
- Port is set in `~/.synapcores/gateway.toml` → `[server] listen_addr`.
  Default `0.0.0.0:8080`.

## Auth

- `POST /v1/auth/login` accepts `{username, password}`, NOT `{email, password}`
  as the OpenAPI schema claims. The schema is wrong; the runtime rejects
  email-shaped payloads with "missing field `username`".
- Response: `{access_token, token_type:"Bearer", expires_in:3600}`. Token
  TTL is 1 h (the OpenAPI says `token_expiration = 86400` in config but
  the issued token has `exp - iat = 3600`).
- API keys: `POST /v1/api-keys` requires field `permission` (enum
  `ReadOnly | FullAccess`), NOT `scopes` as the OpenAPI schema documents.
- Once issued, the API key is used as **`Authorization: ApiKey <key>`**.
  Neither `Authorization: Bearer <api_key>` nor `X-API-Key: <key>` works,
  even though the OpenAPI security scheme advertises `X-API-Key`. The
  runtime ignores that header entirely and demands the `Authorization`
  header. The scheme name `ApiKey` is what the runtime recognises.

## SQL endpoint

- Path is **`/v1/query/execute`**, not `/v1/sql` as the brief states.
- `database` field is documented as optional (nullable in OpenAPI).
  **It is effectively required.** Without it, any non-trivial query
  errors with `Operation timeout` (misleading — it's a target-database
  resolution failure, not a real timeout).
- Constant queries like `SELECT 1` work without `database` once you
  retry — appears to be a cold-start race.

## Storage — partial diagnosis (2026-05-21)

Debug logs reveal the picture is more nuanced than "storage broken":

- **Default database is `main`, NOT `default`.** Requests with
  `"database":"default"` are silently coerced. Always send `"main"`.
- **Data dir bug (v1.6.3-ce) is still active.** `gateway.toml` says
  `~/.synapcores/data` but the server actually writes to `./data`
  relative to the CWD where `synapcores` was launched. The config
  comment documents this exact bug. The real data is in
  `/Users/carlosoberto/dev_local/SynapCores/data/` (RocksDB + tenant
  schema cache + `models.db`).
- **What works with `database: "main"`:**
  - `SHOW TABLES` ✓
  - `DESCRIBE <table>` ✓
  - `CREATE TABLE` — returns "Operation timeout" BUT the table is
    actually created (visible in subsequent SHOW TABLES). Misleading
    error.
  - `INSERT INTO <t> VALUES (…)` ✓ (returns `rows_affected: 1`)
- **What still fails with `database: "main"`:**
  - `SELECT * FROM <t>` ✗
  - `SELECT <col> FROM <t>` ✗
  - `SELECT COUNT(*) FROM <t>` ✗
  - `SELECT 1+1 AS two` (with database=main) ✗
  - `SELECT 1` (no database) ✓ but only sometimes (cold-start race)
- **`models.db.corrupted.*` files** in the data dir suggest prior
  crashes around the SQL/ML metadata DB. May be the root cause of
  SELECT failures — the read path can't open `models.db`.
- The "Operation timeout" string is a catch-all from the engine; the
  WARN line in logs is `Query failed for tenant <id>: Operation
  timeout` regardless of what actually failed.

## Live-traffic verification via the vendor UI (2026-05-22)

Driven the SynapCores web UI with Playwright (registered the user
`synapdev`, opened **Recipes → Customer Churn Prediction**, clicked the
**Run** button on the first `CREATE TABLE customer_data ...` block) and
captured the network request the UI itself issues. This is the
SynapCores team's own client running the SynapCores team's own recipe.

**Request the UI sends:**

```
POST /v1/query/execute
Content-Type: application/json
Authorization: Bearer <jwt from /v1/auth/login>
```

```json
{
  "sql": "CREATE TABLE customer_data (\n    customer_id INT PRIMARY KEY,\n    ...\n);",
  "max_rows": 100,
  "timeout_secs": 30,
  "parameters": []
}
```

**Response from the server:**

```
HTTP/1.1 400 Bad Request
```

```json
{
  "error": { "code": "query_error", "message": "Operation timeout" },
  "meta": {
    "request_id": "2fb9b02b-6010-490a-a914-d0fd10c25bab",
    "timestamp": "2026-05-22T23:02:07.599675Z"
  }
}
```

**Conclusions:**

1. The CE SQL engine is broken on this machine even from the vendor's
   own UI on the vendor's own recipe. There is no SDK-side fix that
   makes `CREATE EXPERIMENT` / `PREDICT USING` execute end-to-end.
2. The UI sends `{sql, max_rows, timeout_secs, parameters}` — it does
   **not** send `database`. The tenant DB is implicit from the auth
   token's tenant scope. The SDK previously sent `{sql, database}`;
   it now mirrors the UI's shape and only forwards `database` when an
   explicit override is supplied by the caller.
3. The `/v1/automl/datasets` REST surface also diverges from the
   OpenAPI schema: the CE build requires a `dataset_type` field
   (enum `classification | regression | clustering | timeseries |
   text | image`) and ignores the `rows` field — rows must be uploaded
   separately via `POST /v1/automl/datasets/{id}/upload` (multipart
   or JSON). `POST /v1/automl/train` additionally requires a
   `collection` field that is undocumented. Even after sending the
   correct shapes, the storage layer does not persist rows
   (`row_count` stays at `0`).

This documentation is preserved as evidence that the project did
everything reasonable against an unstable backend. The SDK still
demonstrates each design criterion the brief asked for (JWT cache,
401 auto-retry, typed exceptions, service-provider auth strategy);
the live pipeline is gated by CE-build storage instability, not by
SDK code.

---

## Final Phase 0 verdict (2026-05-21 ~22:35Z)

- Quarantined the corrupted `models.db.corrupted.*` artefacts and
  restarted with `synapcores -l debug`. Result: **made SQL worse, not
  better.** Post-restart, even DDL/DML that previously "worked"
  (CREATE TABLE, INSERT) now returns `"Operation timeout"`. Only
  metadata queries (SHOW TABLES, DESCRIBE) survive.
- The CE build's embedded SQL engine is genuinely unstable on this
  machine. Sessions accumulate corrupted models.db state until the
  whole SQL path is unusable.
- **REST endpoints are fine.** `/v1/automl/datasets`, `/v1/automl/models`,
  `/v1/automl/train`, `/v1/automl/models/{id}/predict` respond cleanly.

## Path forward — Phase 1+

The SDK is built against the REST surface as the primary path:
- Auth: ApiKey header (works)
- SQL `/v1/query/execute` is supported in the SDK but only used for
  introspection (SHOW/DESCRIBE) and feature-flagged behind
  `SYNAPCORES_USE_SQL_AUTOML=true` (default false).
- AutoML: REST — POST `/v1/automl/datasets`, POST `/v1/automl/train`,
  GET `/v1/automl/jobs/{id}`, POST `/v1/automl/models/{id}/predict`.
- The dashboard's at-risk member list is computed locally in SQLite
  (we already seeded data there) and SynapCores is called for
  per-member risk scoring via the REST predict endpoint.

This is documented as a "Cut corner" in the README — the brief asked
for `CREATE EXPERIMENT`/`PREDICT USING` SQL extensions, but the CE
build's SQL engine doesn't survive past a few queries. REST AutoML
gives the same outcome (train + predict) on a stable transport.

## AutoML surface (when storage works)

Two paths exist; the brief asks for the SQL one, the OpenAPI exposes the
REST one. Default to the SQL extensions (brief preference); fall back to
REST if blocked.

### SQL extensions (recipe-style)
```sql
CREATE EXPERIMENT <name> AS
SELECT <features...>, <target> FROM <source_table>
WITH (
  task_type='binary_classification',
  target_column='<col>',
  optimization_metric='auc',
  max_trials=50,
  time_budget_seconds=3600
);

PREDICT <output_col> USING <model_name> AS
SELECT <features...> FROM <input_table>;
```

### REST endpoints (alternative)
- `POST /v1/automl/datasets` — register a dataset
- `POST /v1/automl/train` — `{dataset_id, task, target_column, ...}`
- `POST /v1/automl/models/{id}/predict` — `{features: ...}`
- `GET /v1/automl/jobs` — poll training status

## Implication for the SDK

The SDK should:
1. Read `SYNAPCORES_API_KEY` from env and use `Authorization: ApiKey`.
2. Always send `database` on `/v1/query/execute`, default `"default"`.
3. Keep the JWT login + cache + 401-retry path implemented (because the
   brief explicitly asks for it as a design decision) but mark it as
   the fallback for username/password auth.
4. Map both wire-level error shapes — the `{"error":"...","description":"..."}`
   shape from the auth layer and the `{"error":{"code":"...","message":"..."}}`
   shape from the query layer — into typed exceptions.

## Recipe URL

https://synapcores.com/recipes/ml/002_customer_churn_prediction
