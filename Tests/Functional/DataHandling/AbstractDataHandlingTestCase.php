<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use T3thi\StructureLayer\Integrity\InvariantChecker;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 2: DataHandler scenarios on Fixtures/StructureData.csv.
 *
 * Page 1 (shadow root, de/en): content shadows 100 (de, en), 110 (de, en), 120 (de only), "-1" element 130.
 * Page 10 (de, en, both nav_hide=1): content shadow 200 (de, en). Page 20: de only.
 *
 * Gate (plan §5, Phase 2): the invariant check is clean after every scenario.
 */
abstract class AbstractDataHandlingTestCase extends AbstractStructureLayerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/StructureData.csv');
        $this->installSiteConfiguration();
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    protected function processDatamap(array $datamap, bool $expectErrors = false): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        if (!$expectErrors) {
            self::assertSame([], $dataHandler->errorLog, 'DataHandler error log');
        }
        return $dataHandler;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    protected function processCmdmap(array $cmdmap, bool $expectErrors = false): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();
        if (!$expectErrors) {
            self::assertSame([], $dataHandler->errorLog, 'DataHandler error log');
        }
        return $dataHandler;
    }

    protected function assertInvariantsHold(): void
    {
        $violations = array_map(strval(...), $this->get(InvariantChecker::class)->check());
        self::assertSame([], $violations, 'structure-layer:check');
    }

    /**
     * Including deleted records.
     *
     * @return array<string, mixed>
     */
    protected function fetchRecord(string $table, int $uid): array
    {
        $row = $this->createUnrestrictedQueryBuilder($table)->select('*')->from($table)
            ->where('uid = ' . $uid)->executeQuery()->fetchAssociative();
        self::assertIsArray($row, $table . ':' . $uid . ' exists');
        return $row;
    }

    /**
     * Not deleted translations of a shadow, indexed by language id.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchTranslations(string $table, int $shadowUid): array
    {
        $parentField = $table === 'pages' ? 'l10n_parent' : 'l18n_parent';
        $rows = $this->createUnrestrictedQueryBuilder($table)->select('*')->from($table)
            ->where($parentField . ' = ' . $shadowUid, 'deleted = 0')
            ->orderBy('sys_language_uid')->executeQuery()->fetchAllAssociative();
        $translations = [];
        foreach ($rows as $row) {
            self::assertArrayNotHasKey((int)$row['sys_language_uid'], $translations, 'duplicate translation of ' . $table . ':' . $shadowUid);
            $translations[(int)$row['sys_language_uid']] = $row;
        }
        return $translations;
    }

    /**
     * Uids of not deleted structure-level records (shadows and "-1") on a page, in sorting order.
     *
     * @return list<int>
     */
    protected function fetchStructureOrder(int $pid, string $table = 'tt_content'): array
    {
        $queryBuilder = $this->createUnrestrictedQueryBuilder($table);
        return array_map(intval(...), $queryBuilder->select('uid')->from($table)->where(
            $queryBuilder->expr()->eq('pid', $pid),
            $queryBuilder->expr()->eq('deleted', 0),
            $queryBuilder->expr()->in('sys_language_uid', [0, -1]),
        )->orderBy('sorting')->addOrderBy('uid')->executeQuery()->fetchFirstColumn());
    }

    /**
     * Sorting of all rows on a page (including translations), indexed by uid.
     *
     * @return array<int, int>
     */
    protected function fetchSortingSnapshot(int $pid, string $table = 'tt_content'): array
    {
        $rows = $this->createUnrestrictedQueryBuilder($table)->select('uid', 'sorting')->from($table)
            ->where('pid = ' . $pid, 'deleted = 0')->executeQuery()->fetchAllKeyValue();
        return array_map(intval(...), $rows);
    }

    /**
     * Connection::select() applies the default restrictions (hidden, time window), tests must see everything.
     */
    private function createUnrestrictedQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
