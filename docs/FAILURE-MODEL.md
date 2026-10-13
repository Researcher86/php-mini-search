# Failure Model

What breaks, what the service does about it, and what it does not
guarantee. Every scenario here comes with a test, or with an explicit
statement of why it has none.

---

# What is guaranteed

* a write acknowledged with `200 applied:true` survives a crash of the process. It also survives power loss, **on Linux only**: on macOS PHP's `fsync()` does not issue `F_FULLFSYNC`;
* a search never sees a write that is not yet durable;
* a stale or reordered write never overwrites a newer one and never resurrects a deleted document;
* a completed reindex leaves exactly the documents of its generation;
* the index can always be rebuilt from the platform.

# What is not guaranteed

* search results match the platform's database only eventually: they lag by the time an indexing job takes;
* during a reindex, search sees a mix of documents from the old and the new generation;
* pages of the same query are not a snapshot;
* throughput above one CPU core;
* availability while `compact` runs.

---

# Scenarios

## Power cut in the middle of an append

The last record is torn. On startup its checksum or length does not match.
It was never acknowledged, because the fsync never returned, so it is
truncated and startup continues.

**Test:** truncate the log at every byte offset inside the last record; every
earlier record must survive.

## A checksum fails in the middle of the log

That is a bad sector or a bug, and truncating there would drop acknowledged
writes. The log is renamed aside (never deleted) and the service starts empty
in `reindex_required`: `/health/ready` answers `503` until the platform
completes a reindex. `bin/mini-search check` inspects the log offline.

A damaged length field in the middle could point past the end of the file
and pass for a torn tail, which would silently drop every acknowledged record
after it. A length above the 256 KB maximum is therefore always treated as
corruption, never as a torn tail.

**Test:** corrupt one byte in the middle, in a body and in a length field;
startup must report the state and keep the damaged file.

## `fsync()` fails

PHP returns `false` and gives no errno. After a failed fsync the kernel may
already have dropped the dirty pages, so a second fsync can succeed without
the data reaching the disk. The current client gets `503` and the process
exits with a non-zero code. On restart, replay rebuilds from what is actually
on disk, and the job that got `503` is retried by the queue.

**Test (Phase 4):** the log's handle is swapped for a read-only one, so the
next write fails the way a full disk would. The write throws, and the corpus
state and search results are exactly what they were before it: the index is
only touched after the append and the fsync. Injecting `apply before append`
fails this test, and only this test.

**Test (Phase 7):** the real process with its data directory on a tmpfs
filled to its last 150 KB. Writes go on until one fails. That request gets
`503 storage_failure` and the process exits with code 1. After the space is
freed and the service restarted, every acknowledged write is there, and the
failed write, retried as the queue would retry it, is applied. If the API
stops flagging the failure, the process keeps running and the test fails.

## The process is killed under write load

**Test (Phase 7):** start the server as a subprocess, keep four connections
sending `PUT`s, `kill -9` it at a random moment, restart it. Five runs
acknowledge between 200 and 560 writes each. Every acknowledged write must be present.
An unacknowledged write may or may not be: its fsync may have finished after
the response was lost. No document the client never sent may appear.

**Limit of this test:** `kill -9` is not power loss. The page cache still
reaches the disk, so this test cannot prove that `fsync()` is called at all.
Simulating power loss at the filesystem level (`dm-flakey`, LazyFS) is out of
scope.

## A crash in the middle of `compact`

The new log is written to a temporary file in the same directory, fsynced,
renamed over the old log, and the directory is fsynced. At every step one
complete log exists, the old one or the new one.

**Test:** a stray half-written temporary file next to an intact log, which
is a crash at any point before the rename: the old log replays whole, and the
next compaction overwrites the leftover. A crash after the rename leaves the
new log, which is complete because it was fsynced before the rename. What
cannot be tested here is power loss between the rename and the directory
fsync. Then the rename itself may be lost, and the old log, still whole, is
what remains. That needs a filesystem-level fault injector, which is out of
scope.

## A crash in the middle of a reindex

The start of the reindex is in the log, so after replay the generation is
still open. The platform either continues sending documents or starts a new
reindex, which cancels the open one.

## Jobs arrive out of order

A worker that read the row before an update delivers after the worker that
read it afterwards. Its source version is lower, so the write answers
`applied:false` and is not retried.

## A delete is overtaken by a stale `PUT`

The tombstone stores the version of the delete, and the late `PUT` carries a
lower one, so it is ignored.

## A delete arrives before its `PUT`

The product was created and deleted in quick succession, and the jobs were
delivered out of order. A `DELETE` of an unknown id still leaves a tombstone
(`200 applied:true`, not `404`), so the late `PUT`, with its lower version, is
ignored.

## A product is created during a reindex

The reindex has already passed the product's key. The ordinary indexing job
writes it while the generation is open, and that write marks it with the open
generation, so `complete` keeps it.

**Test:** open a generation, send part of the corpus with it, send a new
document without `generation`, then complete. The new document must survive.

## A delete never arrives

Its job ended in the dead-letter queue, or it was lost while the service was
down. The document stays searchable until the next full reindex, which
removes every document its generation did not touch.

## Nobody ever filled the index

On a first start with no log, the service is in `reindex_required`, not
ready. It does not answer "no results" forever as if it were working.

## Slow and idle clients

Deadlines for headers (10 s), the body (30 s), idle keep-alive (30 s) and
sending a response (30 s), together with a cap of 256 connections, put a
bound on what a slow client can hold. Above the cap the service stops calling
`accept()`.

**Benchmark:** p99 search latency with 200 idle slow connections open must
be no worse than twice the latency without them.

## A huge or malformed request

Oversized headers → `431`. An oversized body → `413` before it is read.
Conflicting or non-canonical framing → `400` and the connection is closed.
`Transfer-Encoding` → `501`.

**Test:** a corpus of malformed requests, each sent whole, byte by byte and
in random chunks; the results must be identical.

## A blocked event loop

Every handler runs on the loop, so an expensive query delays everyone behind
it. The bounds are 32 terms per query, `offset + limit ≤ 1000` and a top-k
heap instead of a full sort. Logs written synchronously to a slow
`stderr` pipe can also block the loop. That risk is documented, not solved.

## `SIGTERM`

The service stops accepting connections, closes the idle ones and finishes
the requests in flight within 10 seconds. There is nothing to flush: every
acknowledged write is already fsynced.

---

# Coverage

Each scenario above, and where it is checked.

| Scenario | Checked by |
|---|---|
| Power cut in the middle of an append | `LogTest`: a cut at every byte of the last record |
| A checksum fails in the middle | `LogTest`, `CorpusTest` (damaged body and damaged length field) |
| `fsync()` fails | `CorpusTest` (a log that refuses the write), `FullDiskTest` (the real process) |
| Killed under write load | `KillDuringWritesTest`, 5 runs |
| A crash in the middle of `compact` | `LogTest` (before the rename); after the rename, see above; power loss before the directory fsync is not tested |
| A crash in the middle of a reindex | `CorpusTest`: a reindex survives a restart |
| Jobs out of order, a delete overtaken, a delete before its `PUT` | `CorpusTest`, `ApiTest` |
| A product created during a reindex | `CorpusTest` |
| A delete never arrives | `CorpusTest`: a completed reindex removes what it did not touch |
| Nobody ever filled the index | `CorpusTest`, `ApiTest`, `ServiceTest` |
| Slow and idle clients | `ServerTest` (every timeout and the connection cap); the p99 benchmark is Phase 8 |
| A huge or malformed request | `RequestParserTest`, `ServerTest` |
| A blocked event loop | not tested as a failure: it is bounded by the query limits, and Phase 8 measures it |
| `SIGTERM` | `ServiceTest` (drain, deadline, a second signal) |
| Power loss on Linux | not tested: it needs a filesystem fault injector (`dm-flakey`, LazyFS), which is out of scope |

# Open

* what `fsync()` guarantees for a data directory bind-mounted through Docker Desktop on macOS; the data directory may have to be a Docker volume.
