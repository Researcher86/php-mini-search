# Decisions

Why this project is the way it is: what was chosen, what was rejected, and
which earlier choices were overturned before a line of code was written.

Every decision here came out of a design interview held before
implementation. Some were reversed during that interview; they stay in the
table so the reasoning that killed them is not lost.

For the order in which the service will be built, see
[PHASES.md](PHASES.md). For how the pieces fit together, see
[ARCHITECTURE.md](ARCHITECTURE.md). For what breaks and what is not
guaranteed, see [FAILURE-MODEL.md](FAILURE-MODEL.md).

## The decisions themselves

| Decision | Status |
|---|---|
| The index is derived; `php-mini-database` owns the data | current, [why](#the-index-is-derived-from-the-platforms-data) |
| **The search service is the system of record for its documents** | **superseded** — [why](#the-index-is-derived-from-the-platforms-data). It turned `php-mini-search` into a second database, which the lab rules out |
| An append-only log is the only durable state | current, [why](#an-append-only-log-is-the-only-durable-state) |
| Every acknowledged write is fsynced first | current, [why](#every-acknowledged-write-is-fsynced-first) |
| One process, one `stream_select()` loop | current, [why](#one-process-one-loop) |
| Source versions and tombstones order the writes | current, [why](#source-versions-and-tombstones-order-the-writes) |
| **`If-Match` with a service-wide version counter** | **superseded** — [why](#source-versions-and-tombstones-order-the-writes). It guards against two editors, not against a late delivery |
| Full reindex in place, by generation | current, [why](#full-reindex-in-place-by-generation) |
| A damaged or empty log means `reindex_required` | current, [why](#a-damaged-or-empty-log-means-reindex_required) |
| **Refuse to start on a damaged log** | **superseded** — [why](#a-damaged-or-empty-log-means-reindex_required). Once the index is derived, a damaged log is a lost cache, not lost data |
| A query matches only documents containing every term | current, [why](#and-not-or) |
| Russian and English stemming from the first version | current, [why](#stemming-from-the-first-version) |
| Apostrophes inside a word are removed, not split on | current, [why](#apostrophes-are-removed-inside-a-word) |
| BM25 from the start, with per-field weights | current, [why](#bm25-from-the-start) |
| Search returns ids, not stored fields | current, [why](#search-returns-ids) |
| Request bodies are framed by `Content-Length` only | current, [why](#content-length-only) |
| Compaction is offline only | current, known simplification, [why](#offline-compaction) |
| Log records are length-prefixed frames with a JSON body | current, [why](#length-prefixed-frames-with-a-json-body) |
| Loopback by default, and a mandatory token | current, [why](#loopback-and-a-token) |
| No runtime dependencies, PHP 8.5 | current, [why](#no-runtime-dependencies) |
| Up to 10,000 documents; postings are plain PHP arrays | current, [why](#postings-are-plain-php-arrays) |
| **A batch endpoint, chunked replay inside the loop** | **removed** — [why](#postings-are-plain-php-arrays). Both existed only for a corpus of 100,000 |
| Level 8 of the lab, a Composer component run as a child process | current, [why](#level-8-a-component-run-as-a-child-process) |
| The lab's READMEs change only when v1.0.0 is released | current, [why](#the-lab-changes-at-release) |

---

# The index is derived from the platform's data

The first answer was the opposite. `php-mini-search` would be the system of
record: the platform would keep no copy, and losing the index would be
losing data. Durability and recovery would then be real requirements rather
than exercises.

Two facts reversed it. The only searchable data in `php-systems-platform`,
`products`, already lives in `php-mini-database`. And durability, WAL and
crash recovery are already what `php-mini-database` teaches. The lab's own
scope rules out "more databases". A search service that owns its documents
is a database with an inverted index attached.

So the index is a rebuildable projection. The platform's database is the
source of truth, and the platform can always reindex everything. What is left
for this project is what no other project in the lab covers: text analysis,
the inverted index and ranking.

What it costs: the platform has to grow an indexing pipeline (a version
column, an outbox, a worker, a reindex command). Search results can also lag
behind the database by the time it takes a job to run.

# An append-only log is the only durable state

Every accepted write (`put`, `delete`, the start and end of a reindex) is
appended to one checksummed log. The in-memory index is always rebuilt from
that log by replaying it at startup.

The alternative was a Lucene-style on-disk segmented index. It would load
faster and use less memory, but it is a storage engine, and building a
storage engine is `php-mini-database`'s job.

What it costs: startup time grows with the size of the log. At the target
size (10,000 documents) a replay takes seconds, so there is no index snapshot
and the log is replayed before the socket opens.

# Every acknowledged write is fsynced first

A derived index invites a weaker guarantee, but there is a catch. When the
worker gets `200` it acknowledges its job, and no one will ever retry that
job. If the service then loses the write, the index stays stale until the
product changes again, and nothing notices.

So the order is: append, `fflush()`, `fsync()`, apply to the index, respond.
If the write or the fsync fails, the client gets `503` and the process stops
(fail-stop). A failed `fsync()` in PHP returns a bare `false` with no errno.
After a failure the kernel may already have dropped the dirty pages, so a
retry proves nothing.

What it costs: one fsync per write, now paid by a background worker instead
of a user. A full reindex of 10,000 documents costs up to 10,000 fsyncs,
tens of seconds, and less when documents have not changed, because a write
with the same source version only marks the generation. On
macOS PHP does not use `F_FULLFSYNC`, so the guarantee against power loss
holds only on Linux.

# One process, one loop

One PHP process multiplexes connections with `stream_select()` and parses
HTTP incrementally. Request handling (search, a write with fsync) runs
synchronously on the loop.

Prefork workers were rejected. Each worker would need its own copy of a
copy of a ~350 MB index, because PHP's reference counting writes to shared pages and
defeats copy-on-write after `fork()`. Writes would also have to be broadcast
to every worker. Fibers were rejected because they add no CPU parallelism.

What it costs: throughput is bounded by one core, and a slow handler delays
every request queued behind it. In return every index operation is
serialized, so a write can never be half visible.

# Source versions and tombstones order the writes

Indexing jobs run concurrently and can arrive out of order. A worker that
read the row before an update can deliver after the worker that read it
after. A worker that read the row before a delete can resurrect the product
after the delete is indexed.

Each `PUT` and `DELETE` therefore carries the row's **source version**, a
counter the platform increments in the same transaction as the change. The
service applies a write only if its version is greater than the stored one.
Otherwise it answers `200` with `"applied": false`, so the job is not
retried. A delete leaves a **tombstone** with its version, so a stale `PUT`
cannot bring the document back.

The earlier design used `If-Match` against a version counter owned by the
service. That protects two people editing the same document. It does nothing
about a job that is delivered late.

What it costs: tombstones accumulate. They are cleared when a full reindex
completes, because at that point the source of truth has been replayed in
full.

# Full reindex in place, by generation

Replaying every product is not enough. A product deleted while the service
was unavailable, or whose delete job ended in the dead-letter queue, would
never be sent again and would stay searchable forever.

The platform calls `POST /reindex` and receives a generation number. It
sends every document tagged with that generation, then calls `complete`. The
service deletes every document the generation did not touch. The start and
the completion are written to the log, so a reindex survives a restart, and
a new reindex cancels one that never completed.

The rejected alternative was a shadow index with an atomic swap, as with
aliases in Elasticsearch. Searches would never see a half-finished reindex,
but two indexes in memory would need about 2 GB, double the budget.

Ordinary indexing jobs keep running during a reindex, and that creates a
trap. A product created after the reindex has already passed its key would
not be marked, and `complete` would remove it. The rule that closes it:
**while a generation is open, every applied write belongs to it**. A
`PUT` with a newer source version is fresh truth from the source, wherever it
comes from. A `DELETE` leaves a tombstone, and `complete` never touches
tombstones. The alternatives were worse. The incremental worker could fetch
the open generation itself, but then it races with `POST /reindex`. Or
incremental writes could be refused during a reindex, but then they would
pile up in the queue for up to a minute.

What it costs: during a reindex, search sees a mix of old and new documents.
Each document on its own is always consistent.

# A damaged or empty log means `reindex_required`

A torn record at the end of the log was never acknowledged, so it is
truncated. A checksum failure anywhere else used to mean refusing to start,
because under the system-of-record design it meant lost data. Now it means a
lost cache. The damaged log is renamed aside for inspection, never deleted.
The service starts empty, in the `reindex_required` state.

A first start, with no log at all, enters the same state. That separates
"empty because the source is empty" from "empty because nobody filled it".
In `reindex_required`, `/health/ready` answers `503`, and only a completed
reindex clears it. A reindex of zero documents is legitimate and leaves the
service ready with an empty corpus.

# AND, not OR

`php sockets timeout` matches only documents that contain all three terms.
The result is predictable and easy to test, and it is computed by
intersecting sorted postings starting from the rarest term.

OR returns more results, but it needs the union of every posting list plus
pruning such as WAND or MaxScore to stay fast, and that is out of scope for
v1. A query that analyses to no terms (`!!!`) is a `422 empty_query`, not an
empty result. An empty result would hide a bug in the client.

What it costs: one typo returns nothing, and there is no typo tolerance.

# Stemming from the first version

With AND and no morphology, a Russian query for `сокет` misses a document
that contains `сокетов`. A Russian noun has about a dozen forms, so AND
without stemming is close to unusable for Russian text.

Tokens of Cyrillic letters go through Snowball Russian, tokens of Latin
letters go through Snowball English (Porter2), and other tokens are left
as they are. The stemmers are written by hand. The official Snowball
vocabularies are the test fixtures: about 92,000 word/stem pairs, BSD-3
licensed, pinned to the same upstream commit as the algorithm.

What it costs: the analyzer version becomes part of the index format. A
different analyzer means a full rebuild.

# BM25 from the start

The original plan was plain term-frequency scoring first, and BM25 later. A
TF-only scorer is code that will be thrown away. It ignores how rare a term
is and how long a document is, so long documents always win. Meanwhile the
index has to keep the statistics BM25 needs anyway.

Scores are BM25 per field with `k1 = 1.2` and `b = 0.75`, summed with
weights `title = 2.0` and `body = 1.0`. Ties are broken by `id` in ascending
byte order. Query terms are deduplicated and sorted before summing, because
floating-point addition depends on the order of its operands.

BM25F is deferred. It changes only the formula, not the index.

# Search returns ids

Search returns `id`, `score` and `source_version`. The platform reads the
rows it shows from its own database. Stored fields would be a second copy to
keep in sync, and would cost a random disk read per result on a
single-threaded loop.

`GET /documents/{id}` stays, but only as a diagnostic: source version,
generation, field lengths and terms. There is no listing endpoint.

# Content-Length only

The only client is the platform, and it sends `Content-Length`.

* Any `Transfer-Encoding` is rejected with `501`;
* a body without a length gets `411`;
* conflicting, duplicated or non-canonical lengths get `400`, and the connection is closed, because the end of the request can no longer be known.

`Expect: 100-continue` is supported, so an oversized body is refused before
it is read.

# Offline compaction

Every update appends a full copy of the document, so the log keeps growing.
`compact` runs as a CLI command while the service is stopped. It writes the
live records to a temporary file in the same directory as the log, fsyncs
the file, renames it over the log and fsyncs the directory.

Online compaction (stop the world, or segment the log) is out of scope. Writes
are rare compared with searches, the corpus is small, and the share of
garbage in the log is reported by `/stats`.

Compaction replaces history with state, and some state can only be read from
history:

* the tombstones still guarding against stale jobs;
* the generation counter, because a late `complete` for a reused number would wipe the index;
* a reindex still in progress;
* whether the index has ever been filled. An empty corpus after compaction must not look like a service nobody filled.

The compacted log therefore starts with one `checkpoint` record that states
these values explicitly. It is followed by the last `put` of every live
document and a `delete` for every tombstone. The test is one property: replaying the
compacted log produces the same `Corpus` as replaying the original.

What it costs: downtime while compacting. Search is unavailable for that
time, but no data is.

# Loopback and a token

By default the service binds to `127.0.0.1`. Every endpoint except
`/health/*` requires `Authorization: Bearer <token>`, which is compared with
`hash_equals()`. The service refuses to start without a token of at least 32
bytes. Even a derived index is worth protecting: a `complete` call with an
empty generation wipes it.

TLS and scoped tokens are out of scope. Encryption in transit is the
responsibility of the environment.

# No runtime dependencies

The runtime needs only PHP 8.5 with `mbstring`, `intl` (for NFKC
normalization) and `pcntl` (for signals), and checks for all three at
startup. Composer is used only for autoloading and development tools. That
includes the stemmers: ready-made PHP Snowball ports exist, but writing them
is part of the point.

# Level 8, a component run as a child process

`php-mini-search` sits at level 8, after `php-mini-database`, and
`php-systems-platform` moves to level 9. Search is one more subsystem the
platform is built from, not a step beyond it.

It is wired in like the other components. The platform requires
`researcher86/php-mini-search` through Composer and starts
`vendor/bin/mini-search serve` as a child process, the same way it already
runs the database, cache and HTTP servers. The lab's boundary of "one machine,
one process tree" therefore still holds. The difference is the interface: the
platform talks to search only over HTTP, and the package ships no client. The
platform writes its own.

Two alternatives were rejected:

* a level 9 after the platform: the first service outside its process tree;
* a separate container that the platform knows only by URL.

What it costs: the rule "the platform never imports `PhpMiniSearch\`
classes" is a matter of discipline. Nothing enforces it, and the whole
engine sits in the platform's `vendor/`.

# The lab changes at release

Adding a level touches every repository in the lab:

* "Level N of 8" becomes "of 9" in every README;
* a row is added to the shared table, and the neighbours' links change;
* the hub's counts and diagrams change, and its Out of Scope section changes.

Doing that before there is code would announce a level that does not exist
yet. It would also break the hub's true statement that every component is
released, and it would have to be redone if the plan changes. Until v1.0.0,
this README says "Level 8 of 9 (planned)", and every other repository stays
as it is. The checklist of edits is part of Phase 10 in
[PHASES.md](PHASES.md).

The hub's Out of Scope currently rules out "more queues, caches, HTTP
servers, databases or runtimes", and this project has an HTTP server and a
log of its own. At release that line is narrowed: a new component earns its
place only by teaching a mechanism no other level covers. Here that is text
analysis, the inverted index and ranking. The HTTP server and the log are
means, deliberately smaller than the levels that teach them.

# Postings are plain PHP arrays

The first target was 100,000 documents and 1 GB. A throwaway benchmark
(`.scratch/php-mini-search-v1/prototypes/postings/`) measured three
representations on a synthetic Zipf corpus with about 425 postings per
document:

| 100,000 documents | Index | Bytes per posting | Delete a document |
|---|---|---|---|
| nested arrays `term → [doc => tf]` | 2,432 MB | 60 | 0.4 ms |
| `list<int>` of `doc << 16 \| tf` | 1,200 MB | 30 | 61 ms |
| packed `"VV"` strings | 411 MB | 10 | 7.3 ms |

At that size, only packed strings fit, and they come with expensive deletes.
The alternative to expensive deletes is a dead-document bitmap with
in-memory compaction, as Lucene does. That adds three invariants: dead
numbers, renumbering, and separate statistics for live documents. None of
them teaches anything about search.

The scale was then set by the goal instead. The point of the project is to
see how full-text search works, so the corpus is up to 10,000 documents. At
that size, nested arrays take 319 MB for the index (368 MB peak RSS). They are
the most readable form, and replacing a document is one `unset()` per term.

Two features existed only for the larger corpus, and they were removed with
it:

* `POST /documents/batch`, together with its 4 MB body limit and its cap on concurrent large bodies. A full reindex is single `PUT`s;
* replay in chunks inside the event loop. Replay now finishes before the socket opens.

The index snapshot, group commit and online compaction are out of scope for
the same reason.

What it costs: the service is built for small corpora. Going past about
20,000 documents means revisiting this decision, and the measurements above
show where it breaks.

# Length-prefixed frames with a JSON body

Each record is `[uint32 length][uint32 crc32][JSON body]`. The length gives an
exact record boundary, the checksum detects a torn or damaged record, and the
body can be read by eye with `strings` or `bin/mini-search check`. It is the
same framing idea as the WAL in `php-mini-database` and the length-prefixed
protocol in `php-worker-pool`.

JSON Lines was rejected: its record boundary is `\n`, so framing depends on
the content being escaped correctly. A fully binary layout was rejected too:
it is one more hand-written serializer, and it teaches nothing about search.

What it costs: JSON is larger and slower to parse than a binary layout. At
10,000 documents, replay still takes seconds.

# Apostrophes are removed inside a word

The corpus is products, and apostrophes show up in brands and surnames:
`Levi's`, `O'Neill`, `д’Артаньян`. NFKC does not fold `’` (U+2019) or `ʼ`
(U+02BC) into `'`.

Splitting on apostrophes turns `O'Neill` into the terms `o` and `neill`. A
query for `oneill` then finds nothing, and the dictionary fills up with
one-letter terms. Keeping the apostrophe inside the token lets Porter2 strip
`'s`, but a query typed without the apostrophe still misses. People almost
never type apostrophes into a search box.

An apostrophe between two letters is therefore removed before tokenizing:
`Levi's` → `levis` → stem `levi`, and `O’Neill` → `oneill`. Documents and
queries go through the same step, so the apostrophe never matters. An
apostrophe at the edge of a word (`'quoted'`) stays a separator.

What it costs: Porter2's own step for `'s` is never used. Its plural rule
gives the same stem for `levis`.
