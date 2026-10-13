<?php

declare(strict_types=1);

/**
 * Downloads a slice of Russian and English Wikipedia as JSON Lines:
 * {"id", "title", "body"} per line.
 *
 *   php benchmarks/corpus-wikipedia.php [articles=10000] [seed=1] > benchmarks/data/wikipedia.jsonl
 *
 * The articles come from the `wikimedia/wikipedia` dataset (the 2023-11-01
 * dumps) through the Hugging Face datasets server, 100 rows per request,
 * taken at seeded random offsets, so the slice is varied and reproducible.
 * Requests are a second apart, and a refusal (the server rate-limits with
 * 429) backs off exponentially up to a minute before trying again.
 * Half are Russian, half English. Each body is the start of the article, cut
 * at a word boundary near 6 KB — close to the ~4 KB average the service is
 * sized for, and far under its 64 KB limit.
 *
 * The MediaWiki API was the first source tried; its plain-text extracts take
 * about 7 s per request, an hour for this slice. The text is CC BY-SA 4.0:
 * the file is for local benchmarks only, and benchmarks/data/ is never
 * committed.
 */

$articles = (int) ($argv[1] ?? 10_000);
mt_srand((int) ($argv[2] ?? 1));
$userAgent = 'php-mini-search-benchmark/1.0 (https://github.com/Researcher86/php-mini-search)';
$rowsPerRequest = 100;
$languages = [
    // Rows in each split of the 20231101 dumps, rounded down, so every offset is valid.
    'ru' => ['config' => '20231101.ru', 'rows' => 1_900_000, 'wanted' => intdiv($articles, 2)],
    'en' => ['config' => '20231101.en', 'rows' => 6_400_000, 'wanted' => $articles - intdiv($articles, 2)],
];

foreach ($languages as $language => $split) {
    $got = 0;
    $failures = 0;
    $offset = null;
    while ($got < $split['wanted']) {
        // Kept until it succeeds, so a retry does not change the slice.
        $offset ??= mt_rand(0, $split['rows'] - $rowsPerRequest);
        $url = 'https://datasets-server.huggingface.co/rows?' . http_build_query([
            'dataset' => 'wikimedia/wikipedia',
            'config' => $split['config'],
            'split' => 'train',
            'offset' => $offset,
            'length' => $rowsPerRequest,
        ]);
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: {$userAgent}\r\n", 'timeout' => 60]]));
        $response = is_string($json) ? json_decode($json, true) : null;
        $rows = is_array($response) ? ($response['rows'] ?? null) : null;

        if (!is_array($rows)) {
            if (++$failures > 12) {
                fwrite(STDERR, "\nGiving up on {$split['config']} after 12 refusals in a row.\n");
                exit(1);
            }
            sleep(min(60, 2 ** $failures));
            continue;
        }
        $failures = 0;
        $offset = null;
        sleep(1);

        foreach ($rows as $row) {
            $article = is_array($row) ? ($row['row'] ?? null) : null;
            if (!is_array($article) || !is_string($article['id'] ?? null) || !is_string($article['title'] ?? null) || !is_string($article['text'] ?? null)) {
                continue;
            }
            $text = trim($article['text']);
            if ($text === '' || $got >= $split['wanted']) {
                continue;
            }
            if (strlen($text) > 6_000) {
                $cut = mb_strcut($text, 0, 6_000);
                $space = strrpos($cut, ' ');
                $text = $space === false ? $cut : substr($cut, 0, $space);
            }
            $got++;
            echo json_encode(['id' => "{$language}-{$article['id']}", 'title' => $article['title'], 'body' => $text], JSON_UNESCAPED_UNICODE), "\n";
        }
        fwrite(STDERR, "\r{$language}: {$got}/{$split['wanted']}");
    }
    fwrite(STDERR, "\n");
}
