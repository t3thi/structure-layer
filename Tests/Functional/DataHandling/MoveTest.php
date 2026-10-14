<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenario 6 (component D): a move always moves the element in all languages.
 */
final class MoveTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function movingATranslationMovesItsShadowAndAllLanguages(): void
    {
        // "Element 3 (de only)" after "Element 1 (de)"
        $this->processCmdmap(['tt_content' => [121 => ['move' => -101]]]);
        self::assertSame([100, 120, 110, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function movingATranslationAfterATranslationOfAnotherLanguageUsesTheTargetShadow(): void
    {
        // "Element 1 (en)" after "Element 2 (de)"
        $this->processCmdmap(['tt_content' => [102 => ['move' => -111]]]);
        self::assertSame([110, 100, 120, 130], $this->fetchStructureOrder(1));
        self::assertSame(2, $this->fetchRecord('tt_content', 102)['sys_language_uid']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function movingATranslationToAnotherPageMovesAllLanguages(): void
    {
        $this->processCmdmap(['tt_content' => [111 => ['move' => 10]]]);
        self::assertSame(10, $this->fetchRecord('tt_content', 110)['pid']);
        foreach ($this->fetchTranslations('tt_content', 110) as $languageId => $translation) {
            self::assertSame(10, $translation['pid'], 'language ' . $languageId);
        }
        self::assertSame([110, 200], $this->fetchStructureOrder(10));
        self::assertSame([100, 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function dragAndDropMovesTheShadowAppliesColPosToAllLanguagesAndKeepsTheLanguage(): void
    {
        // Layout module drag & drop of "Element 1 (de)" into the English column 1, after "Element 2 (en)"
        $this->processCmdmap(['tt_content' => [101 => ['move' => [
            'action' => 'paste',
            'target' => -112,
            'update' => ['colPos' => 1, 'sys_language_uid' => 2],
        ]]]]);
        self::assertSame([110, 100, 120, 130], $this->fetchStructureOrder(1));
        self::assertSame(1, $this->fetchRecord('tt_content', 100)['colPos']);
        $translations = $this->fetchTranslations('tt_content', 100);
        self::assertSame([1, 2], array_keys($translations), 'language change is rejected');
        foreach ($translations as $languageId => $translation) {
            self::assertSame(1, $translation['colPos'], 'colPos in language ' . $languageId);
        }
        $this->assertInvariantsHold();
    }

    #[Test]
    public function movingAnAllLanguagesRecordChangesOnlyItsOwnSortingAndNeverItsLanguage(): void
    {
        $this->processCmdmap(['tt_content' => [130 => ['move' => [
            'action' => 'paste',
            'target' => -101,
            'update' => ['colPos' => 0, 'sys_language_uid' => 1],
        ]]]]);
        self::assertSame([100, 130, 110, 120], $this->fetchStructureOrder(1));
        self::assertSame(-1, $this->fetchRecord('tt_content', 130)['sys_language_uid']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function movingAPageTranslationMovesThePageInAllLanguages(): void
    {
        // "Seite B" before "Seite A": into page 1 at the top
        $this->processCmdmap(['pages' => [21 => ['move' => 1]]]);
        self::assertSame([20, 10], $this->fetchStructureOrder(1, 'pages'));
        $this->assertInvariantsHold();
    }
}
