# Benchmarks

The baseline of Phase 8: what the service measured before anything was tuned,
the one change the measurements forced, and the targets from
[ARCHITECTURE.md](ARCHITECTURE.md#targets) checked against both.

## How

```bash
make bench              # 10,000 synthetic documents
make bench-wikipedia    # 10,000 Wikipedia articles (downloaded once into benchmarks/data/)
```

[benchmarks/run.php](../benchmarks/run.php) starts `bin/mini-search serve` as
its own process, loads the corpus through the API as a full reindex (`PUT`
with the generation, then `complete`), and runs four profiles of 8 seconds
each:

| Profile | Connections | Requests |
|---|---|---|
| `search` | 16 | `POST /search` with 1–3 words taken from real titles |
| `write` | 8 | `PUT` of a newer version of a random document: analysis, one fsync, reindexing |
| `mixed` | 16 | 95% search, 5% write |
| `slow` | 16 + 200 | `search`, while 200 more connections trickle a request head one byte every 500 ms |

It then restarts the service to time a full replay. Each connection keeps
one request in flight. The service has one loop, so a request waits behind
every request queued before it: under 16 connections, p50 is roughly 16 times
the cost of one request. The load generator runs in its own process, so it
does not share the service's core. Raw output is in
[benchmarks/results/](../benchmarks/results/).

The corpora:

* **synthetic**: [corpus-synthetic.php](../benchmarks/corpus-synthetic.php), 10,000 documents of Zipf-distributed made-up Russian and English words, titles of 4–10 words, bodies of about 4 KB. It is deterministic and needs no download;
* **Wikipedia**: [corpus-wikipedia.php](../benchmarks/corpus-wikipedia.php), 5,000 Russian and 5,000 English articles from the `wikimedia/wikipedia` dumps (2023-11-01), taken at seeded random offsets. Each body is cut at a word boundary near 6 KB. The text is CC BY-SA 4.0 and is never committed.

## Environment

Apple M2 Max, Docker Desktop (LinuxKit kernel), 12 CPUs and 10 GB visible
to the container, `php:8.5-cli` 8.5.11. Service and load generator are on the
same machine, over loopback. **Xdebug is off** (`XDEBUG_MODE=off`): the
development image loads it in trigger mode, and the first runs, made with it
loaded, were up to 2.7 times slower across the board. Those numbers measured
Xdebug, not the service, and are not quoted here.

Treat every number as what this container measured on this machine, not as
the implementation's ceiling.

## The baseline, and the one change it forced

Synthetic corpus, 10,000 documents:

| | before | after the stem cache |
|---|---|---|
| load (a full reindex through the API) | 339 docs/s | **985 docs/s** |
| `write`: one `PUT` | p50 24.4 ms, 324/s | **p50 8.2 ms, 939/s** |
| `search` | 488/s, p99 58.5 ms | 489/s, p99 66.3 ms |
| startup: replay of the log | **29.9 s** ✗ (12,773 records) | **5.0 s** ✓ (17,735 records) |
| peak RSS | 276 MB | 308 MB |

Without the cache the startup target (≤ 10 s) was missed by three times. A
breakdown of where the time went:

* analysis cost 12 µs per token, and **96% of that was stemming**. NFKC, lowercasing and tokenizing 300 documents took 22 ms together;
* **90% of tokens repeat**, as words in text do: 5 million tokens in the corpus, 27,379 distinct;
* fsync cost 0.56 ms, so it was not the bottleneck.

The fix was a bounded `token → term` cache in `Analyzer`, about 15 lines. It
holds up to 200,000 tokens and is emptied when full. It changes no result:
the 92,434 Snowball reference pairs and the oracle comparison pass
unchanged. Analysing the whole corpus went from about 60 s to 1.5 s, and the
cache holds about 4 MB here. The other options were rewriting the stemmers
for speed, which would gain perhaps 2–3 times and make them no longer a
line-by-line port of the `.sbl` sources, or raising the startup target. Both
were rejected (DECISIONS.md, "Stems are cached").

Search did not change. Its cost is BM25 over the matches of frequent terms,
not the analysis of a short query.

PHP's default `memory_limit` of 128 MB stopped the service at a few thousand
documents. The service now sets `memory_limit` itself, `768M` by default
(`MINI_SEARCH_MEMORY_LIMIT`).

## Synthetic, with the cache

```text
profile       requests      rps  errors    p50 ms    p95 ms    p99 ms
load             10000    985.2       0      7.68      9.98     19.37
search            3921    488.9       0     31.97     54.10     66.27
write             7515    939.4       0      8.20     10.60     14.31
mixed/search      3812    502.9       0     30.19     51.02     58.13
mixed/write        211    502.9       0     29.25     50.53     53.93
slow              3922    489.6       0     31.56     53.11     63.44

RSS after load 268.2 MB, peak 307.9 MB, after replay 298.7 MB
startup 5.03 s (replay of 17735 records in 5003 ms)
```

## Wikipedia, with the cache

10,000 articles, 5,000 Russian and 5,000 English, with an average body of
2.7 KB:

```text
profile       requests      rps  errors    p50 ms    p95 ms    p99 ms
load             10000   1107.4       0      7.08     10.25     12.11
search           84601  10575.1       0      0.99      4.27      5.96
write             9070   1133.8       0      6.95      9.32     10.70
mixed/search     54381   7161.9       0      1.78      5.09      6.88
mixed/write       2914   7161.9       0      2.69      5.85      7.73
slow             70172   8771.5       0      1.31      4.57      6.33

RSS after load 218.8 MB, peak 239.2 MB, after replay 231.3 MB
startup 6.66 s (replay of 21992 records in 6639 ms)
```

Search is 22 times faster here than on the synthetic corpus, and the
difference is the corpus, not the code:

* the synthetic vocabulary has 25,896 terms under a steep Zipf curve. A few terms are in nearly every document, so almost every query computes BM25 for thousands of matches;
* Wikipedia has 151,505 terms. Words from a title are mostly rare, so the rarest term's posting list is short, and intersection starting from it does very little work.

The synthetic run is therefore close to a worst case for search, and the
Wikipedia run a typical one. Writes cost about the same in both, 7–8 ms,
mostly analysis and one fsync.

The 200 slow clients lowered throughput by 17% (8,772/s against 10,575/s)
but not latency: the loop spends a little time on their bytes, and each
request still waits only behind the requests in front of it.

## Targets

| Target | Synthetic | Wikipedia |
|---|---|---|
| peak RSS ≤ 512 MB | ✓ 308 MB | ✓ 239 MB |
| startup ≤ 10 s | ✓ 5.0 s (17,735 records) | ✓ 6.7 s (21,992 records) |
| p99 with 200 slow clients ≤ 2 × p99 without | ✓ 63.4 vs 66.3 ms | ✓ 6.3 vs 6.0 ms |
| no errors without overload | ✓ 0 | ✓ 0 |

The slow clients cost nothing measurable. Each one wakes the loop for a
single byte every half second, and its head would hit the 10-second timeout
long before it completed.

## Not measured

* ranking quality: the plan mentioned using the Wikipedia slice for it, but it needs judged queries, and nothing in this phase provides them;
* concurrency beyond 16 connections: with one loop, more connections only lengthen the queue;
* a cold disk, or a disk whose fsync costs more than 0.56 ms: `write` latency moves with it.
