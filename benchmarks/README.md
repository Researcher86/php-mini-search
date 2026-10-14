# Benchmarks

The baseline measurements of Phase 8. The numbers, the environment, and what
was decided from them are in [docs/BENCHMARKS.md](../docs/BENCHMARKS.md).

```bash
make bench                        # 10,000 synthetic documents, 8 s per profile
make bench-wikipedia              # 10,000 Wikipedia introductions (downloaded once)
make bench SECONDS=20             # longer profiles
```

| File | What it does |
|---|---|
| `corpus-synthetic.php` | a deterministic corpus: Zipf-distributed made-up Russian and English words, ~4 KB bodies |
| `corpus-wikipedia.php` | 5,000 Russian and 5,000 English articles from the `wikimedia/wikipedia` dumps (2023-11-01) via the Hugging Face datasets server, 100 rows per request at seeded offsets, bodies cut near 6 KB |
| `run.php` | starts the service, loads the corpus as a full reindex, runs four load profiles, restarts to time the replay, and checks the targets |
| `results/` | the raw output of the runs quoted in docs/BENCHMARKS.md |

The profiles:

- `search`: 16 connections, search only;
- `write`: 8 connections, `PUT` with newer versions only, one fsync each;
- `mixed`: 16 connections, 95% search and 5% writes;
- `slow`: the search profile alongside 200 connections that trickle a request head one byte every 500 ms.

Every connection keeps one request in flight. Because the service has one
loop, a request's latency includes the requests queued before it: p50 under
16 connections is roughly 16 times the time of one request.

Wikipedia text is CC BY-SA 4.0, and `benchmarks/data/` is in `.gitignore`.
The download script identifies itself with the repository's URL, paces its
requests a second apart, and backs off when the server rate-limits it.
