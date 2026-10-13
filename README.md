# PHP Mini Search

**[🧪 PHP Systems Lab](https://github.com/Researcher86/php-systems-lab)** · Level 8 of 9 (planned) · ← [`php-mini-database`](https://github.com/Researcher86/php-mini-database) · [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) →

> An educational full-text search service written in PHP.

`php-mini-search` is a small educational project for exploring how a search engine works internally.

The goal is not to replace:

* Elasticsearch;
* OpenSearch;
* Meilisearch;
* Typesense.

The goal is to build a simplified search service that exposes the fundamental ideas behind:

* text analysis;
* Unicode normalization;
* stemming;
* inverted indexes;
* posting-list intersection;
* relevance ranking with BM25;
* keeping a derived index in sync with its source of truth;
* out-of-order writes and tombstones;
* full reindexing;
* an append-only log and crash recovery;
* an event-driven HTTP server.

## 30-second demo

Requires Docker. Nothing is installed on your machine.

```bash
make build install   # build the image and install dependencies
make run-server      # replay data/search.log, listen on 127.0.0.1:8080
```

In a second terminal, `make shell`, then:

```bash
T="Authorization: Bearer demo-token-demo-token-demo-token-0"; J="Content-Type: application/json"

curl -H "$T" -X POST localhost:8080/reindex
# {"generation":1,"cancelled_generation":null}

curl -H "$T" -H "$J" -X PUT localhost:8080/documents/SKU-1 \
     -d '{"source_version":1,"title":"Кабель USB-C","body":"Быстрая зарядка","generation":1}'
curl -H "$T" -X POST localhost:8080/reindex/1/complete

curl -H "$T" -H "$J" -X POST localhost:8080/search -d '{"query":"кабели usb"}'
# {"results":[{"id":"SKU-1","score":1.150728,"source_version":1}],"total":1,"limit":10,"offset":0}

curl -H "$T" localhost:8080/documents/SKU-1
# what the index holds: {"fields":{"title":{"length":3,"terms":{"кабел":1,"usb":1,"c":1}}, ...}}
```

`кабели` finds `Кабель` because both stem to `кабел`. Ctrl-C stops the
server gracefully: requests in flight finish first, for at most 10 seconds,
and a second Ctrl-C forces it down. A restart replays the log, and
`bin/mini-search check` and `compact` work on it while the server is stopped.

## Documentation

| Document | What it covers |
| -------- | -------------- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | the components, one write and one search traced end to end |
| [docs/API.md](docs/API.md) | the HTTP contract with `php-systems-platform` |
| [docs/DECISIONS.md](docs/DECISIONS.md) | what was chosen, what was rejected, and why |
| [docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md) | what breaks, what is guaranteed, and what is not |
| [docs/PHASES.md](docs/PHASES.md) | the build plan, with a definition of done per phase |

# Project Status

**Phases 0–8 are done: the project skeleton, the postings benchmark (run
during design), text analysis with hand-written Snowball stemmers, the
inverted index with BM25 checked against a naive oracle, the durable log
with `Corpus`, replay and offline compaction, the event-driven HTTP server,
the API with its runtime, and the failure model proven on the real process
with `kill -9` and a full disk, and a measured baseline: on 10,000 Wikipedia
articles, 10,575 searches/s at p99 6 ms, a 6.7 s startup, 239 MB peak RSS.** The documents above describe the planned
service, and [PHASES.md](docs/PHASES.md) tracks what is built. The one known
unknown is what `fsync()` guarantees through Docker Desktop on macOS. It is
listed in [FAILURE-MODEL.md](docs/FAILURE-MODEL.md#open).

# Why?

Every other project in the lab finds data by key: a cache key, a primary
key, a B-tree range. Search finds data by meaning, approximately: `кабель`
has to find `Кабели USB-C`, and the better match has to come first.

That raises questions the rest of the lab never asks:

* what a word is, once Unicode, case and morphology are involved;
* how to find the documents that contain all of the words without reading every document;
* what "more relevant" means, as a number;
* how a copy of someone else's data stays correct when updates arrive late, twice, or out of order.

# Core Idea

```text
            php-mini-database (source of truth)
                      │
           outbox → php-job-queue → worker
                      │  PUT / DELETE + source version
                      ▼
   ┌───────────── php-mini-search ─────────────┐
   │  HTTP  →  Corpus  →  append-only log      │
   │                  └→  analysis → inverted  │
   │                                  index    │
   └───────────────────────────────────────────┘
                      ▲
                      │  POST /search → ids ranked by BM25
               platform search
```

The index is a projection: it can always be thrown away and rebuilt from the
platform. The log exists so that a restart does not require a rebuild.

# What This Project Is Not

* not a database: the platform owns the data;
* not distributed: one process, one machine;
* not a query language: AND over terms, nothing more;
* not production-ready: there is no TLS, no OR, no phrase search and no typo tolerance.

# PHP Systems Lab

This project is part of [**PHP Systems Lab**](https://github.com/Researcher86/php-systems-lab) — a collection of small
educational PHP projects that rebuild the mechanisms behind backend
infrastructure in order to understand them. The recommended order:

| Level | Project | Focus |
| ----- | ------- | ----- |
| 1 | 🧠 [`php-memory-lab`](https://github.com/Researcher86/php-memory-lab) | memory, RSS, fork, copy-on-write, `mmap`, shared memory, FFI |
| 2 | ⚡ [`php-concurrency`](https://github.com/Researcher86/php-concurrency) | processes, IPC, concurrency patterns, event loops, Fibers (course in Russian) |
| 3 | ⚙️ [`php-worker-pool`](https://github.com/Researcher86/php-worker-pool) | persistent master/worker pool, supervision, graceful shutdown |
| 4 | 📬 [`php-job-queue`](https://github.com/Researcher86/php-job-queue) | reliable background jobs: delivery leases, ACK, retries, DLQ |
| 5 | 💾 [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) | event-driven in-memory server: RESP, pipelining, TTL, Pub/Sub |
| 6 | 🌐 [`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server) | event-driven HTTP server: parsing, routing, middleware, keep-alive |
| 7 | 🗄️ [`php-mini-database`](https://github.com/Researcher86/php-mini-database) | relational engine: pages, B-trees, SQL, transactions, WAL, recovery |
| **8** | 🔎 **`php-mini-search`** (this project) | **full-text search: analysis, stemming, inverted index, BM25, reindexing** |
| 9 | 🏗️ [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) | integration of the components into one backend platform |

These are teaching projects, not libraries: a mechanism travels between them
by being read in one and reimplemented in the next. Levels 1–8 do not depend
on each other as packages. Only [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) requires the
six components (worker pool, job queue, cache, HTTP server, database, search)
through Composer and runs them together as one system.

Until `php-mini-search` v1.0.0 is released, this table describes the planned
lab: the other repositories still list eight levels.

## How this project relates

**[`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server)** — the same event-driven HTTP foundation:
one `stream_select()` loop, incremental parsing, keep-alive and pipelining.
This project reimplements those ideas (not that code) in a deliberately
narrower form: `Content-Length` framing only, and no middleware.

**[`php-mini-database`](https://github.com/Researcher86/php-mini-database)** — the source of truth for everything this service
indexes, and the place where WAL and crash recovery are taught in depth. The
log here is the small version of the same idea: append, fsync, replay.

**[`php-job-queue`](https://github.com/Researcher86/php-job-queue)** — carries index updates from the platform. Its
at-least-once delivery is why every write here carries a source version.

**[`php-systems-platform`](https://github.com/Researcher86/php-systems-platform)** — the next level and the only client. Like
the other components, this project is a Composer dependency of the platform,
which starts `vendor/bin/mini-search serve` as a child process. Unlike them,
the platform talks to it only over HTTP and never imports its classes: search
is the one component whose boundary is a network protocol the platform did
not have before.

## License

MIT.
