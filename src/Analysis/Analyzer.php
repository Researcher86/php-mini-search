<?php

declare(strict_types=1);

namespace PhpMiniSearch\Analysis;

use InvalidArgumentException;
use Normalizer;
use PhpMiniSearch\Analysis\Snowball\EnglishStemmer;
use PhpMiniSearch\Analysis\Snowball\RussianStemmer;

/**
 * Turns text into terms: the units the index stores and queries match.
 *
 * Documents and queries go through this one pipeline, and that is the whole
 * contract. `Кабели USB-C` in a title and `кабель usb` in a search box only
 * meet because both become `кабел`, `usb` and `c` here:
 *
 *   1. Unicode NFKC, so `й` is one character, the ligature `ﬁ` is `fi` and
 *      full-width `ＵＳＢ` is `USB`;
 *   2. lowercase, then `ё` → `е`, since Russian text uses the two
 *      interchangeably;
 *   3. an apostrophe between two letters is removed — `O’Neill` → `oneill` —
 *      so a query typed without it still matches;
 *   4. tokens are maximal runs of letters and digits; everything else
 *      separates them;
 *   5. a token of Cyrillic letters goes through the Russian stemmer, a token
 *      of ASCII Latin letters through the English one, and any other token
 *      (digits, mixed scripts, accented Latin) is kept as it is.
 *
 * Duplicates are kept: a term's frequency in a field is how many times it
 * comes out of here.
 */
final class Analyzer
{
    public function __construct(
        private readonly RussianStemmer $russian = new RussianStemmer(),
        private readonly EnglishStemmer $english = new EnglishStemmer(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function terms(string $text): array
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            throw new InvalidArgumentException('Text is not valid UTF-8.');
        }

        $lowered = str_replace('ё', 'е', mb_strtolower($normalized, 'UTF-8'));
        $joined = preg_replace("/(?<=\\p{L})['’ʼ](?=\\p{L})/u", '', $lowered) ?? $lowered;

        preg_match_all('/[\p{L}\p{N}]+/u', $joined, $matches);

        return array_map($this->stem(...), $matches[0]);
    }

    private function stem(string $token): string
    {
        if (preg_match('/^\p{Cyrillic}+$/u', $token) === 1) {
            return $this->russian->stem($token);
        }

        if (preg_match('/^[a-z]+$/', $token) === 1) {
            return $this->english->stem($token);
        }

        return $token;
    }
}
