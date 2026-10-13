<?php

declare(strict_types=1);

namespace PhpMiniSearch\Analysis\Snowball;

/**
 * The Snowball Russian stemmer, ported by hand from `algorithms/russian.sbl`
 * at snowballstem/snowball commit 14a4f24e4cf64f1003d1205edc0713b8111a18a8.
 *
 * Stemming is what lets the query `сокет` find a document that says
 * `сокетов`: both become `сокет`. A Russian noun has about a dozen forms, and
 * with AND semantics a query that misses one form misses the document.
 *
 * The algorithm only ever removes endings, and only inside RV — the part of
 * the word after its first vowel. Everything before RV is never looked at,
 * so a short word like `сон` keeps its stem whole. Endings are tried longest
 * first, exactly like Snowball's `among`: once the longest matching ending is
 * found, only its rule is applied, and a shorter ending is never tried
 * instead, even when that rule's condition fails.
 *
 * Input must be lowercase. `ё` is folded to `е` here as upstream does, though
 * the analyzer has already done it.
 *
 * Positions (RV, R2) are counted in characters, while suffixes are compared
 * and cut as UTF-8 bytes: a complete UTF-8 suffix can only match on a
 * character boundary, so the two never disagree.
 *
 * Copyright (c) 2001, Dr Martin Porter; (c) 2004,2005, Richard Boulton;
 * (c) 2013, Yoshiki Shibukawa; (c) 2006-2025, Olly Betts. Ported under the
 * 3-clause BSD license in COPYING next to this file.
 */
final class RussianStemmer
{
    private const string VOWELS = 'аеиоуыэюя';

    /** Endings removed only after `а` or `я`, which stays. */
    private const array GERUND_AFTER_A = ['в', 'вши', 'вшись'];

    private const array GERUND = ['ив', 'ивши', 'ившись', 'ыв', 'ывши', 'ывшись'];

    private const array ADJECTIVE = [
        'ее', 'ие', 'ые', 'ое', 'ими', 'ыми', 'ей', 'ий', 'ый', 'ой', 'ем', 'им',
        'ым', 'ом', 'его', 'ого', 'ему', 'ому', 'их', 'ых', 'ую', 'юю', 'ая',
        'яя', 'ою', 'ею',
    ];

    private const array PARTICIPLE_AFTER_A = ['ем', 'нн', 'вш', 'ющ', 'щ'];

    private const array PARTICIPLE = ['ивш', 'ывш', 'ующ'];

    private const array REFLEXIVE = ['ся', 'сь'];

    private const array VERB_AFTER_A = [
        'ла', 'на', 'ете', 'йте', 'ли', 'й', 'л', 'ем', 'н', 'ло', 'но', 'ет',
        'ют', 'ны', 'ть', 'ешь', 'нно',
    ];

    private const array VERB = [
        'ила', 'ыла', 'ена', 'ейте', 'уйте', 'ите', 'или', 'ыли', 'ей', 'уй',
        'ил', 'ыл', 'им', 'ым', 'ен', 'ило', 'ыло', 'ено', 'ят', 'ует', 'уют',
        'ит', 'ыт', 'ены', 'ить', 'ыть', 'ишь', 'ую', 'ю',
    ];

    private const array NOUN = [
        'а', 'ев', 'ов', 'ие', 'ье', 'е', 'иями', 'ями', 'ами', 'еи', 'ии', 'и',
        'ией', 'ей', 'ой', 'ий', 'й', 'иям', 'ям', 'ием', 'ем', 'ам', 'ом', 'о',
        'у', 'ах', 'иях', 'ях', 'ы', 'ь', 'ию', 'ью', 'ю', 'ия', 'ья', 'я',
    ];

    private const array DERIVATIONAL = ['ост', 'ость'];

    private const array SUPERLATIVE = ['ейш', 'ейше'];

    public function stem(string $word): string
    {
        $word = str_replace('ё', 'е', $word);
        [$rv, $r2] = $this->regions($word);

        // Step 1: a perfective gerund, or else an optional reflexive ending
        // followed by an adjectival, verb or noun ending.
        if (!$this->removeEnding($word, $rv, self::GERUND_AFTER_A, self::GERUND)) {
            $this->removeEnding($word, $rv, [], self::REFLEXIVE);

            if (!$this->removeAdjectival($word, $rv)
                && !$this->removeEnding($word, $rv, self::VERB_AFTER_A, self::VERB)
            ) {
                $this->removeEnding($word, $rv, [], self::NOUN);
            }
        }

        // Step 2: a final и (the noun ending -ию is treated as the verb ending -ю).
        $this->removeEnding($word, $rv, [], ['и']);

        // Step 3: the derivational ending -ост(ь), only when it lies in R2.
        $derivational = $this->longestEnding($word, $rv, self::DERIVATIONAL);
        if ($derivational !== null && $this->start($word, $derivational) >= $r2) {
            $word = $this->cut($word, $derivational);
        }

        // Step 4: a superlative ending (then нн → н), or нн → н, or a final ь.
        $this->tidyUp($word, $rv);

        return $word;
    }

    /**
     * RV starts after the first vowel. R1 starts after the first non-vowel
     * that follows a vowel, and R2 is the R1 of R1. A region that does not
     * exist starts at the end of the word.
     *
     * @return array{int, int} [RV, R2] as character offsets
     */
    private function regions(string $word): array
    {
        $chars = mb_str_split($word);
        $length = count($chars);
        $isVowel = fn (int $i): bool => str_contains(self::VOWELS, $chars[$i]);

        // Each step is one Snowball `gopast`: move to just past the first
        // character of the wanted kind, or give up at the end of the word.
        $gopast = function (int $from, bool $vowel) use ($length, $isVowel): ?int {
            for ($i = $from; $i < $length; $i++) {
                if ($isVowel($i) === $vowel) {
                    return $i + 1;
                }
            }

            return null;
        };

        $rv = $gopast(0, true);
        if ($rv === null) {
            return [$length, $length];
        }

        $r1 = $gopast($rv, false);
        $afterVowel = $r1 === null ? null : $gopast($r1, true);
        $r2 = $afterVowel === null ? null : $gopast($afterVowel, false);

        return [$rv, $r2 ?? $length];
    }

    /**
     * Removes the longest matching ending inside RV. An ending from the first
     * list is removed only when `а` or `я` (also inside RV) precedes it; if
     * that check fails, nothing is removed.
     *
     * @param list<string> $afterA endings that need a preceding а or я
     * @param list<string> $plain endings removed unconditionally
     */
    private function removeEnding(string &$word, int $rv, array $afterA, array $plain): bool
    {
        $ending = $this->longestEnding($word, $rv, [...$afterA, ...$plain]);
        if ($ending === null) {
            return false;
        }

        if (in_array($ending, $afterA, true)) {
            $before = $this->start($word, $ending) - 1;
            if ($before < $rv || !in_array(mb_substr($word, $before, 1), ['а', 'я'], true)) {
                return false;
            }
        }

        $word = $this->cut($word, $ending);

        return true;
    }

    /**
     * An adjective ending, then optionally a participle ending in front of it
     * (`-ующий` loses both `-ий` and `-ующ`).
     */
    private function removeAdjectival(string &$word, int $rv): bool
    {
        if (!$this->removeEnding($word, $rv, [], self::ADJECTIVE)) {
            return false;
        }

        $this->removeEnding($word, $rv, self::PARTICIPLE_AFTER_A, self::PARTICIPLE);

        return true;
    }

    private function tidyUp(string &$word, int $rv): void
    {
        $ending = $this->longestEnding($word, $rv, [...self::SUPERLATIVE, 'н', 'ь']);

        if ($ending === null) {
            return;
        }

        if ($ending === 'ь') {
            $word = $this->cut($word, 'ь');

            return;
        }

        if (in_array($ending, self::SUPERLATIVE, true)) {
            $word = $this->cut($word, $ending);
        }

        // -нн → -н, after a superlative or on its own (-нно endings).
        if ($this->longestEnding($word, $rv, ['нн']) !== null) {
            $word = $this->cut($word, 'н');
        }
    }

    /**
     * @param list<string> $endings
     */
    private function longestEnding(string $word, int $rv, array $endings): ?string
    {
        $longest = null;

        foreach ($endings as $ending) {
            if (str_ends_with($word, $ending)
                && $this->start($word, $ending) >= $rv
                && ($longest === null || strlen($ending) > strlen($longest))
            ) {
                $longest = $ending;
            }
        }

        return $longest;
    }

    /** The character offset at which $ending starts in $word. */
    private function start(string $word, string $ending): int
    {
        return mb_strlen($word) - mb_strlen($ending);
    }

    private function cut(string $word, string $ending): string
    {
        return substr($word, 0, strlen($word) - strlen($ending));
    }
}
