<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Analysis;

use InvalidArgumentException;
use PhpMiniSearch\Analysis\Analyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer();
    }

    public function testEmptyAndPunctuationOnlyTextHasNoTerms(): void
    {
        $this->assertSame([], $this->analyzer->terms(''));
        $this->assertSame([], $this->analyzer->terms("  !!! -- ... \n\t"));
    }

    public function testRepeatedWordsKeepEveryOccurrence(): void
    {
        $this->assertSame(['кабел', 'кабел', 'кабел'], $this->analyzer->terms('кабель, кабели, кабелей'));
    }

    public function testCaseDoesNotMatter(): void
    {
        $this->assertSame($this->analyzer->terms('кабель usb'), $this->analyzer->terms('КАБЕЛЬ USB'));
    }

    public function testYoIsTheSameLetterAsYe(): void
    {
        $this->assertSame($this->analyzer->terms('ежик зеленый'), $this->analyzer->terms('Ёжик зелёный'));
    }

    /**
     * NFKC folds compatibility forms into the characters people type.
     */
    #[DataProvider('compatibilityForms')]
    public function testCompatibilityFormsMatchWhatPeopleType(string $stored, string $typed): void
    {
        $this->assertSame($this->analyzer->terms($typed), $this->analyzer->terms($stored));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compatibilityForms(): iterable
    {
        yield 'full-width Latin' => ['ＵＳＢ кабель', 'usb кабель'];
        yield 'ligature' => ['ﬁle', 'file'];
        yield 'й as и + combining breve' => ["бели\u{0306}", 'белый'];
        yield 'ё as е + combining diaeresis' => ["зеле\u{0308}ный", 'зеленый'];
    }

    public function testNumbersAreTermsAndAreNotStemmed(): void
    {
        $this->assertSame(['usb', 'c', '3', '1', '2', '0'], $this->analyzer->terms('USB-C 3.1, 2 × 0'));
    }

    public function testEachScriptGoesToItsOwnStemmerAndMixedTokensAreKept(): void
    {
        $this->assertSame(
            ['кабел', 'cabl', 'usb3', 'café', '東京'],
            $this->analyzer->terms('кабели cables usb3 café 東京'),
        );
    }

    /**
     * The table from docs/DECISIONS.md: with or without the apostrophe, in
     * any of its three forms, a brand or surname finds the same document.
     */
    #[DataProvider('apostrophes')]
    public function testAnApostropheInsideAWordDoesNotMatter(string $stored, string $typed): void
    {
        $this->assertSame($this->analyzer->terms($typed), $this->analyzer->terms($stored));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function apostrophes(): iterable
    {
        yield "Levi's → levis" => ["Levi's", 'levis'];
        yield "Levi's → levi" => ["Levi's", 'levi'];
        yield 'O’Neill → oneill' => ['O’Neill', 'oneill'];
        yield "O’Neill → o'neill" => ['O’Neill', "o'neill"];
        yield 'д’Артаньян → дартаньян' => ['д’Артаньян', 'дартаньян'];
        yield 'modifier apostrophe' => ['Oʼneill', 'oneill'];
    }

    public function testAnApostropheAtTheEdgeOfAWordSeparates(): void
    {
        $this->assertSame(['quot'], $this->analyzer->terms("'quoted'"));
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->analyzer->terms("\xC3\x28");
    }
}
