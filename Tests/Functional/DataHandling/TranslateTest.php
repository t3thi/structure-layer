<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenario 3 (component F): translating from any language connects to the shadow.
 */
final class TranslateTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function translatingFromAnotherLanguageConnectsToTheShadow(): void
    {
        $this->processCmdmap(['tt_content' => [121 => ['localize' => 2]]]);
        $translations = $this->fetchTranslations('tt_content', 120);
        self::assertSame([1, 2], array_keys($translations));
        self::assertSame(121, $translations[2]['l10n_source'], 'content origin = German record');
        self::assertSame(1, $translations[2]['pid']);
        self::assertSame($this->fetchRecord('tt_content', 120)['sorting'], $translations[2]['sorting']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function translatingAlongAChainKeepsTheShadowAsParent(): void
    {
        $this->processCmdmap(['tt_content' => [102 => ['localize' => 3]]]);
        $translations = $this->fetchTranslations('tt_content', 100);
        self::assertSame([1, 2, 3], array_keys($translations));
        self::assertSame(102, $translations[3]['l10n_source']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function translatingIntoALanguageThatAlreadyHasTheElementCreatesNoDuplicate(): void
    {
        // German "Element 1" exists (created directly, l10n_source = 0). Translating the English
        // record to German must be rejected instead of creating a second German record (I3).
        $dataHandler = $this->processCmdmap(['tt_content' => [102 => ['localize' => 1]]], true);
        self::assertNotSame([], $dataHandler->errorLog, 'localize is rejected');
        self::assertSame(101, $this->fetchTranslations('tt_content', 100)[1]['uid']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function translatingAPageFromAnotherLanguageConnectsToTheShadow(): void
    {
        $this->processCmdmap(['pages' => [12 => ['localize' => 3]]]);
        $translations = $this->fetchTranslations('pages', 10);
        self::assertSame([1, 2, 3], array_keys($translations));
        self::assertSame(12, $translations[3]['l10n_source']);
        self::assertStringEndsWith('Page A', $translations[3]['title'], 'title of the source, with Core\'s "[Translate to …]" prefix');
        // localizePage() copies only "prefixLangTitle" fields: nav_hide is not taken over from the source,
        // and the empty shadow must not provide it either
        self::assertSame(0, $translations[3]['nav_hide']);
        $this->assertInvariantsHold();
    }
}
