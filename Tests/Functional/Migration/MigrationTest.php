<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use T3thi\StructureLayer\Command\MigrateCommand;
use T3thi\StructureLayer\Migration\MigrationReport;
use T3thi\StructureLayer\Migration\Migrator;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Plan §4 I on the record level, with Fixtures/Visibility.csv (classic data, language 0 = English):
 * - page 1: en, da, de; page 10: hidden en; page 20: expired en; page 30: en only; page 40: da hidden
 * - content on page 1: A 100 (da 101 with its own sorting, de 102), B 110 (en hidden, da 111,
 *   de 112), C 120 (da 121 hidden), D 130 (en only), E 140 ("-1"), F 150 (da, Free Mode),
 *   G 160 (da, copied from A: Free Mode with l10n_source = 100)
 */
final class MigrationTest extends AbstractStructureLayerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(MigrationDataSets::fixturePath('Visibility.csv'));
    }

    /**
     * @return array<string, array{string, array<int, array<string, string>>, list<string>}>
     */
    public static function siteConfigurationDataProvider(): array
    {
        return [
            // Records always fell back to the default record in the end: the chain ends with it
            'fallback' => ['fallback', [1 => ['fallbacks' => '3'], 2 => ['fallbacks' => '1,3']], []],
            // Records fell back along the chain, never to the default record: only "0" goes, and with it the page fallback
            'strict' => ['strict', [1 => ['fallbacks' => '', 'inactiveFallbacks' => '3'], 2 => ['fallbacks' => '1', 'inactiveFallbacks' => '3']], []],
            // Records never fell back: the chain only switched whole pages
            'free' => ['free', [1 => ['fallbacks' => '', 'inactiveFallbacks' => '3'], 2 => ['fallbacks' => '', 'inactiveFallbacks' => '1,3']], []],
        ];
    }

    /**
     * @param array<int, array<string, string>> $expectedFallbacks
     */
    #[DataProvider('siteConfigurationDataProvider')]
    #[Test]
    public function siteConfigurationGetsTheStructureLayerAndAnEquivalentChain(string $fallbackType, array $expectedFallbacks): void
    {
        $configuration = $this->get(Migrator::class)->migrateSiteConfiguration(
            MigrationDataSets::siteConfiguration(1, MigrationDataSets::BASE, $fallbackType),
            $report = new MigrationReport()
        );

        self::assertSame(3, $report->newLanguageId);
        self::assertSame([0, 3, 1, 2], array_column($configuration['languages'], 'languageId'));
        self::assertSame(
            ['languageId' => 0, 'title' => 'Structure', 'enabled' => false, 'base' => '/_structure/', 'locale' => 'en_US.UTF-8', 'flag' => 'global'],
            $configuration['languages'][0]
        );
        // The old default language keeps title, base and locale: the same URLs show the same content
        self::assertSame(
            ['languageId' => 3, 'title' => 'English', 'enabled' => true, 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
            $configuration['languages'][1]
        );
        foreach ($expectedFallbacks as $index => $expected) {
            $language = $configuration['languages'][$index + 1];
            self::assertSame($expected, array_intersect_key($language, ['fallbacks' => true, 'inactiveFallbacks' => true]), 'language ' . $language['languageId']);
            // left in place, ignored (plan §4 G), reported by structure-layer:check as a notice
            self::assertSame($fallbackType, $language['fallbackType']);
        }
    }

    #[Test]
    public function defaultRecordsBecomeShadowsWithATranslationInTheNewLanguage(): void
    {
        $report = $this->migrate('fallback');

        self::assertSame([], $report->violations);
        self::assertSame(5, $report->get('pages', 'translationsCreated'));
        self::assertSame(4, $report->get('tt_content', 'translationsCreated'));
        // uids stay: the old default record is the shadow, its payload moved to language 3
        $shadow = $this->fetchRecord('tt_content', 100);
        self::assertSame([0, '', 0], [(int)$shadow['sys_language_uid'], $shadow['header'], (int)$shadow['hidden']]);
        $english = $this->fetchTranslation('tt_content', 100, 3);
        self::assertSame(['A (en)', 0, 256], [$english['header'], (int)$english['l10n_source'], (int)$english['sorting']]);
        $page = $this->fetchRecord('pages', 1);
        self::assertSame(['', 1, '/'], [$page['title'], (int)$page['l18n_cfg'], $page['slug']]);
        $englishPage = $this->fetchTranslation('pages', 1, 3);
        self::assertSame(['Home', '/'], [$englishPage['title'], $englishPage['slug']]);
        // §3.3: synchronizable page fields are per language, or DataMapProcessor blanks them
        self::assertSame('custom', json_decode((string)$englishPage['l10n_state'], true)['nav_hide'] ?? null);
        self::assertSame('custom', json_decode((string)$this->fetchTranslation('pages', 1, 1)['l10n_state'], true)['nav_hide'] ?? null);
    }

    #[Test]
    public function hiddenDefaultRecordsBecomeHiddenTranslationsWhereTheirVisibilityApplied(): void
    {
        $report = $this->migrate('fallback');

        // Page 10: the page lookup read the default page, so it was hidden in every language
        self::assertSame(0, (int)$this->fetchRecord('pages', 10)['hidden']);
        self::assertSame([1, 1, 1], array_map(fn(int $languageId): int => (int)$this->fetchTranslation('pages', 10, $languageId)['hidden'], [1, 2, 3]));
        // Page 20: the default page's time window is intersected into every translation
        self::assertSame([0, 0], [(int)$this->fetchRecord('pages', 20)['starttime'], (int)$this->fetchRecord('pages', 20)['endtime']]);
        self::assertSame(1000000000, (int)$this->fetchTranslation('pages', 20, 1)['endtime']);
        // Content B: overlays of the hidden default row were not shown in fallback languages
        self::assertSame(0, (int)$this->fetchRecord('tt_content', 110)['hidden']);
        self::assertSame([1, 1, 1], array_map(fn(int $languageId): int => (int)$this->fetchTranslation('tt_content', 110, $languageId)['hidden'], [1, 2, 3]));
        self::assertSame(2, $report->get('pages', 'translationsHiddenByParent'));
        self::assertSame(2, $report->get('tt_content', 'translationsHiddenByParent'));
        self::assertSame(2, $report->get('pages', 'timeWindowsIntersected'));
    }

    #[Test]
    public function inFreeLanguagesTheDefaultRecordsVisibilityDidNotApply(): void
    {
        $this->migrate('free');

        // OVERLAYS_OFF selected the translations directly: B (da), B (de) were visible
        self::assertSame([0, 0, 1], array_map(fn(int $languageId): int => (int)$this->fetchTranslation('tt_content', 110, $languageId)['hidden'], [1, 2, 3]));
        // The page lookup always read the default page
        self::assertSame([1, 1, 1], array_map(fn(int $languageId): int => (int)$this->fetchTranslation('pages', 10, $languageId)['hidden'], [1, 2, 3]));
    }

    #[Test]
    public function freeModeRecordsGetTheirOwnShadowAndStayAbsentInChainLanguages(): void
    {
        $report = $this->migrate('fallback');

        $free = $this->fetchRecord('tt_content', 150);
        $shadow = $this->fetchRecord('tt_content', (int)$free['l18n_parent']);
        self::assertSame([0, '', 640, 640], [(int)$shadow['sys_language_uid'], $shadow['header'], (int)$shadow['sorting'], (int)$free['sorting']]);
        // de (chain 1,3) never showed the Danish floating record: a disabled translation keeps it absent
        $marker = $this->fetchTranslation('tt_content', (int)$shadow['uid'], 2);
        self::assertSame([1, 150], [(int)$marker['hidden'], (int)$marker['l10n_source']]);
        self::assertNull($this->findTranslation('tt_content', (int)$shadow['uid'], 3), 'language 3 has no chain');
        self::assertSame(2, $report->get('tt_content', 'freeModeShadowsCreated'));
        self::assertSame(2, $report->get('tt_content', 'absenceMarkersCreated'));
        // Never matched automatically (2026-08-21), only reported
        self::assertCount(1, $report->connectionCandidates);
        self::assertStringContainsString('tt_content:160 (language 1, Free Mode) was copied from tt_content:100', $report->connectionCandidates[0]);
    }

    #[Test]
    public function translationsMirrorTheShadowsStructureAndSorting(): void
    {
        $report = $this->migrate('fallback');

        self::assertSame(256, (int)$this->fetchRecord('tt_content', 101)['sorting']);
        self::assertSame(1, $report->get('tt_content', 'sortingAligned'));
        // "-1" records stay single records without a shadow
        $allLanguages = $this->fetchRecord('tt_content', 140);
        self::assertSame([-1, 0, 'E (all languages)'], [(int)$allLanguages['sys_language_uid'], (int)$allLanguages['l18n_parent'], $allLanguages['header']]);
        self::assertSame(1, $report->get('tt_content', 'allLanguagesRecordsUntouched'));
    }

    #[Test]
    public function fileReferencesMoveToTheTranslationInTheNewLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../DataHandling/Fixtures/SysFile.csv');
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', ['pid' => 1, 'uid_local' => 1, 'uid_foreign' => 100, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'sys_language_uid' => 0]);
        $reference = (int)$connection->lastInsertId();
        $connection->insert('sys_file_reference', ['pid' => 1, 'uid_local' => 1, 'uid_foreign' => 101, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'sys_language_uid' => 1, 'l10n_parent' => $reference]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['image' => 1], ['uid' => 100]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['image' => 1], ['uid' => 101]);

        $report = $this->migrate('fallback');

        $english = $this->fetchTranslation('tt_content', 100, 3);
        $moved = $this->fetchRows('sys_file_reference', ['uid' => $reference])[0];
        self::assertSame([(int)$english['uid'], 3, 1], [(int)$moved['uid_foreign'], (int)$moved['sys_language_uid'], (int)$english['image']]);
        self::assertSame(0, (int)$this->fetchRecord('tt_content', 100)['image']);
        self::assertSame(1, $report->get('tt_content', 'fileReferencesMoved'));
    }

    #[Test]
    public function dryRunReportsTheSameButWritesNothing(): void
    {
        $this->writeClassicSite('fallback');
        $rowsBefore = $this->countRows();

        $dryRun = $this->get(Migrator::class)->migrate($this->getSite(), true);

        self::assertTrue($dryRun->dryRun);
        self::assertSame($rowsBefore, $this->countRows());
        self::assertTrue($this->getSite()->getLanguageById(0)->isEnabled(), 'site configuration untouched');
        $report = $this->get(Migrator::class)->migrate($this->getSite());
        self::assertSame($report->counts, $dryRun->counts);
        self::assertSame($report->siteConfiguration, $dryRun->siteConfiguration);
    }

    #[Test]
    public function aMigratedSiteIsNotMigratedTwice(): void
    {
        $this->migrate('fallback');

        $this->expectExceptionCode(1792060000);
        $this->get(Migrator::class)->migrate($this->getSite());
    }

    #[Test]
    public function commandMigratesAndWritesThePlan(): void
    {
        $this->writeClassicSite('fallback');
        $planFile = $this->instancePath . '/typo3temp/migration-plan.json';
        $tester = new CommandTester($this->get(MigrateCommand::class));

        $tester->execute(['site' => self::SITE_IDENTIFIER, '--dry-run' => true, '--plan' => $planFile]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $plan = json_decode((string)file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(4, $plan['counts']['tt_content']['translationsCreated']);

        $tester->execute(['site' => self::SITE_IDENTIFIER]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Site migrated', $tester->getDisplay());
    }

    private function migrate(string $fallbackType): MigrationReport
    {
        $this->writeClassicSite($fallbackType);
        return $this->get(Migrator::class)->migrate($this->getSite());
    }

    private function writeClassicSite(string $fallbackType): void
    {
        $this->get(SiteWriter::class)->write(self::SITE_IDENTIFIER, MigrationDataSets::siteConfiguration(1, MigrationDataSets::BASE, $fallbackType));
    }

    private function getSite(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRecord(string $table, int $uid): array
    {
        $record = $this->fetchRows($table, ['uid' => $uid])[0] ?? null;
        self::assertIsArray($record, $table . ':' . $uid);
        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTranslation(string $table, int $shadowUid, int $languageId): array
    {
        $translation = $this->findTranslation($table, $shadowUid, $languageId);
        self::assertIsArray($translation, $table . ':' . $shadowUid . ' in language ' . $languageId);
        return $translation;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTranslation(string $table, int $shadowUid, int $languageId): ?array
    {
        $parentField = $table === 'pages' ? 'l10n_parent' : 'l18n_parent';
        return $this->fetchRows($table, [$parentField => $shadowUid, 'sys_language_uid' => $languageId, 'deleted' => 0])[0] ?? null;
    }

    /**
     * Without restrictions: Connection::select() would hide hidden and expired rows.
     *
     * @param array<string, int> $where
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $table, array $where): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from($table);
        foreach ($where as $field => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
        }
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, int>
     */
    private function countRows(): array
    {
        $counts = [];
        foreach (['pages', 'tt_content', 'sys_file_reference'] as $table) {
            $counts[$table] = $this->getConnectionPool()->getConnectionForTable($table)->count('*', $table, []);
        }
        return $counts;
    }
}
