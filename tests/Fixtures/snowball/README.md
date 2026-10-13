# Snowball vocabularies

The reference word → stem pairs the hand-written stemmers in
`src/Analysis/Snowball/` are tested against. Line N of `voc.txt` is a word;
line N of `output.txt` is its stem.

Copied verbatim, unmodified, from
[snowballstem/snowball-data](https://github.com/snowballstem/snowball-data)
at commit `a0ec0d0a2839ec885878868de20fcb63209d92b0` (2026-06-09):

| Here | Upstream |
|---|---|
| `russian/voc.txt`, `russian/output.txt` | `russian/voc.txt`, `russian/output.txt` |
| `english/voc.txt`, `english/output.txt` | `english/voc.txt`, `english/output.txt` (Porter2, not `porter/`) |

They match the algorithms in
[snowballstem/snowball](https://github.com/snowballstem/snowball) at commit
`14a4f24e4cf64f1003d1205edc0713b8111a18a8`. Update both together, or the
tests will disagree with the code for reasons that have nothing to do with
it.

Licensed under the 3-clause BSD license in [COPYING](COPYING), copied
verbatim from the same snowball-data commit.
