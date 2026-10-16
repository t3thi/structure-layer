<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Measurements;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Migration\Migrator;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use T3thi\StructureLayer\Tests\Functional\Migration\MigrationDataSets;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Phase 6 (plan §5, SCALE-1): absolute counts, no adjectives (T3DD26 analysis). Every test writes
 * its numbers to var/measurements/<name>.json (collected into docs/findings.md) and only asserts
 * that it measured something; the numbers depend on Core and are not a contract.
 *
 * SQL statements are counted by QueryCounter; "extension" = issued while code of the extension
 * was on the stack (listeners, hooks, nested DataHandler runs started by the hook).
 */
final class MeasurementTest extends AbstractStructureLayerTestCase
{
    protected array $configurationToUseInTestInstance = [
        'DB' => ['Connections' => ['Default' => ['driverMiddlewares' => [
            'structure-layer-query-counter' => ['target' => QueryCounter::class],
        ]]]],
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function frontendRequestDataProvider(): array
    {
        return [
            'de (no chain), page 1' => ['/'],
            'en (chain 1), page 1' => ['/en/'],
            'en-GB (chain 2,1), page 1' => ['/en-gb/'],
            'de (no chain), page 10' => ['/ueber-uns'],
            'en (chain 1), page 10' => ['/en/about-us'],
        ];
    }

    #[DataProvider('frontendRequestDataProvider')]
    #[Test]
    public function frontendRequest(string $path): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Frontend/Fixtures/ChainOutput.csv');
        $this->installSiteConfiguration();
        $this->setUpFrontendRootPage(1, [
            'EXT:structure_layer/Tests/Functional/Fixtures/Frontend/JsonRenderer.typoscript',
            'EXT:structure_layer/Tests/Functional/Frontend/Fixtures/StructureLayer.typoscript',
        ]);
        $url = 'https://structure.localhost' . $path;
        // Warm-up: caches (TypoScript, site, TCA) are built on the first request
        $this->executeFrontendSubRequest(new InternalRequest($url));

        $counts = QueryCounter::measure(fn() => self::assertSame(200, $this->executeFrontendSubRequest(new InternalRequest($url))->getStatusCode()));

        $this->writeMeasurement('frontend ' . $path, $counts);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function dataHandlerOperationDataProvider(): array
    {
        return [
            'create in en (shadow + record)' => [['tt_content' => ['NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'New']]], []],
            'insert after a translation' => [['tt_content' => ['NEW1' => ['pid' => -112, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'New']]], []],
            'edit payload of a translation' => [['tt_content' => [102 => ['header' => 'Changed']]], []],
            'edit colPos from a translation' => [['tt_content' => [102 => ['colPos' => 1]]], []],
            'hide in all languages' => [['tt_content' => [100 => ['hidden' => 1]]], []],
            'move a translation (all languages)' => [[], ['tt_content' => [112 => ['move' => -102]]]],
            'translate de to en' => [[], ['tt_content' => [121 => ['localize' => 2]]]],
            'delete one of two translations' => [[], ['tt_content' => [112 => ['delete' => 1]]]],
            'delete the last translation (+ shadow)' => [[], ['tt_content' => [121 => ['delete' => 1]]]],
            'copy a translation (all languages)' => [[], ['tt_content' => [112 => ['copy' => -120]]]],
        ];
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    #[DataProvider('dataHandlerOperationDataProvider')]
    #[Test]
    public function dataHandlerOperation(array $datamap, array $cmdmap): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataHandling/Fixtures/StructureData.csv');
        $this->installSiteConfiguration();
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $counts = QueryCounter::measure(static function () use ($datamap, $cmdmap): void {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($datamap, $cmdmap);
            $dataHandler->process_datamap();
            $dataHandler->process_cmdmap();
            self::assertSame([], $dataHandler->errorLog);
        });

        $this->writeMeasurement('datahandler ' . $this->dataName(), $counts);
    }

    #[Test]
    public function referenceIndexBeforeAndAfterMigration(): void
    {
        $dataSet = MigrationDataSets::all()['TranslationHandling'];
        $this->importCSVDataSet($dataSet['csv']);
        foreach ($dataSet['sites'] as $identifier => $configuration) {
            $this->get(SiteWriter::class)->write($identifier, $configuration);
        }
        $this->get(ReferenceIndex::class)->updateIndex(false);
        $before = $this->countReferenceIndex();

        $counts = QueryCounter::measure(function () use ($dataSet): void {
            foreach (array_keys($dataSet['sites']) as $identifier) {
                $this->get(Migrator::class)->migrate($this->get(SiteFinder::class)->getSiteByIdentifier($identifier));
            }
        });
        $afterMigration = $this->countReferenceIndex();
        $this->get(ReferenceIndex::class)->updateIndex(false);

        $this->writeMeasurement('migration TranslationHandling', $counts + [
            'refindexBefore' => $before,
            'refindexAfterMigration' => $afterMigration,
            'refindexAfterFullUpdate' => $this->countReferenceIndex(),
        ]);
    }

    private function countReferenceIndex(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('sys_refindex')->count('*', 'sys_refindex', []);
    }

    /**
     * @param array<string, int> $counts
     */
    private function writeMeasurement(string $name, array $counts): void
    {
        self::assertGreaterThan(0, $counts['total']);
        $directory = dirname(__DIR__, 3) . '/var/measurements';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/' . preg_replace('/[^a-z0-9]+/i', '-', $name) . '.json', json_encode(['name' => $name] + $counts, JSON_PRETTY_PRINT) . "\n");
    }
}
