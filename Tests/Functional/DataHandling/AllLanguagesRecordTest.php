<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 6 (plan §5): "-1" records stay single records without a shadow (plan non-goals) – the
 * paths Phases 2 and 3 did not cover: copy and cut/paste to another page, workspaces.
 *
 * Data: StructureData.csv, "-1" element 130 on page 1; page 10 has 200 (de 201, en 202).
 */
final class AllLanguagesRecordTest extends AbstractDataHandlingTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    #[Test]
    public function copyToAnotherPageCreatesOneRecordWithoutShadow(): void
    {
        $dataHandler = $this->processCmdmap(['tt_content' => [130 => ['copy' => -200]]]);

        $copy = $this->fetchRecord('tt_content', (int)$dataHandler->copyMappingArray_merged['tt_content'][130]);
        self::assertSame([10, -1, 0], [(int)$copy['pid'], (int)$copy['sys_language_uid'], (int)$copy['l18n_parent']]);
        self::assertSame([200, (int)$copy['uid']], $this->fetchStructureOrder(10));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function cutAndPasteFromALanguageColumnKeepsTheLanguage(): void
    {
        // Paste into the English column of page 10: Core's paste sends sys_language_uid of the column
        $this->processCmdmap(['tt_content' => [130 => ['move' => ['action' => 'paste', 'target' => -202, 'update' => ['colPos' => 0, 'sys_language_uid' => 2]]]]]);

        $record = $this->fetchRecord('tt_content', 130);
        self::assertSame([10, -1], [(int)$record['pid'], (int)$record['sys_language_uid']]);
        self::assertSame([200, 130], $this->fetchStructureOrder(10));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function workspaceEditCreatesOneVersionAndPublishes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Workspaces/Fixtures/Workspace.csv');
        $GLOBALS['BE_USER']->setWorkspace(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [130 => ['header' => 'Element 4 (all languages, ws)', 'hidden' => 1]]], []);
        $dataHandler->process_datamap();
        $GLOBALS['BE_USER']->setWorkspace(0);
        self::assertSame([], $dataHandler->errorLog);
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $versions = array_map(intval(...), $queryBuilder->select('uid')->from('tt_content')->where('t3ver_wsid = 1')->executeQuery()->fetchFirstColumn());
        self::assertCount(1, $versions, 'one version, no shadow, no copies per language');
        $version = $versions[0];
        $this->processCmdmap(['tt_content' => [130 => ['version' => ['action' => 'publish', 'swapWith' => $version]]]]);

        $record = $this->fetchRecord('tt_content', 130);
        self::assertSame(['Element 4 (all languages, ws)', 1, -1], [$record['header'], (int)$record['hidden'], (int)$record['sys_language_uid']]);
        $this->assertInvariantsHold();
    }
}
