# PHP Mini Search — How It Will Be Built

The service is built in phases. Each phase ends with something that works
and is tested, and each one has a definition of done that can be measured.
Nothing is built yet: this file is the plan, and it becomes the record as
phases are completed.

The search engine is built bottom-up (analysis, index, storage) before the
network layer, because it can be tested without a server. The memory cost
of the index was measured before anything else (Phase 1).

# Progress

- [x] Phase 0 — Project Setup
- [x] Phase 1 — Postings Prototype (during design)
- [x] Phase 2 — Text Analysis
- [x] Phase 3 — Inverted Index and Ranking
- [x] Phase 4 — Log and Corpus
- [ ] Phase 5 — HTTP Server
- [ ] Phase 6 — API and Runtime
- [ ] Phase 7 — Reliability End to End
- [ ] Phase 8 — Baseline Measurements
- [ ] Phase 9 — Platform Integration
- [ ] Phase 10 — Documentation and Release

---

# Phase 0 — Project Setup  ✅

## Goal

Create the same foundation as the other projects in the lab.

## Tasks

* Docker (`php:8.5-cli`, `pcntl`, `posix`, `intl`, Xdebug on trigger), docker-compose, a Makefile with the lab's standard targets;
* Composer `researcher86/php-mini-search`, namespace `PhpMiniSearch\`, no runtime dependencies;
* PHPUnit, PHPStan, PHP-CS-Fixer, GitHub Actions running `make build install test analyse format-check`;
* a startup check that refuses to run without `mbstring`, `intl` and `pcntl`.

## Definition of Done

`make install test analyse format-check` passes locally and in CI.

## Result

```text
make build → make install → make test → make analyse → make format-check
```

All of them run inside the same `php:8.5-cli` image, locally and in CI.
PHPStan runs at `level: max` over `src`, `bin` and `tests`, including the
extensionless `bin/mini-search`, which has to be listed by name. `bin/mini-search` refuses to start without
`intl`, `mbstring` or `pcntl`, and names the reason for each. Its commands
answer "not built yet" with the phase that builds them.

## Tests

- [tests/ProjectSetupTest.php](../tests/ProjectSetupTest.php) —
  `testProjectLoads` proves PSR-4 autoloading resolves a class out of `src/`;
  `testTheEntryPointRunsAndPrintsUsage` runs `bin/mini-search` as a real
  process, which proves `bin/bootstrap.php` finds the autoloader and the
  extension check passes in the development image.
- [tests/Runtime/RequiredExtensionsTest.php](../tests/Runtime/RequiredExtensionsTest.php) —
  a missing extension is reported with its reason, and the development image
  carries every required one.

---

# Phase 1 — Postings Prototype  ✅

## Goal

Choose how postings are represented, by measuring instead of guessing.

## Tasks

* a synthetic corpus of 10,000 and 100,000 documents (Zipf distribution, seeded);
* nested PHP arrays, a `list<int>`, and packed binary strings;
* measure peak RSS and the cost of reading a posting list and deleting a document.

## Result

Nested arrays, at 319 MB for 10,000 documents. The numbers and the reasoning
are in [DECISIONS.md](DECISIONS.md#postings-are-plain-php-arrays). The
prototype stays out of the codebase, in `.scratch/`.

---

# Phase 2 — Text Analysis  ✅

## Goal

Turn text into terms the same way for documents and for queries.

## Tasks

* NFKC, lowercasing, `ё → е`, apostrophes removed between letters, `\p{L}\p{N}` tokens;
* Snowball Russian and Snowball English (Porter2), written by hand from the `.sbl` sources;
* the Snowball vocabularies as fixtures in `tests/Fixtures/snowball/`, with the BSD `COPYING` file and the upstream commit SHA.

## Definition of Done

100% of both Snowball vocabularies pass. Tests cover NFKC, `ё`, case,
numbers, empty text, mixed scripts and apostrophes: `Levi's` matches `levis`
and `levi`, `O’Neill` matches `oneill`, `д’Артаньян` matches `дартаньян`.

## Result

`Analyzer::terms()` is the whole pipeline: `Кабели USB-C` becomes `кабел`,
`usb`, `c`. Both stemmers are ports of the `.sbl` sources at the pinned
commit, written rule for rule. Endings are matched longest first, and when
the longest ending's condition fails no shorter one is tried, because that is
how Snowball's `among` behaves. They agree with all 49,785 Russian and 42,649
English reference pairs.

Only tokens made of ASCII `a`–`z` go to the English stemmer, because Porter2
is defined for those letters only. Accented Latin (`café`), digits and mixed
tokens are kept as they are. There are no stop words: `и` and `the` are terms
like any other.

## Tests

- [tests/Analysis/Snowball/SnowballVocabularyTest.php](../tests/Analysis/Snowball/SnowballVocabularyTest.php) —
  every word of both vocabularies, one test per language, listing the first
  mismatches on failure. Over 27,000 of the English pairs change the word, so
  a stemmer that does nothing fails.
- [tests/Analysis/AnalyzerTest.php](../tests/Analysis/AnalyzerTest.php) —
  NFKC (full-width letters, ligatures, `й` and `ё` built from combining
  marks), `ё`, case, numbers, empty text, script routing, the apostrophe
  table, and invalid UTF-8.

---

# Phase 3 — Inverted Index and Ranking  ✅

## Goal

The core of the project: postings, statistics, AND intersection, BM25, top-k.

## Tasks

* per-field postings and statistics in the representation chosen in Phase 1;
* replace and delete without leaving stale postings behind;
* intersection starting from the rarest term, BM25 per field with weights, ties broken by id, a top-k heap;
* the oracle in `tests/Support/`: a naive search that re-analyses every document and computes BM25 directly from the formula;
* an invariant check.

## Definition of Done

At least 10,000 random `put`/`delete`/`search` operations, on several fixed
seeds, return the same ids, in the same order, with scores within ε of the
oracle. The invariants hold after every operation, and a failing seed is
printed.


## Result

`InvertedIndex` takes terms that are already analysed: analysis belongs to
`Corpus`, which arrives in Phase 4. `put()` adds or replaces, `delete()`
removes, `search()` returns a page of `SearchHit`s and the exact total, and
`describe()` is what the diagnostic `GET /documents/{id}` will show. On
10,000 synthetic documents it takes 291 MB (381 MB RSS). The worst search
takes 14 ms.

## Tests

- [tests/Index/InvertedIndexAgainstOracleTest.php](../tests/Index/InvertedIndexAgainstOracleTest.php) —
  5 seeds × 2,500 random puts, replacements, deletes and searches, compared
  step by step with [tests/Support/NaiveSearch.php](../tests/Support/NaiveSearch.php),
  which recomputes BM25 from scratch on every query. The invariants are
  checked after every operation. The test was checked against injected bugs:
  stale postings, an off-by-one in N, and a reversed tie-break. The first two
  were caught at once. The reversed tie-break went unnoticed until a quarter
  of the writes became exact copies of earlier documents, because random
  documents almost never tie to the last bit. The test now also requires more
  than 100 tied scores.
- [tests/Index/InvertedIndexTest.php](../tests/Index/InvertedIndexTest.php) —
  the behaviours by name: AND, terms split across fields, replacement,
  deletion, the title weight, length normalisation, the tie-break, a
  positive idf for a term in every document, paging, query order, `describe()`.

---

# Phase 4 — Log and Corpus  ✅

## Goal

Durable writes, ordered by source version, behind one deep module.

## Tasks

* the log format from [ARCHITECTURE.md](ARCHITECTURE.md#storage): `MSLOG` header, length + crc32 frames, JSON bodies; append + `fflush` + `fsync`;
* replay, truncation of a torn tail, `reindex_required` for mid-log damage and for a first start;
* `Corpus`: the write order, source versions, tombstones, generations;
* offline `compact` and `check`.

## Definition of Done

* truncating the log at every byte offset of the last record recovers every earlier record;
* a corrupted byte in the middle leads to `reindex_required`, and the damaged file is kept;
* the stale-update and resurrection scenarios are covered;
* a reindex removes the documents it did not touch;
* replaying a compacted log yields the same `Corpus` as replaying the original: documents, source versions, generations, tombstones, `reindex_required`;
* a compaction interrupted at each step recovers.

## Result

`Storage` is the bytes: `LogFormat` frames records, `LogScanner` reads and
decides between a torn tail and damage without writing anything, `Log` takes
the lock, repairs, appends with `fflush` + `fsync`, and rewrites atomically
for compaction. `Durable` holds the verified fsync recipe.

`Corpus` owns the log and the index together. Every write goes check →
analyse → append → fsync → apply. Replay and live writes share the same
`apply()`, so a restart reproduces the state by construction. `bin/mini-search
check` and `compact` work. On a log of 51 records holding one document's
history, compaction went from 43,846 bytes to 1,043.

Two decisions were taken while building this phase, and both are recorded in
[DECISIONS.md](DECISIONS.md) and [API.md](API.md):

* tombstones are never cleared. Clearing them when a reindex completed would let a retried stale job resurrect a deleted product;
* a stale `PUT` from a reindex confirms its document with a small `mark` record instead of a second copy of the text.

## Tests

- [tests/Storage/LogTest.php](../tests/Storage/LogTest.php) — a torn last
  record cut at every byte, a bad last checksum, a damaged body and a damaged
  length field in the middle, a header cut off while being created, a file
  that is not a log, scanning without side effects, atomic rewrite and an
  interrupted one.
- [tests/Corpus/CorpusTest.php](../tests/Corpus/CorpusTest.php) — the first
  start, an empty reindex, stale writes, resurrection, a delete before its
  put, a reindex removing untouched documents, a document created during a
  reindex, a `mark` surviving a restart, cancellation, a reindex surviving a
  restart, full state equality after a restart, a damaged log, query
  rejection, the lock, and a write the log rejected.
- [tests/Corpus/CompactionTest.php](../tests/Corpus/CompactionTest.php) — 8
  random histories with puts, stale puts, deletes and reindexes that are
  completed, cancelled or left open. Each compacted log replays to an
  identical state, and compacting twice changes nothing.

The tests were checked against injected bugs: compaction dropping
tombstones, compaction dropping the checkpoint, a `mark` kept only in
memory, and applying before appending. The last one was invisible until the
failing-log test was added.

---

# Phase 5 — HTTP Server

## Goal

An event-driven HTTP/1.1 server that knows nothing about search.

## Tasks

* a `stream_select()` loop, non-blocking reads and writes, an incremental parser;
* keep-alive, sequential pipelining, `Content-Length` framing, `100-continue`;
* every limit and timeout from [ARCHITECTURE.md](ARCHITECTURE.md#http-subset).

## Definition of Done

* the malformed-request corpus passes;
* every request gives the same result whether it is sent whole, byte by byte or in random chunks;
* every limit and timeout is covered by an integration test;
* pipelined requests are answered in order.

---

# Phase 6 — API and Runtime

## Goal

Connect the server to `Corpus` and make the process operable.

## Tasks

* routes, JSON validation, the token, the error contract, `X-Request-Id`;
* replay before listening, `/health/live`, `/health/ready`, `/stats`;
* signals and draining, JSON logs, config with environment overrides.

## Definition of Done

* every endpoint and error code matches [API.md](API.md);
* `/health/ready` reports `reindex_required` until a reindex completes;
* `SIGTERM` drains within the deadline.

---

# Phase 7 — Reliability End to End

## Goal

Prove the failure model on the real process.

## Definition of Done

* `kill -9` under write load loses no acknowledged write across N runs;
* a failing `fsync` stops the process, and the queue's retry recovers the write;
* every scenario in [FAILURE-MODEL.md](FAILURE-MODEL.md) has a test, or a written reason it has none.

---

# Phase 8 — Baseline Measurements

## Goal

Measure before optimising.

## Tasks

* corpora: a synthetic one (CI) and a 10,000-article slice of Russian and English Wikipedia (downloaded by a script, never committed);
* profiles: search only, write only, 95/5, search alongside 200 slow connections;
* `wrk` for throughput, and a PHP script for slow clients.

## Definition of Done

`docs/BENCHMARKS.md` records the environment and p50/p95/p99, RPS, peak RSS
and startup time. Each of the targets in
[ARCHITECTURE.md](ARCHITECTURE.md#targets) is either met, or the gap and the
decision taken about it is documented.

---

# Phase 9 — Platform Integration

## Goal

`php-systems-platform` searches products through `php-mini-search`.

## Tasks

The platform side is listed in [API.md](API.md#what-the-platform-has-to-provide):
a Composer dependency, a supervised child process, an HTTP client of its own,
and the indexing pipeline.

## Definition of Done

A product is created, found, renamed, found under its new name, deleted and
no longer found, all through the platform. A reindex started from
`reindex_required` brings search back.

---

# Phase 10 — Documentation and Release

## Tasks

Join the lab, as one consistent change across every repository:

* every README: "Level N of 8" becomes "of 9", the shared table gains `php-mini-search` at level 8, and the platform moves to level 9;
* `php-mini-database`: its "→" link points to `php-mini-search`. `php-systems-platform`: its "←" link points to `php-mini-search`;
* the hub: "five components" becomes "six", "Levels 1–7" becomes "Levels 1–8", the release statement, the Repositories table, and the Architecture, How the Projects Connect and Mental Map diagrams;
* the hub's Out of Scope: narrowed as described in [DECISIONS.md](DECISIONS.md#the-lab-changes-at-release);
* this README: drop "(planned)" and the note under the table.

## Definition of Done

* the README, API, architecture, failure model, decisions and benchmarks match the code;
* the service can be started reproducibly from the README;
* every repository in the lab lists nine levels consistently;
* the release is tagged `v1.0.0`.
