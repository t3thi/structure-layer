<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenarios 4 and 5 (plan §3.3): structural fields live on the shadow and are edited from
 * any language; synchronizable page fields keep their per-language values.
 */
final class FieldPartitionTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function structuralContentFieldsEditedInATranslationAreWrittenToTheShadow(): void
    {
        $this->processDatamap([
            'tt_content' => [
                101 => ['colPos' => 1, 'CType' => 'header', 'fe_group' => '-2', 'header' => 'Element 1 (de, changed)'],
            ],
        ]);
        $shadow = $this->fetchRecord('tt_content', 100);
        self::assertSame(1, $shadow['colPos']);
        self::assertSame('header', $shadow['CType']);
        self::assertSame('-2', $shadow['fe_group'], 'fe_group is structural (l10n_mode=exclude by the extension)');
        self::assertSame('', $shadow['header'], 'payload stays on the translation');
        foreach ($this->fetchTranslations('tt_content', 100) as $languageId => $translation) {
            self::assertSame(1, $translation['colPos'], 'colPos in language ' . $languageId);
            self::assertSame('header', $translation['CType'], 'CType in language ' . $languageId);
            self::assertSame('-2', $translation['fe_group'], 'fe_group in language ' . $languageId);
        }
        self::assertSame('Element 1 (de, changed)', $this->fetchRecord('tt_content', 101)['header']);
        self::assertSame('Element 1 (en)', $this->fetchRecord('tt_content', 102)['header']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function structuralPageFieldsEditedInATranslationAreWrittenToTheShadow(): void
    {
        $this->processDatamap([
            'pages' => [
                11 => ['doktype' => 254, 'fe_group' => '-2'],
            ],
        ]);
        self::assertSame(254, $this->fetchRecord('pages', 10)['doktype'], 'doktype is structural (l10n_mode=exclude by the extension)');
        self::assertSame('-2', $this->fetchRecord('pages', 10)['fe_group']);
        foreach ($this->fetchTranslations('pages', 10) as $languageId => $translation) {
            self::assertSame(254, $translation['doktype'], 'doktype in language ' . $languageId);
            self::assertSame('-2', $translation['fe_group'], 'fe_group in language ' . $languageId);
        }
        $this->assertInvariantsHold();
    }

    #[Test]
    public function synchronizablePageFieldsSurviveAStructuralEdit(): void
    {
        // Fixture pages 11/12 have nav_hide=1 and no l10n_state: DataMapProcessor would treat
        // nav_hide as "parent" and copy the empty shadow value on the next shadow update.
        $this->processDatamap([
            'pages' => [
                11 => ['layout' => 1],
            ],
        ]);
        self::assertSame(1, $this->fetchRecord('pages', 10)['layout']);
        self::assertSame(0, $this->fetchRecord('pages', 10)['nav_hide']);
        self::assertSame(1, $this->fetchRecord('pages', 11)['nav_hide']);
        self::assertSame(1, $this->fetchRecord('pages', 12)['nav_hide']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function synchronizablePageFieldsArePerLanguageAfterTranslate(): void
    {
        // Translate "Seite B" (de) to English, then change nav_hide in German only
        $this->processCmdmap(['pages' => [21 => ['localize' => 2]]]);
        $this->processDatamap(['pages' => [21 => ['nav_hide' => 1]]]);

        $english = $this->fetchTranslations('pages', 20)[2];
        self::assertSame(20, $english['l10n_parent']);
        self::assertSame(21, $english['l10n_source']);
        self::assertSame(0, $english['nav_hide']);
        self::assertSame(1, $this->fetchRecord('pages', 21)['nav_hide']);
        self::assertSame(0, $this->fetchRecord('pages', 20)['nav_hide']);
        $state = json_decode((string)$english['l10n_state'], true);
        self::assertIsArray($state);
        self::assertSame('custom', $state['nav_hide'] ?? null);
        $this->assertInvariantsHold();
    }
}
