<?php

declare(strict_types=1);

/**
 * Writes a synthetic corpus as JSON Lines: {"id", "title", "body"} per line.
 *
 *   php benchmarks/corpus-synthetic.php [documents=10000] [seed=1] > corpus.jsonl
 *
 * Words are drawn from a Zipf distribution over a vocabulary of made-up
 * Russian- and English-looking words, so a few terms are in almost every
 * document and most are rare — the shape real text has, without a download.
 * Titles are 4–10 words, bodies about 4 KB. Fully deterministic per seed.
 */

$documents = (int) ($argv[1] ?? 10_000);
mt_srand((int) ($argv[2] ?? 1));

$syllables = [
    ['ка', 'бе', 'ль', 'за', 'ря', 'дк', 'ус', 'ко', 'ри', 'те', 'ма', 'ни', 'то', 'ва', 'ло', 'ст', 'на', 'по', 'ре', 'ди'],
    ['ca', 'ble', 'char', 'ge', 'fast', 'po', 'wer', 'ad', 'ap', 'ter', 'lin', 'ko', 'ma', 'ri', 'son', 'del', 'ta', 'vi', 'no', 'ex'],
];
$vocabulary = [];
for ($rank = 0; $rank < 50_000; $rank++) {
    $alphabet = $syllables[$rank % 2];
    $word = '';
    for ($n = 2 + $rank % 3; $n > 0; $n--) {
        $word .= $alphabet[mt_rand(0, count($alphabet) - 1)];
    }
    $vocabulary[] = $word;
}

// Zipf(s = 1) by inverse transform over cumulative weights.
$cumulative = [];
$sum = 0.0;
foreach ($vocabulary as $rank => $_) {
    $sum += 1.0 / ($rank + 1);
    $cumulative[] = $sum;
}
$pick = function () use ($cumulative, $sum, $vocabulary): string {
    $target = mt_rand() / mt_getrandmax() * $sum;
    [$low, $high] = [0, count($cumulative) - 1];
    while ($low < $high) {
        $middle = intdiv($low + $high, 2);
        $cumulative[$middle] < $target ? $low = $middle + 1 : $high = $middle;
    }

    return $vocabulary[$low];
};
$words = fn (int $count): string => implode(' ', array_map(fn () => $pick(), range(1, $count)));

for ($i = 1; $i <= $documents; $i++) {
    echo json_encode([
        'id' => "SYN-{$i}",
        'title' => $words(mt_rand(4, 10)),
        'body' => $words(mt_rand(350, 650)),
    ], JSON_UNESCAPED_UNICODE), "\n";
}
