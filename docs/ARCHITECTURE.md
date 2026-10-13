# Architecture

How `php-mini-search` is put together, traced through one write and one
search. This is the planned design. The memory figures were measured with a
throwaway benchmark, and everything else is still to be measured in Phase 8.

For why each choice was made, see [DECISIONS.md](DECISIONS.md). For the HTTP
contract, see [API.md](API.md).

---

# Vocabulary

**Document**: a searchable projection of one platform record (a product),
identified by the platform's id. The platform's database, not this service,
is its source of truth.

**Corpus**: the set of all documents one service instance currently holds.

**Term**: the normalized, stemmed unit of text that the index stores and
that queries match against. It is produced the same way from documents and
from queries. A *token* is the raw piece of text before normalization and
stemming.

**Source version**: a monotonic integer owned by the platform record that a
document projects. A write whose source version is not greater than the
stored one is stale and is ignored.

**Tombstone**: the remembered fact that a document was deleted at a given
source version. It is kept so that a stale write cannot resurrect the
document.

**Generation**: one full reindex. Documents written as part of it are marked
with its number. When it completes, every document it did not mark is
removed.

---

# Where it sits

```text
 php-systems-platform                                   php-mini-search
┌──────────────────────────────────┐                 ┌────────────────────────┐
│ PUT/DELETE /products/{sku}       │                 │                        │
│   1. row written, version += 1   │                 │                        │
│      (a delete is soft)          │                 │                        │
│   2. product.index published     │                 │                        │
│ php-job-queue ──► worker ────────┼── PUT/DELETE ──►│  log ─► in-memory      │
│                   reads the      │    + version    │         index          │
│                   current row    │                 │                        │
│ search controller ───────────────┼── POST /search ►│  ids + scores          │
│   └─ SELECT … WHERE sku IN (ids) │◄────────────────┤                        │
└──────────────────────────────────┘                 └────────────────────────┘
        php-mini-database is the source of truth; the index can always be rebuilt
```

The platform requires this project through Composer and runs
`vendor/bin/mini-search serve` as a child process on `127.0.0.1`, like its
other components. It talks to it only over HTTP.

---

# Components

An arrow means "knows about".

```text
bin/mini-search (serve | compact | check)
   └─ Runtime   — config, extension checks, logger, signals, wiring
        ├─ Http     — event loop, connections, incremental parser, limits, responses.
        │             Knows nothing about search: takes a Request → Response handler.
        └─ Api      — routes, JSON validation, token, error mapping → calls Corpus
             └─ Corpus  — sole owner of the write order, source versions,
                  │       tombstones, generations, replay
                  ├─ Analysis — tokenizer, normalization, Snowball RU/EN (pure functions)
                  ├─ Index    — postings, statistics, intersection, BM25, top-k (memory only)
                  └─ Storage  — log format, checksums, append + fsync, recovery, compaction
```

* `Corpus` is the deep module. Its interface is `put`, `delete`, `get`, `search`, `beginReindex`, `completeReindex` and `stats`. Nothing else touches the log and the index together;
* `Api` sees only `Corpus`, and `Http` knows nothing about search;
* ranking lives inside `Index`. It moves out only if BM25F arrives;
* `Http` is generic but minimal: one handler interface, no middleware.

---

# The write path

```text
PUT /documents/SKU-42  {source_version: 17, title, body}
  │
  ├─ Http     frame by Content-Length, enforce limits
  ├─ Api      token, JSON schema, field limits
  └─ Corpus
       1. stale?   stored version ≥ 17, or a tombstone ≥ 17 → 200 applied:false
       2. analyse  title and body → terms per field
       3. append   one checksummed record to the log
       4. fflush + fsync
       5. apply    remove the old postings, add the new ones, update statistics
       6. respond  200 applied:true
```

While a reindex generation is open, step 5 also marks the document with
that generation, whether or not the request named one. See
[API.md](API.md#writes-during-a-reindex).

Steps 3–5 are never reordered. A search can see a document only after it is
durable. A failed append or fsync answers `503` and stops the process.

---

# The search path

```text
POST /search  {query: "кабель usb", limit: 10, offset: 0}
  1. analyse      NFKC → lowercase → ё→е → tokens → stems; 0 terms → 422 empty_query
  2. order        deduplicate, sort terms; at most 32
  3. intersect    postings, starting from the rarest term (AND); a term may be in either field
  4. score        Σ terms (sorted) Σ fields  w_field · BM25(tf, df, len, avglen)
  5. top-k        heap of size offset + limit (≤ 1000); ties by id ascending
  6. respond      [{id, score, source_version}], total = size of the intersection
```

The search never reads the disk. `score` is a relative number with no stable
scale.

---

# Text analysis

1. Unicode NFKC (`Normalizer`, `ext-intl`). This makes `й` and `ё` single precomposed characters, as the Snowball rules expect;
2. `mb_strtolower`, then `ё → е`;
3. an apostrophe (`'`, `’` U+2019 or `ʼ` U+02BC) between two letters is removed: `Levi's` → `levis`, `O’Neill` → `oneill`, `д’Артаньян` → `дартаньян`. An apostrophe at the edge of a word is a separator;
4. tokens are maximal runs of `\p{L}\p{N}`. Everything else is a separator;
5. a token made only of Cyrillic letters goes through Snowball Russian, and a token made only of ASCII `a`–`z` through Snowball English (Porter2). Anything else is left unchanged: digits (`usb3`), mixed scripts, and accented Latin (`café`), because Porter2 is defined for `a`–`z` only. Lowercase input is required: Porter2 uses an internal uppercase `Y` marker;
6. documents and queries go through the same pipeline.

Stems are cached, `token → term`, up to 200,000 entries. Stemming was 96% of
the cost of analysis, and 90% of tokens repeat. The cache turned a 30-second
replay into a 5-second one ([DECISIONS.md](DECISIONS.md#stems-are-cached)).

---

# The index

`InvertedIndex` holds, in memory only:

* `field → term → [docNum => tf]` for `title` and `body`. A document gets a small internal number when it is added, and a new one when it is replaced;
* per field, each document's length and the total length, from which the average is derived. A term's document frequency is the size of its posting list, so it is never stored separately and cannot drift;
* per field, each document's distinct terms joined into one string with `\0`. This is what a delete needs to find its postings. A full forward index (`doc → term → tf`) would roughly double the memory, and the frequencies are already in the postings;
* `id ↔ internal number`.

Source versions, generations and tombstones belong to `Corpus` (Phase 4), not
to the index.

A query matches a document when **every term appears in at least one
field**, so `кабель usb` finds a product with `кабель` in its title and `usb`
in its body. The candidates come from the rarest term's postings (title ∪
body), and each one is checked against the other terms. Scoring:

```text
score    = Σ terms (sorted) Σ fields  w_f · idf_f(t) · tf·(k1+1) / (tf + k1·(1 − b + b·len/avglen))
idf_f(t) = ln(1 + (N − df_f(t) + 0.5) / (df_f(t) + 0.5))
```

The idf is Lucene's variant, which never goes negative. Robertson's original
drops below zero for a term in more than half the documents. Under AND such
a term is in every match, so it would push all of them down together. The
best `offset + limit` hits are kept in a heap (`TopHits`) whose top is the
worst hit, and ties go to the smaller `id`.

Invariants, checked after every operation in tests:

* every posting points at a live document;
* no posting list is empty;
* a document's term frequencies add up to its field lengths, and its term list names exactly the postings that mention it;
* the total length of a field is the sum of its documents' lengths;
* a replaced or deleted document leaves no postings, lengths or term lists behind.

Postings are nested PHP arrays, `term → [docNum => tf]`, chosen by
measurement. On 10,000 synthetic documents (about 4.2 million postings) the
index takes 319 MB, peak RSS is 368 MB, and deleting a document (one `unset`
per term) costs 0.3 ms. Packed binary strings were six times smaller, but
deleting from them cost twenty times more, and the code is harder to read.
At the target size the compact form buys nothing that is needed. The numbers
are in [DECISIONS.md](DECISIONS.md#postings-are-plain-php-arrays).

The finished `InvertedIndex`, with its lengths, term lists and id maps, was
measured on the same corpus: 291 MB, 381 MB RSS. Deleting a document takes
0.14 ms. The worst search, two terms found in almost every document, takes
14 ms, because BM25 is computed for all 10,000 matches.

---

# Storage

One append-only log is the only durable state:

* one file, `data/search.log`, starting with the magic `MSLOG` and format version `1`;
* each record is `[uint32 body length][uint32 crc32 of body][JSON body]`, packed with `pack('NN')` (big-endian). The length and checksum frame the record. The body stays readable;
* operations: `put` (`id`, `source_version`, `generation` when part of a reindex, `title`, `body`), `delete` (`id`, `source_version`), `mark` (`id`, `generation`: a stale `PUT` from a reindex confirming a document without rewriting it), `reindex-begin`, `reindex-complete` (`generation`), and `checkpoint` (`next_generation`, `open_generation`, `last_completed_generation`), written only by compaction;
* the log holds raw text, and replay analyses it again. The analyzer version is therefore not part of the format.

A torn tail and corruption are told apart like this:

* a length above 256 KB is corruption. No valid record is that large;
* only the **last** record may be short, or fail its checksum, and then it is a torn tail;
* a checksum failure anywhere else is corruption.

The log is `<data>/search.log`, where `<data>` is `--data=DIR` or `./data`.
One process at a time holds it: the service, `compact` or `check`. An
exclusive `flock()` on `<data>/search.log.lock` is taken before anything is
read or repaired. It is a separate file because compaction renames a new log
over the old one, and a lock on the old file would not cover it.

At startup:

1. check the extensions and the config, including the token;
2. replay the log before opening the socket (a few seconds at the target size);
3. a torn tail is truncated. A checksum failure elsewhere, or no log at all, moves the log aside if there is one and enters `reindex_required`;
4. open the listening socket. `/health/live` answers `200`. `/health/ready` answers `200`, or `503` while the service is in `reindex_required`.

The durability recipe (verified against the php-src code and strace, PHP
8.5, Linux):

* call `fflush()` before every `fsync()`. After the first `fsync()` the stream is buffered by libc, and write errors surface only at the next sync;
* write the compaction temp file in the same directory as the log, because across filesystems `rename()` silently degrades to an unsynced copy;
* fsync the directory with `fsync(fopen($dir, 'r'))`.

---

# Concurrency

One process, one thread, one `stream_select()` loop. Client I/O is
multiplexed and request handling is synchronous, so every `Corpus`
operation is serialized. A write is never half visible, and checking a
source version and then applying it is atomic without locks.

Shutdown on `SIGTERM` or `SIGINT` (`pcntl_async_signals`):

* close the listening socket and the idle keep-alive connections;
* finish the requests in flight and answer them with `Connection: close`;
* after a 10-second deadline (configurable), close whatever is left;
* exit with code 0 after a clean drain, or a non-zero code if the deadline expired. A second signal exits at once, and a signal during replay stops the replay immediately.

A `stream_select()` interrupted by a signal is not an error.

---

# HTTP subset

* HTTP/1.1 and HTTP/1.0. Keep-alive is the default in 1.1. In 1.0 it is used only with `Connection: keep-alive`;
* pipelining is sequential: leftover bytes stay in the buffer, the requests on one connection are handled one at a time, and responses go out in order;
* bodies are framed by `Content-Length` only. `Expect: 100-continue` gets `100 Continue` or `413`;
* lines end in CRLF: a bare CR or LF, a NUL or a folded header line is `400`. Only origin-form targets (`/path`) are accepted, and HTTP/1.1 requires `Host`. A repeated `Content-Length` is `400` even when both values agree. No `Content-Length` means an empty body, as RFC 9112 says;
* the next pipelined request is parsed only after the previous response is fully written. While a whole request is buffered, the socket is not read. This keeps responses in order and is the backpressure: a client that pipelines without reading fills its own socket buffer;
* a handler that throws becomes `500 internal_error`. `HEAD` gets headers with the length of the body, but no body;
* responses always carry `Content-Length`. Any response after which the stream state is uncertain carries `Connection: close`.

Limits (all configurable):

| Limit | Value |
|---|---|
| request line + headers | 8 KB → `431` |
| request body | 256 KB → `413` |
| concurrent connections | 256; beyond it, `accept()` is not called |
| receiving headers | 10 s from the first byte |
| receiving the body | 30 s |
| idle keep-alive | 30 s |
| sending a response | 30 s, written non-blocking from a buffer |

---

# Observability

* logs: one JSON line per event on `stderr`, written synchronously; one line per request (method, path, status, latency, `request_id`). The level can be lowered to "errors and slow requests only". Bodies, document text and the token are never logged;
* `GET /stats` (needs the token): counts by route and status, a latency histogram, connections, documents, terms, tombstones, the log size and its share of garbage, the current generation, RSS, uptime;
* configuration: a PHP file returning an array, with overrides from environment variables.

---

# Targets

* corpus: up to 10,000 documents, about 4 KB on average, with far more searches than writes. The point is to see full-text search work, not to scale it;
* RSS ≤ 512 MB on that corpus;
* startup (full replay) ≤ 10 s on that corpus;
* p99 search latency with 200 idle slow connections open is no worse than twice the latency without them;
* no errors on any load profile that does not overload the service.

All four targets are met, measured on a synthetic corpus and on a slice of
Wikipedia ([BENCHMARKS.md](BENCHMARKS.md)). Absolute latency and throughput
targets were never set; the baseline is there for whoever wants to set them.
