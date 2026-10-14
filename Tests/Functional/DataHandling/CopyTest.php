<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenario 9: copies keep shadows and translations consistent.
 */
final class CopyTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function copyingAShadowToAnotherPageCopiesAllLanguages(): void
    {
        $dataHandler = $this->processCmdmap(['tt_content' => [100 => ['copy' => 10]]]);
        $newShadowUid = (int)$dataHandler->copyMappingArray_merged['tt_content'][100];
        self::assertSame([$newShadowUid, 200], $this->fetchStructureOrder(10));
        self::assertSame([1, 2], array_keys($this->fetchTranslations('tt_content', $newShadowUid)));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function copyingATranslationCopiesTheElementInAllLanguages(): void
    {
        $dataHandler = $this->processCmdmap(['tt_content' => [101 => ['copy' => -200]]]);
        $newShadowUid = (int)$dataHandler->copyMappingArray_merged['tt_content'][100];
        self::assertSame([200, $newShadowUid], $this->fetchStructureOrder(10));
        $translations = $this->fetchTranslations('tt_content', $newShadowUid);
        self::assertSame([1, 2], array_keys($translations));
        self::assertSame('', $this->fetchRecord('tt_content', $newShadowUid)['header']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function copyingAPageCopiesShadowsAndTranslations(): void
    {
        $dataHandler = $this->processCmdmap(['pages' => [10 => ['copy' => -20]]]);
        $newPageUid = (int)$dataHandler->copyMappingArray_merged['pages'][10];
        self::assertSame([10, 20, $newPageUid], $this->fetchStructureOrder(1, 'pages'));
        self::assertSame([1, 2], array_keys($this->fetchTranslations('pages', $newPageUid)));
        $contentShadows = $this->fetchStructureOrder($newPageUid);
        self::assertCount(1, $contentShadows);
        self::assertSame([1, 2], array_keys($this->fetchTranslations('tt_content', $contentShadows[0])));
        $this->assertInvariantsHold();
    }
}
