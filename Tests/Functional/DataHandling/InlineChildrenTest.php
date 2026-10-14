<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenario 10 (characterization, plan §4 B): inline children of content created in one
 * language stay language-scoped to the translation. They get no shadow in v1.
 */
final class InlineChildrenTest extends AbstractDataHandlingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysFile.csv');
    }

    #[Test]
    public function fileReferencesOfContentCreatedInOneLanguageBelongToTheTranslation(): void
    {
        $dataHandler = $this->processDatamap([
            'sys_file_reference' => [
                // FormEngine submits the language of the parent record for new inline children
                'NEWref' => ['pid' => 1, 'sys_language_uid' => 2, 'uid_local' => 'sys_file_1', 'title' => 'Image (en)'],
            ],
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'image', 'header' => 'Image (en)', 'image' => 'NEWref'],
            ],
        ]);
        $translation = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        $shadow = $this->fetchRecord('tt_content', (int)$translation['l18n_parent']);
        $reference = $this->fetchRecord('sys_file_reference', $dataHandler->substNEWwithIDs['NEWref']);

        self::assertSame(1, $translation['image']);
        self::assertSame(0, $shadow['image'], 'the shadow has no file reference');
        self::assertSame($translation['uid'], $reference['uid_foreign']);
        self::assertSame(2, $reference['sys_language_uid']);
        self::assertSame(0, $reference['l10n_parent'], 'no shadow for inline children');
        $this->assertInvariantsHold();
    }
}
