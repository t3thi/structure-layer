<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 6 (plan §5, 2026-08-14: "expose rather than silently preserve"): characterization only.
 *
 * DataHandler fills l10n_diffsource with the values of the l10n_parent record whenever a translation
 * is saved, and FormEngine compares that snapshot with the current l10n_parent record to show
 * "the default language changed". With the structure layer, l10n_parent is the empty shadow: the
 * snapshot is always empty, and a change of the record actually translated from (l10n_source) is
 * never shown.
 */
final class DiffSourceCharacterizationTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function diffSourceSnapshotsTheEmptyShadowNotTheTranslationSource(): void
    {
        // 121 (de) is the only translation of 120; translate it to en and save the translation
        $dataHandler = $this->processCmdmap(['tt_content' => [121 => ['localize' => 2]]]);
        $englishUid = (int)$dataHandler->copyMappingArray_merged['tt_content'][121];
        $this->processDatamap(['tt_content' => [$englishUid => ['header' => 'Element 3 (en)']]]);

        $english = $this->fetchRecord('tt_content', $englishUid);
        self::assertSame(121, (int)$english['l10n_source']);
        $snapshot = json_decode((string)$english['l18n_diffsource'], true);
        self::assertSame('', $snapshot['header'] ?? null, 'snapshot of the shadow, not of the German source');

        // The German source changes; the comparison base (the shadow) does not
        $this->processDatamap(['tt_content' => [121 => ['header' => 'Element 3 (de, changed)']]]);
        self::assertSame('', $this->fetchRecord('tt_content', 120)['header']);
        self::assertSame($snapshot['header'], $this->fetchRecord('tt_content', 120)['header'], 'FormEngine sees no change');
    }
}
