<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Baseline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\Migration\MigrationDataSets;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "before" of the migration test: renders every data set on an unpatched Core without
 * EXT:structure_layer and writes the result to Tests/Functional/Migration/Fixtures/Baseline/.
 *
 * TranslationHandling is generated here, with DataHandler, the way the t3thi translation_handling
 * extension (Generator::create()) does it, and exported as CSV: generating it next to
 * EXT:structure_layer would already create shadows.
 */
final class RecordBaselineTest extends FunctionalTestCase
{
    private const array TRANSLATION_HANDLING_TYPES = ['fallback', 'strict', 'free'];

    /**
     * @return array<string, list<string>>
     */
    public static function dataSetDataProvider(): array
    {
        $names = ['TranslationHandling' => ['TranslationHandling']];
        foreach (array_keys(MigrationDataSets::all()) as $name) {
            $names[$name] = [$name];
        }
        return $names;
    }

    #[DataProvider('dataSetDataProvider')]
    #[Test]
    public function recordBaseline(string $name): void
    {
        if ($name === 'TranslationHandling') {
            $this->generateTranslationHandlingDataSet();
        }
        $dataSet = MigrationDataSets::all()[$name];
        $this->importCSVDataSet($dataSet['csv']);
        $siteWriter = $this->get(SiteWriter::class);
        $typoScript = (string)file_get_contents(__DIR__ . '/../../../Tests/Functional/Fixtures/Frontend/JsonRenderer.typoscript')
            . LF . (string)file_get_contents(MigrationDataSets::fixturePath('Output.typoscript'));
        foreach ($dataSet['sites'] as $identifier => $configuration) {
            $siteWriter->write($identifier, $configuration);
            $this->setUpFrontendRootPage((int)$configuration['rootPageId'], [], ['config' => $typoScript]);
        }

        $baseline = [];
        foreach (MigrationDataSets::urls($dataSet) as $key => $url) {
            $baseline[$key] = MigrationDataSets::signature($this->executeFrontendSubRequest(new InternalRequest($url)));
        }
        file_put_contents(
            MigrationDataSets::baselinePath($name),
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . LF
        );
        self::assertNotSame([], $baseline);
    }

    /**
     * Generator::create() for the types fallback, strict and free, minus files and sys_template.
     */
    private function generateTranslationHandlingDataSet(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../../Tests/Functional/Fixtures/be_users_admin.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $sites = [];
        $pages = [];
        foreach (self::TRANSLATION_HANDLING_TYPES as $type) {
            $dataHandler = $this->process(['pages' => [
                'NEWroot' => [
                    'title' => 'TYPO3 Translation Handling - ' . strtoupper($type),
                    'pid' => 0,
                    'is_siteroot' => 1,
                    'hidden' => 0,
                    // Configuration/page.tsconfig of translation_handling
                    'TSconfig' => "TCEMAIN.translateToMessage = %s\nTCEMAIN.table.pages.disablePrependAtCopy = 1\nTCEMAIN.table.pages.disableHideAtCopy = 1\nTCEMAIN.table.tt_content.disableHideAtCopy = 1",
                ],
                'NEWstorage' => ['title' => 'records storage', 'pid' => 'NEWroot', 'hidden' => 0, 'doktype' => 254],
            ]]);
            $rootPageId = (int)$dataHandler->substNEWwithIDs['NEWroot'];
            $identifier = 'translation-handling-' . $type;
            try {
                $this->get(SiteWriter::class)->delete($this->get(SiteFinder::class)->getSiteByRootPageId($rootPageId)->getIdentifier());
            } catch (SiteNotFoundException) {
            }
            $sites[$identifier] = $this->translationHandlingSite($rootPageId, $type);
            $this->get(SiteWriter::class)->write($identifier, $sites[$identifier]);

            $tree = [['uid' => $rootPageId, 'mode' => 'connected', 'content' => $this->addContent($rootPageId, [['textmedia', 'Intro', []]])]];
            $tree[] = ['uid' => (int)$dataHandler->substNEWwithIDs['NEWstorage'], 'mode' => 'connected', 'content' => []];
            foreach (['free', 'connected', 'mixed'] as $mode) {
                $pageId = (int)$this->process(['pages' => ['NEWpage' => ['title' => $mode, 'pid' => $rootPageId, 'hidden' => 0]]])->substNEWwithIDs['NEWpage'];
                $tree[] = ['uid' => $pageId, 'mode' => $mode, 'content' => $this->addContent($pageId, [
                    ['text', '', []],
                    ['text', '', [1, 2, 3, 4, 5, 6]],
                    ['text', '', [4, 5]],
                    ['text', '', [5]],
                    ['textmedia', '', [1]],
                    ['textmedia', '', [4]],
                ])];
            }
            $pages[$identifier] = array_column($tree, 'uid');

            // localizePageTree()
            foreach ($tree as $page) {
                foreach (range(1, 6) as $languageId) {
                    $this->process([], ['pages' => [$page['uid'] => ['localize' => $languageId]]]);
                    foreach (array_values($page['content']) as $position => [$uid, $excludeLanguages]) {
                        if (in_array($languageId, $excludeLanguages, true)) {
                            continue;
                        }
                        $connected = $page['mode'] === 'connected' || ($page['mode'] === 'mixed' && $position % 2 === 0);
                        $this->process([], ['tt_content' => [$uid => [$connected ? 'localize' : 'copyToLanguage' => $languageId]]]);
                    }
                }
            }
        }
        $this->exportCsv(MigrationDataSets::fixturePath('TranslationHandling.csv'), [
            'pages' => ['uid', 'pid', 'sorting', 'deleted', 'hidden', 'doktype', 'is_siteroot', 'sys_language_uid', 'l10n_parent', 'l10n_source', 'l10n_state', 'l18n_cfg', 'title', 'slug', 'TSconfig'],
            'tt_content' => ['uid', 'pid', 'sorting', 'deleted', 'hidden', 'sys_language_uid', 'l18n_parent', 'l10n_source', 'l10n_state', 'colPos', 'CType', 'header', 'header_layout'],
        ]);
        file_put_contents(
            MigrationDataSets::fixturePath('TranslationHandling.json'),
            json_encode(['sites' => $sites, 'pages' => $pages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . LF
        );
        foreach ($this->getConnectionPool()->getConnectionNames() as $connectionName) {
            foreach (['pages', 'tt_content', 'sys_refindex', 'sys_log', 'sys_history'] as $table) {
                $this->getConnectionPool()->getConnectionByName($connectionName)->truncate($table);
            }
        }
        foreach (array_keys($sites) as $identifier) {
            $this->get(SiteWriter::class)->delete($identifier);
        }
    }

    /**
     * Generator::createSiteConfiguration() on an empty installation (highest language id 0).
     *
     * @return array<string, mixed>
     */
    private function translationHandlingSite(int $rootPageId, string $type): array
    {
        $language = static fn(int $id, string $title, string $base, string $locale, string $fallbacks): array => [
            'languageId' => $id, 'title' => $title, 'enabled' => true, 'base' => $base, 'locale' => $locale,
            'flag' => strtolower(strtok($title, ' ') ?: ''), 'fallbackType' => $type, 'fallbacks' => $fallbacks,
        ];
        return [
            'rootPageId' => $rootPageId,
            'base' => 'https://' . $type . '.translation-handling.local/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Pink (Default)', 'enabled' => true, 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'pink'],
                $language(1, 'Purple (> Pink)', '/purple/', 'es_US.UTF-8', '0'),
                $language(2, 'Indigo (> Purple > Pink)', '/indigo/', 'es_MX.UTF-8', '1,0'),
                $language(3, 'Yellow (> Teal > Green > Pink)', '/yellow/', 'es_ES.UTF-8', '4,5,0'),
                $language(4, 'Teal', '/teal/', 'de_DE.UTF-8', ''),
                $language(5, 'Green (> Teal)', '/green/', 'de_AT.UTF-8', '4'),
                $language(6, 'Cyan (> Teal > Green)', '/cyan/', 'de_CH.UTF-8', '5,4'),
            ],
        ];
    }

    /**
     * Generator::addContentToPage() + getContentHeaderData(): the header is the default record's uid.
     *
     * @param list<array{string, string, list<int>}> $elements CType, header (empty: uid), excluded languages
     * @return list<array{int, list<int>}> uid, excluded languages
     */
    private function addContent(int $pageId, array $elements): array
    {
        $created = [];
        foreach ($elements as [$cType, $header, $excludeLanguages]) {
            $uid = (int)$this->process(['tt_content' => ['NEWcontent' => [
                'pid' => $pageId,
                'CType' => $cType,
                'header_layout' => 3,
                'bodytext' => '<p>Lorem ipsum</p>',
            ]]])->substNEWwithIDs['NEWcontent'];
            $this->process(['tt_content' => [$uid => ['header' => $header !== '' ? $header : (string)$uid]]]);
            $created[] = [$uid, $excludeLanguages];
        }
        return $created;
    }

    /**
     * @param array<string, array<string, mixed>> $datamap
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function process(array $datamap, array $cmdmap = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, $cmdmap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        return $dataHandler;
    }

    /**
     * Writes rows in the CSV format of importCSVDataSet().
     *
     * @param array<string, list<string>> $tables table => columns
     */
    private function exportCsv(string $fileName, array $tables): void
    {
        $handle = fopen('php://temp', 'w+');
        self::assertNotFalse($handle);
        foreach ($tables as $table => $columns) {
            fputcsv($handle, [$table], ',', '"', '');
            fputcsv($handle, ['', ...$columns], ',', '"', '');
            $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select(...$columns)->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
                fputcsv($handle, ['', ...array_map(static fn(mixed $value): string => (string)$value, array_values($row))], ',', '"', '');
            }
        }
        rewind($handle);
        file_put_contents($fileName, stream_get_contents($handle));
        fclose($handle);
    }
}
