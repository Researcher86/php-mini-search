<?php

declare(strict_types=1);

namespace PhpMiniSearch\Analysis\Snowball;

/**
 * The Snowball English (Porter2) stemmer, ported by hand from
 * `algorithms/english.sbl` at snowballstem/snowball commit
 * 14a4f24e4cf64f1003d1205edc0713b8111a18a8.
 *
 * Porter2, not the original 1980 Porter algorithm: `generously` → `generous`,
 * `dying` → `die`, and words such as `inning` or `proceed` are left alone.
 *
 * The algorithm works on lowercase ASCII letters and the apostrophe, byte by
 * byte. One trick runs through all of it: the prelude marks a consonant `y`
 * as `Y`, so the vowel checks can tell `say` (consonant) from `cry` (vowel)
 * without looking twice; the postlude turns `Y` back into `y`.
 *
 * R1 starts after the first non-vowel that follows a vowel (or after one of
 * a few listed prefixes, so `generous` and `general` share a region); R2 is
 * the R1 of R1. Most endings are removed only when they lie in R1 or R2.
 * Endings are matched longest first, and when the longest ending's condition
 * fails, no shorter ending is tried: that is how Snowball's `among` works.
 *
 * Copyright (c) 2001, Dr Martin Porter; (c) 2004,2005, Richard Boulton;
 * (c) 2013, Yoshiki Shibukawa; (c) 2006-2025, Olly Betts. Ported under the
 * 3-clause BSD license in COPYING next to this file.
 */
final class EnglishStemmer
{
    private const string VOWELS = 'aeiouy';

    /** Words that are stemmed, or kept, as a whole. */
    private const array EXCEPTIONS = [
        'skis' => 'ski', 'skies' => 'sky',
        'idly' => 'idl', 'gently' => 'gentl', 'ugly' => 'ugli', 'early' => 'earli',
        'only' => 'onli', 'singly' => 'singl',
        'sky' => 'sky', 'news' => 'news', 'howe' => 'howe',
        'atlas' => 'atlas', 'cosmos' => 'cosmos', 'bias' => 'bias', 'andes' => 'andes',
    ];

    /** Prefixes after which R1 starts, so related words keep one stem. */
    private const array R1_PREFIXES = ['gener', 'commun', 'arsen', 'past', 'univers', 'later', 'emerg', 'organ', 'inter'];

    private const array STEP2 = [
        'tional' => 'tion', 'enci' => 'ence', 'anci' => 'ance', 'abli' => 'able',
        'entli' => 'ent', 'izer' => 'ize', 'ization' => 'ize', 'ational' => 'ate',
        'ation' => 'ate', 'ator' => 'ate', 'alism' => 'al', 'aliti' => 'al',
        'alli' => 'al', 'fulness' => 'ful', 'ousli' => 'ous', 'ousness' => 'ous',
        'iveness' => 'ive', 'iviti' => 'ive', 'biliti' => 'ble', 'bli' => 'ble',
        'ogist' => 'og', 'ogi' => 'og', 'fulli' => 'ful', 'lessli' => 'less',
        'li' => '',
    ];

    private const array STEP3 = [
        'tional' => 'tion', 'ational' => 'ate', 'alize' => 'al', 'icate' => 'ic',
        'iciti' => 'ic', 'ical' => 'ic', 'ful' => '', 'ness' => '', 'ative' => '',
    ];

    private const array STEP4 = [
        'al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 'ement', 'ment',
        'ent', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize', 'ion',
    ];

    /** Letters that may precede a removable -li. */
    private const string VALID_LI = 'cdeghkmnrt';

    private const array DOUBLES = ['bb', 'dd', 'ff', 'gg', 'mm', 'nn', 'pp', 'rr', 'tt'];

    public function stem(string $word): string
    {
        if (isset(self::EXCEPTIONS[$word])) {
            return self::EXCEPTIONS[$word];
        }

        if (strlen($word) < 3) {
            return $word;
        }

        [$word, $yFound] = $this->prelude($word);
        [$r1, $r2] = $this->regions($word);

        $word = $this->step1a($word);
        $word = $this->step1b($word, $r1);
        $word = $this->step1c($word);
        $word = $this->step2($word, $r1);
        $word = $this->step3($word, $r1, $r2);
        $word = $this->step4($word, $r2);
        $word = $this->step5($word, $r1, $r2);

        return $yFound ? str_replace('Y', 'y', $word) : $word;
    }

    /**
     * Drops a leading apostrophe and marks every consonant y as Y: an initial
     * y, and a y right after a vowel.
     *
     * @return array{string, bool} the word, and whether any Y was marked
     */
    private function prelude(string $word): array
    {
        if (str_starts_with($word, "'")) {
            $word = substr($word, 1);
        }

        $yFound = false;
        for ($i = 0, $length = strlen($word); $i < $length; $i++) {
            if ($word[$i] === 'y' && ($i === 0 || $this->isVowel($word[$i - 1]))) {
                $word[$i] = 'Y';
                $yFound = true;
            }
        }

        return [$word, $yFound];
    }

    /**
     * @return array{int, int} [R1, R2] as byte offsets; a missing region starts at the end
     */
    private function regions(string $word): array
    {
        $length = strlen($word);

        $r1 = null;
        foreach (self::R1_PREFIXES as $prefix) {
            if (str_starts_with($word, $prefix)) {
                $r1 = strlen($prefix);
                break;
            }
        }
        $r1 ??= $this->afterVowelThenConsonant($word, 0);
        if ($r1 === null) {
            return [$length, $length];
        }

        return [$r1, $this->afterVowelThenConsonant($word, $r1) ?? $length];
    }

    /** The offset just past the first non-vowel that follows a vowel, searching from $from. */
    private function afterVowelThenConsonant(string $word, int $from): ?int
    {
        $length = strlen($word);
        $i = $from;

        while ($i < $length && !$this->isVowel($word[$i])) {
            $i++;
        }
        while ($i < $length && $this->isVowel($word[$i])) {
            $i++;
        }

        return $i < $length ? $i + 1 : null;
    }

    private function step1a(string $word): string
    {
        $apostrophe = $this->longest($word, ["'s'", "'s", "'"]);
        if ($apostrophe !== null) {
            $word = $this->cut($word, $apostrophe);
        }

        $ending = $this->longest($word, ['sses', 'ied', 'ies', 'us', 'ss', 's']);
        $stem = $ending === null ? $word : $this->cut($word, $ending);

        return match ($ending) {
            'sses' => $stem . 'ss',
            // ties → tie, but cries → cri
            'ied', 'ies' => $stem . (strlen($stem) > 1 ? 'i' : 'ie'),
            // gaps → gap, but gas stays: the vowel may not be the letter right before the s
            's' => $this->hasVowel(substr($stem, 0, -1)) ? $stem : $word,
            default => $word,
        };
    }

    private function step1b(string $word, int $r1): string
    {
        $ending = $this->longest($word, ['eedly', 'ingly', 'edly', 'eed', 'ing', 'ed']);
        if ($ending === null) {
            return $word;
        }
        $stem = $this->cut($word, $ending);

        if ($ending === 'eed' || $ending === 'eedly') {
            if (strlen($stem) < $r1 || in_array($stem, ['proc', 'exc', 'succ'], true)) {
                return $word;
            }

            return $stem . 'ee';
        }

        if ($ending === 'ing') {
            // dying → die, lying → lie, tying → tie
            if (strlen($stem) === 2 && $stem[1] === 'y' && !$this->isVowel($stem[0])) {
                return $stem[0] . 'ie';
            }
            // inning, outing, canning, herring, earring, evening stay whole
            if (in_array($stem, ['inn', 'out', 'cann', 'herr', 'earr', 'even'], true)) {
                return $word;
            }
        }

        if (!$this->hasVowel($stem)) {
            return $word;
        }

        $last2 = substr($stem, -2);
        if (in_array($last2, ['at', 'bl', 'iz'], true)) {
            return $stem . 'e';
        }
        if (in_array($last2, self::DOUBLES, true)) {
            // hopping → hop, but added → add
            $keepDouble = strlen($stem) === 3 && str_contains('aeo', $stem[0]);

            return $keepDouble ? $stem : substr($stem, 0, -1);
        }
        if (strlen($stem) === $r1 && $this->isShort($stem, strlen($stem))) {
            return $stem . 'e';
        }

        return $stem;
    }

    /** cry → cri, but by and say stay: the y must follow a consonant that is not the first letter. */
    private function step1c(string $word): string
    {
        $length = strlen($word);
        $last = $word[$length - 1] ?? '';

        if (($last === 'y' || $last === 'Y') && $length > 2 && !$this->isVowel($word[$length - 2])) {
            return substr($word, 0, -1) . 'i';
        }

        return $word;
    }

    private function step2(string $word, int $r1): string
    {
        $ending = $this->longest($word, array_keys(self::STEP2));
        if ($ending === null || $this->start($word, $ending) < $r1) {
            return $word;
        }
        $before = $word[$this->start($word, $ending) - 1] ?? '';

        if ($ending === 'ogi' && $before !== 'l') {
            return $word;
        }
        if ($ending === 'li' && ($before === '' || !str_contains(self::VALID_LI, $before))) {
            return $word;
        }

        return $this->cut($word, $ending) . self::STEP2[$ending];
    }

    private function step3(string $word, int $r1, int $r2): string
    {
        $ending = $this->longest($word, array_keys(self::STEP3));
        if ($ending === null || $this->start($word, $ending) < $r1) {
            return $word;
        }
        if ($ending === 'ative' && $this->start($word, $ending) < $r2) {
            return $word;
        }

        return $this->cut($word, $ending) . self::STEP3[$ending];
    }

    private function step4(string $word, int $r2): string
    {
        $ending = $this->longest($word, self::STEP4);
        if ($ending === null || $this->start($word, $ending) < $r2) {
            return $word;
        }
        if ($ending === 'ion' && !in_array($word[$this->start($word, $ending) - 1] ?? '', ['s', 't'], true)) {
            return $word;
        }

        return $this->cut($word, $ending);
    }

    private function step5(string $word, int $r1, int $r2): string
    {
        $start = strlen($word) - 1;
        $last = $word[$start] ?? '';

        if ($last === 'e' && ($start >= $r2 || ($start >= $r1 && !$this->isShort($word, $start)))) {
            return substr($word, 0, -1);
        }
        if ($last === 'l' && $start >= $r2 && ($word[$start - 1] ?? '') === 'l') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Whether the letters before offset $end end in a short syllable: a vowel
     * between non-vowels where the last is not w, x or Y (`hop`), or a vowel
     * then a non-vowel at the start of the word (`ow`). `past` counts too, so
     * that pasted and pasting both become paste.
     */
    private function isShort(string $word, int $end): bool
    {
        if ($end >= 3
            && !$this->isVowel($word[$end - 1]) && !str_contains('wxY', $word[$end - 1])
            && $this->isVowel($word[$end - 2])
            && !$this->isVowel($word[$end - 3])
        ) {
            return true;
        }

        if ($end === 2 && $this->isVowel($word[0]) && !$this->isVowel($word[1])) {
            return true;
        }

        return $end >= 4 && substr($word, $end - 4, 4) === 'past';
    }

    /**
     * @param list<string> $endings
     */
    private function longest(string $word, array $endings): ?string
    {
        $longest = null;

        foreach ($endings as $ending) {
            if (str_ends_with($word, $ending) && ($longest === null || strlen($ending) > strlen($longest))) {
                $longest = $ending;
            }
        }

        return $longest;
    }

    private function start(string $word, string $ending): int
    {
        return strlen($word) - strlen($ending);
    }

    private function cut(string $word, string $ending): string
    {
        return substr($word, 0, strlen($word) - strlen($ending));
    }

    private function isVowel(string $char): bool
    {
        return $char !== '' && str_contains(self::VOWELS, $char);
    }

    private function hasVowel(string $part): bool
    {
        return strpbrk($part, self::VOWELS) !== false;
    }
}
