<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Workspaces;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use T3thi\StructureLayer\Tests\Functional\DataHandling\AbstractDataHandlingTestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 6 (plan §5, WS-1): characterization only. The structure layer was built for live records;
 * this records what a workspace does with it. Assertions describe the current behavior, including
 * the broken parts (marked "BREAKS").
 *
 * Data: StructureData.csv (page 1: 100 (de 101, en 102), 110 (de 111, en 112), 120 (de 121 only)).
 */
final class WorkspaceCharacterizationTest extends AbstractDataHandlingTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Workspace.csv');
    }

    #[Test]
    public function createInALanguageCreatesAShadowVersionAndPublishesConsistently(): void
    {
        $uid = (int)$this->inWorkspace(['tt_content' => ['NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'New (en)']]])->substNEWwithIDs['NEW1'];
        $record = $this->fetchRecord('tt_content', $uid);
        $shadow = $this->fetchRecord('tt_content', (int)$record['l18n_parent']);

        self::assertSame([1, 1, 0], [(int)$record['t3ver_wsid'], (int)$shadow['t3ver_wsid'], (int)$shadow['sys_language_uid']]);
        // Drift: sorting alignment (C) only touches live records, the workspace preview of a
        // no-chain language orders by the translation's own sorting
        self::assertNotSame((int)$shadow['sorting'], (int)$record['sorting']);

        $this->publish([(int)$shadow['uid'], $uid]);
        self::assertSame((int)$this->fetchRecord('tt_content', (int)$shadow['uid'])['sorting'], (int)$this->fetchRecord('tt_content', $uid)['sorting'], 'aligned on publish');
        $this->assertInvariantsHold();
    }

    #[Test]
    public function moveCreatesMoveVersionsInAllLanguagesAndPublishesConsistently(): void
    {
        $this->inWorkspace([], ['tt_content' => [112 => ['move' => -102]]]);

        $versions = $this->fetchVersions();
        self::assertSame([110, 111, 112], array_keys(array_filter($versions, static fn(array $version): bool => (int)$version['t3ver_state'] === 4)));

        $this->publish(array_map(intval(...), array_column($versions, 'uid')));
        self::assertSame([100, 110, 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function hideInAllLanguagesCreatesVersionsOfEveryTranslation(): void
    {
        $this->inWorkspace(['tt_content' => [100 => ['hidden' => 1]]]);

        $versions = $this->fetchVersions();
        self::assertSame([101, 102], array_keys($versions));
        self::assertSame([1, 1], array_map(static fn(array $version): int => (int)$version['hidden'], array_values($versions)));
    }

    #[Test]
    public function deletingTheLastTranslationInAWorkspaceKeepsTheShadow(): void
    {
        $this->inWorkspace([], ['tt_content' => [121 => ['delete' => 1]]]);

        // BREAKS: the hook looks for live translations; 121 is still live, so 120 is not the last
        self::assertSame([121], array_keys($this->fetchVersions()));
        $this->publish(array_map(intval(...), array_column($this->fetchVersions(), 'uid')));
        self::assertSame(['[I4] tt_content:120 shadow without translation'], $this->violations());
    }

    #[Test]
    public function discardingANewTranslationKeepsItsNewShadow(): void
    {
        $uid = (int)$this->inWorkspace(['tt_content' => ['NEW1' => ['pid' => 10, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'Discard me']]])->substNEWwithIDs['NEW1'];
        $shadowUid = (int)$this->fetchRecord('tt_content', $uid)['l18n_parent'];

        $this->inWorkspace([], ['tt_content' => [$uid => ['version' => ['action' => 'clearWSID']]]]);

        // BREAKS: discard has no hook path to the shadow; publishing the workspace makes it live
        self::assertArrayHasKey($shadowUid, $this->fetchVersions());
        $this->publish([$shadowUid]);
        self::assertSame(['[I4] tt_content:' . $shadowUid . ' shadow without translation'], $this->violations());
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function inWorkspace(array $datamap, array $cmdmap = []): DataHandler
    {
        $GLOBALS['BE_USER']->setWorkspace(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, $cmdmap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        $GLOBALS['BE_USER']->setWorkspace(0);
        self::assertSame([], $dataHandler->errorLog);
        return $dataHandler;
    }

    /**
     * @param list<int> $versionUids
     */
    private function publish(array $versionUids): void
    {
        $cmdmap = [];
        foreach ($versionUids as $versionUid) {
            $version = $this->fetchRecord('tt_content', $versionUid);
            $liveUid = (int)$version['t3ver_oid'] ?: $versionUid;
            $cmdmap['tt_content'][$liveUid]['version'] = ['action' => 'publish', 'swapWith' => $versionUid];
        }
        $this->processCmdmap($cmdmap);
    }

    /**
     * Workspace versions by the uid of their live record (new records: their own uid).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchVersions(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $versions = [];
        foreach ($queryBuilder->select('*')->from('tt_content')->where('t3ver_wsid = 1')->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
            $versions[(int)$row['t3ver_oid'] ?: (int)$row['uid']] = $row;
        }
        ksort($versions);
        return $versions;
    }

    /**
     * @return list<string>
     */
    private function violations(): array
    {
        return array_values(array_map(strval(...), $this->get(InvariantChecker::class)->check()));
    }
}
