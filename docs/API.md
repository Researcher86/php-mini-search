# API

The HTTP contract between `php-systems-platform` and `php-mini-search`. The
platform talks to the service through this API only: it never reads the
service's files and does not depend on how search is implemented.

Every request and response body is `application/json`. Every endpoint except
`/health/*` requires `Authorization: Bearer <token>`.

---

# Endpoints

| Method and path | Purpose |
|---|---|
| `PUT /documents/{id}` | index a document, or replace it |
| `DELETE /documents/{id}?source_version=N` | delete a document, leaving a tombstone |
| `GET /documents/{id}` | a diagnostic view of what the index holds for an id |
| `POST /search` | ranked search |
| `POST /reindex` | open a new generation |
| `POST /reindex/{generation}/complete` | close a generation |
| `GET /health/live` | the process is alive (no token) |
| `GET /health/ready` | the service can answer searches (no token) |
| `GET /stats` | metrics |

---

# Documents

## `PUT /documents/{id}`

```json
{"source_version": 17, "title": "Кабель USB-C", "body": "…", "generation": 5}
```

| Field | Rule |
|---|---|
| `{id}` | 1–128 bytes, `[A-Za-z0-9._-]` |
| `source_version` | positive integer, required |
| `title` | string, ≤ 1 KB, required (may be empty) |
| `body` | string, ≤ 64 KB, required (may be empty) |
| `generation` | positive integer, optional; sent only by a full reindex |

```json
{"id": "SKU-42", "applied": true, "source_version": 17, "generation": 5}
```

The write is **stale** when `source_version` is not greater than the stored
version, or than the version of a tombstone. It answers `200` with
`"applied": false`, and `source_version` then reports the stored version. A
stale write is not an error, and the job must not be retried.

## `DELETE /documents/{id}?source_version=18`

The version travels in the query string. A body on `DELETE` has no defined
meaning (RFC 9110), and some clients drop it.

```json
{"id": "SKU-42", "applied": true, "source_version": 18}
```

Deleting an id the service has never seen also answers `applied: true` and
leaves a tombstone. Its `PUT` may still be waiting in the queue, and without
the tombstone that late `PUT` would create a product that no longer exists.

## Writes during a reindex

While a generation is open, **every applied write belongs to it**: a `PUT`
with a newer `source_version` is fresh truth from the source, whether it
comes from the reindex or from an ordinary indexing job. A stale `PUT` that
carries an explicit `generation` also marks the document: the source has
confirmed that it exists. A `DELETE` leaves a tombstone, and `complete` does
not touch tombstones. Without this rule, a product created during a reindex,
after the reindex had passed its key, would be removed by `complete`.

## `GET /documents/{id}`

A diagnostic view, used to debug drift between the platform and the index.

```json
{"id": "SKU-42", "state": "live", "source_version": 17, "generation": 6,
 "fields": {"title": {"length": 2, "terms": {"кабел": 1, "usb": 1}},
            "body":  {"length": 87, "terms": {"…": 1}}}}
```

A tombstone answers `{"id": "SKU-7", "state": "deleted", "source_version": 18}`.
An id the service has never seen answers `404 document_not_found`.

---

# Search

## `POST /search`

```json
{"query": "кабель usb", "limit": 10, "offset": 0}
```

| Field | Rule |
|---|---|
| `query` | string, required, ≤ 64 KB; 1–32 terms after analysis |
| `limit` | 1–100, default 10 |
| `offset` | ≥ 0, default 0; `offset + limit ≤ 1000` |

```json
{"results": [{"id": "SKU-42", "score": 7.31, "source_version": 17}],
 "total": 1, "limit": 10, "offset": 0}
```

* a document matches only if it contains every term (AND);
* results are ordered by `score` descending, then `id` ascending;
* `total` is the exact number of matches;
* `score` is a relative number with no stable scale;
* pages are not a snapshot: a write between two pages can shift results.

---

# Reindex

## `POST /reindex`

```json
{"generation": 6, "cancelled_generation": 5}
```

Opens a new generation. A generation that was open and never completed is
cancelled; `cancelled_generation` is `null` if there was none.

## `POST /reindex/{generation}/complete`

```json
{"generation": 6, "removed": 12}
```

Removes every live document the generation did not mark, leaving a
tombstone for each, then leaves `reindex_required` if the service was in it.
Tombstones are never cleared: a stale job can still arrive after the reindex
(docs/DECISIONS.md). A
generation that is not open (already completed, cancelled, or unknown)
answers `409 generation_not_open`.

---

# Health and metrics

```text
GET /health/live   → 200 {"status": "ok"}
GET /health/ready  → 200 {"status": "ready"}
                   → 503 {"status": "reindex_required", "reason": "first_start" | "log_corrupted"}, Retry-After: 10
```

`GET /stats`:

```json
{"documents": 9812, "tombstones": 41, "terms": 302117,
 "open_generation": null, "last_completed_generation": 6,
 "log": {"bytes": 52428800, "garbage_ratio": 0.18},
 "connections": 3,
 "requests": {"POST /search 200": 1520, "PUT /documents/{id} 200": 88},
 "latency_ms": {"p50": 1.2, "p95": 4.8, "p99": 9.1},
 "rss_bytes": 385875968, "uptime_s": 86400}
```

---

# Errors

```json
{"error": {"code": "validation_failed", "message": "…", "details": {"field": "title", "rule": "max_bytes"}},
 "request_id": "…"}
```

Clients branch on `code`, never on `message`. `request_id` also travels in
the `X-Request-Id` header: it is taken from the client or generated.

| Status | `code` | When |
|---|---|---|
| `400` | `malformed_request` | broken HTTP or framing; the connection is closed |
| `400` | `invalid_json` | the body is not valid JSON |
| `401` | `unauthorized` | missing or wrong token |
| `404` | `route_not_found` | unknown path |
| `404` | `document_not_found` | diagnostic `GET` of an id never seen |
| `405` | `method_not_allowed` | known path, wrong method (with `Allow`) |
| `409` | `generation_not_open` | `complete` for a generation that is not open |
| `413` | `payload_too_large` | body over 256 KB |
| `415` | `unsupported_media_type` | `Content-Type` is not `application/json` |
| `422` | `validation_failed` | a field breaks its rule; `details` names the `field` and the `rule` |
| `422` | `empty_query` | the query has no terms after analysis |
| `422` | `too_many_terms` | more than 32 terms |
| `422` | `offset_too_deep` | `offset + limit > 1000` |
| `431` | `headers_too_large` | request line and headers over 8 KB |
| `501` | `unsupported_transfer_encoding` | any `Transfer-Encoding` |
| `503` | `reindex_required` | search while the index was never filled or was lost (with `Retry-After`) |
| `503` | `storage_failure` | the log write or fsync failed; the process stops next |
| `500` | `internal_error` | a bug |

---

# Retries

* searches and diagnostic reads can be retried freely;
* `PUT` and `DELETE` can be retried freely: the source version makes them idempotent, and a duplicate answers `applied: false`;
* `storage_failure` is retried by the queue after the service restarts;
* `complete` for a generation that is already completed answers `409`, and that is final.

---

# What the platform has to provide

What `php-systems-platform` does to use this service, as built in its PLAN
Step 41:

* requires `researcher86/php-mini-search` through Composer and runs `vendor/bin/mini-search serve` as an owned child process (start-or-adopt), passing `MINI_SEARCH_HOST`, `PORT`, `DATA_DIR` and `TOKEN`;
* uses only HTTP and the binary, never `PhpMiniSearch\` classes: `src/Search/SearchClient.php` is plain curl, with a 1-second connect timeout and a 5-second request timeout;
* `products.version`, incremented by every write, sent as `source_version`;
* `products.description` (`VARCHAR(4000)`; the mini database has no `TEXT`), sent as `body`;
* a **soft** delete (`products.deleted`): the row and its version outlive the product, so a sku deleted and created again continues above its own tombstone. With a physical delete, the re-created product would start at version 1, below the tombstone, and never be indexed;
* after each write, a `product.index` job on `php-job-queue`, run by the worker pool, which reads the **current** row and sends `PUT` or `DELETE`. Row first, then the job: the same dual write the platform uses for orders. An outbox was rejected, because the mini database allows one transaction per server. The gap is healed by the next change to the product, or by a full reindex;
* `product.reindex`, published by `serve` when `/health/ready` reports `reindex_required`, and `bin/platform.php search:reindex` for the same thing by hand;
* `GET /products/search?q=`: skus and order from this service, rows from the platform's own table.
