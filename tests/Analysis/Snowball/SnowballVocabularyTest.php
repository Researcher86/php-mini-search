<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Analysis\Snowball;

use PhpMiniSearch\Analysis\Snowball\EnglishStemmer;
use PhpMiniSearch\Analysis\Snowball\RussianStemmer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The hand-written stemmers against the official Snowball vocabularies:
 * about 49,800 Russian and 42,600 English word → stem pairs, copied from the
 * same upstream point in time as the algorithms (tests/Fixtures/snowball).
 *
 * One test per language rather than one per word: a data provider of 92,000
 * cases would spend more time in PHPUnit than in the stemmer. A failure lists
 * the first mismatches, which is what is needed to find the broken rule.
 */
final class SnowballVocabularyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RussianStemmer|EnglishStemmer}>
     */
    public static function languages(): iterable
    {
        yield 'russian' => ['russian', new RussianStemmer()];
        yield 'english' => ['english', new EnglishStemmer()];
    }

    #[DataProvider('languages')]
    public function testEveryWordInTheVocabularyGetsTheReferenceStem(string $language, RussianStemmer|EnglishStemmer $stemmer): void
    {
        $words = $this->lines("{$language}/voc.txt");
        $stems = $this->lines("{$language}/output.txt");
        $this->assertCount(count($words), $stems);

        $mismatches = [];
        foreach ($words as $i => $word) {
            $actual = $stemmer->stem($word);
            if ($actual !== $stems[$i]) {
                $mismatches[] = "{$word} → {$actual}, expected {$stems[$i]}";
            }
        }

        $this->assertSame(
            [],
            array_slice($mismatches, 0, 20),
            sprintf('%d of %d words stem differently', count($mismatches), count($words)),
        );
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        $lines = file(__DIR__ . '/../../Fixtures/snowball/' . $path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read fixture {$path}");
        }

        return $lines;
    }
}
