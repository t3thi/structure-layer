<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Migration\Migrator;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Plan Phase 4: the frontend output after the migration equals the output before it (recorded
 * on an unpatched Core by `ddev baseline`, Fixtures/Baseline/), for every non-structure language –
 * except for the differences listed in EXPECTED_DIFFERENCES, each with its reason. All of them
 * follow from the model, none from the migration: the migration cannot avoid them.
 */
final class MigrationOutputTest extends AbstractStructureLayerTestCase
{
    /**
     * strict and free switched a whole page to the fallback language (here: the old default) if
     * the page had no translation. A chain now also selects records; for strict the chain never
     * contained the default records, for free no records at all. The page is a 404, its menu
     * entry is gone (plan §4 G compatibility note).
     */
    private const string PAGE_FALLBACK = 'page fallback to the old default language is lost';

    /**
     * A disabled translation in the requested language is intentional absence: no fallback for
     * the record or the page (plan §4 G, decided 2026-10-14). Before, the fallback content showed.
     */
    private const string DISABLED_BLOCKS = 'a disabled translation blocks the fallback';

    /**
     * free rendered translations in their own sorting; there is only the shared structure order
     * now (component C, no per-language ordering: plan non-goal).
     */
    private const string OWN_SORTING = 'per-language sorting is replaced by the structure order';

    /**
     * data set => request key => [changed parts of the signature, reasons]
     *
     * @var array<string, array<string, array{0: array<string, mixed>, 1: list<string>}>>
     */
    private const array EXPECTED_DIFFERENCES = [
        'RegularFreeMode-free' => [
            'website Dansk page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 89' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Dansk page 90' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Deutsch page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 89' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Deutsch page 90' => [['menu' => ''], [self::PAGE_FALLBACK]],
        ],
        'RegularFreeMode-strict' => [
            'website Dansk page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 89' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Dansk page 90' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Deutsch page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 89' => [['menu' => ''], [self::PAGE_FALLBACK]],
            'website Deutsch page 90' => [['menu' => ''], [self::PAGE_FALLBACK]],
        ],
        'RegularLocalize-free' => [
            'website Dansk page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 89' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 90' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 89' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 90' => [['status' => 404], [self::PAGE_FALLBACK]],
        ],
        'RegularLocalize-strict' => [
            'website Dansk page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 89' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 90' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 1' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 88' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 89' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 90' => [['status' => 404], [self::PAGE_FALLBACK]],
        ],
        'Visibility-fallback' => [
            'website Dansk page 1' => [['content' => ['A (da, own sorting)', 'F (da, Free Mode)', 'D (en only)', 'E (all languages)', 'G (da, copied from A)'], 'menu' => 'Default only,'], [self::DISABLED_BLOCKS]],
            'website Dansk page 30' => [['menu' => 'Default only,'], [self::DISABLED_BLOCKS]],
            'website Dansk page 40' => [['status' => 404], [self::DISABLED_BLOCKS]],
        ],
        'Visibility-free' => [
            'website Dansk page 1' => [['content' => ['A (da, own sorting)', 'B (da)', 'F (da, Free Mode)', 'E (all languages)', 'G (da, copied from A)'], 'menu' => ''], [self::OWN_SORTING, self::PAGE_FALLBACK, self::DISABLED_BLOCKS]],
            'website Dansk page 30' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 40' => [['status' => 404], [self::DISABLED_BLOCKS]],
            'website Deutsch page 1' => [['menu' => 'Versteckt auf Daenisch,'], [self::PAGE_FALLBACK]],
            'website Deutsch page 30' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 40' => [['menu' => 'Versteckt auf Daenisch,'], [self::PAGE_FALLBACK]],
        ],
        'Visibility-strict' => [
            'website Dansk page 1' => [['menu' => ''], [self::PAGE_FALLBACK, self::DISABLED_BLOCKS]],
            'website Dansk page 30' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Dansk page 40' => [['status' => 404], [self::DISABLED_BLOCKS]],
            'website Deutsch page 1' => [['menu' => 'Versteckt auf Daenisch,'], [self::PAGE_FALLBACK]],
            'website Deutsch page 30' => [['status' => 404], [self::PAGE_FALLBACK]],
            'website Deutsch page 40' => [['menu' => 'Versteckt auf Daenisch,'], [self::PAGE_FALLBACK]],
        ],
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function dataSetDataProvider(): array
    {
        $names = [];
        foreach (array_keys(MigrationDataSets::all()) as $name) {
            $names[$name] = [$name];
        }
        return $names;
    }

    #[DataProvider('dataSetDataProvider')]
    #[Test]
    public function outputAfterTheMigrationEqualsTheBaseline(string $name): void
    {
        $dataSet = MigrationDataSets::all()[$name];
        $this->importCSVDataSet($dataSet['csv']);
        $typoScript = (string)file_get_contents(__DIR__ . '/../Fixtures/Frontend/JsonRenderer.typoscript')
            . "\n" . (string)file_get_contents(MigrationDataSets::fixturePath('Output.typoscript'));
        foreach ($dataSet['sites'] as $identifier => $configuration) {
            $this->get(SiteWriter::class)->write($identifier, $configuration);
            $this->setUpFrontendRootPage((int)$configuration['rootPageId'], [], ['config' => $typoScript]);
        }
        foreach (array_keys($dataSet['sites']) as $identifier) {
            $report = $this->get(Migrator::class)->migrate($this->get(SiteFinder::class)->getSiteByIdentifier($identifier));
            self::assertSame([], $report->violations, $identifier);
        }

        $baseline = json_decode((string)file_get_contents(MigrationDataSets::baselinePath($name)), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        $actual = [];
        foreach (MigrationDataSets::urls($dataSet) as $key => $url) {
            $expected[$key] = $baseline[$key];
            if (isset(self::EXPECTED_DIFFERENCES[$name][$key])) {
                $difference = self::EXPECTED_DIFFERENCES[$name][$key][0];
                $expected[$key] = ($difference['status'] ?? 200) !== 200 ? $difference : array_replace($baseline[$key], $difference);
            }
            $actual[$key] = MigrationDataSets::signature($this->executeFrontendSubRequest(new InternalRequest($url)));
        }
        self::assertSame($expected, $actual);
    }
}
