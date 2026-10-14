<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Phase 1: Extbase queries follow the same chain-driven output as TypoScript CONTENT
 * (plan §4 G), including findByUid() with a shadow uid (difference #5).
 */
final class ExtbaseOutputTest extends AbstractStructureLayerTestCase
{
    protected array $testExtensionsToLoad = [
        't3thi/structure-layer',
        __DIR__ . '/../Fixtures/Extensions/structure_layer_extbase_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ChainOutput.csv');
        $this->setUpFrontendRootPage(1, ['EXT:structure_layer/Tests/Functional/Frontend/Fixtures/Extbase.typoscript']);
        $this->installSiteConfiguration();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function extbaseDataProvider(): array
    {
        return [
            'de, no chain' => [
                '/',
                ['Element 1 (de)', 'Element 2 (de)', 'Element 4 (all languages)', 'Element 5 (de)'],
                [100 => 'Element 1 (de)', 110 => 'Element 2 (de)', 120 => null],
            ],
            'en, chain 1' => [
                '/en/',
                ['Element 1 (en)', 'Element 3 (en only)', 'Element 4 (all languages)', 'Element 5 (en)'],
                [100 => 'Element 1 (en)', 110 => null, 120 => 'Element 3 (en only)'],
            ],
            'en-GB, chain 2,1' => [
                '/en-gb/',
                ['Element 1 (en-GB)', 'Element 2 (de)', 'Element 3 (en only)', 'Element 4 (all languages)'],
                [100 => 'Element 1 (en-GB)', 110 => 'Element 2 (de)', 120 => 'Element 3 (en only)'],
            ],
        ];
    }

    /**
     * @param list<string> $expectedList
     * @param array<int, string|null> $expectedByUid
     */
    #[DataProvider('extbaseDataProvider')]
    #[Test]
    public function extbaseFollowsTheFallbackChain(string $path, array $expectedList, array $expectedByUid): void
    {
        $result = $this->renderExtbase($path);
        self::assertSame($expectedList, $result['list']);
        self::assertSame($expectedByUid, $result['byUid']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function countDataProvider(): array
    {
        return [
            'de, no chain: SQL selects the requested language directly' => ['/', 4],
            // Known gap (plan §4 G): with a chain, Extbase counts language-0/-1 rows in SQL;
            // the listener drops rows only after the query. 5 shadows + 1 "-1" element.
            'en, chain 1: count includes dropped positions' => ['/en/', 6],
            'en-GB, chain 2,1: count includes dropped positions' => ['/en-gb/', 6],
        ];
    }

    #[DataProvider('countDataProvider')]
    #[Test]
    public function extbaseCountCharacterization(string $path, int $expectedCount): void
    {
        self::assertSame($expectedCount, $this->renderExtbase($path)['count']);
    }

    /**
     * @return array{list: list<string>, count: int, byUid: array<int, string|null>}
     */
    private function renderExtbase(string $path): array
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://structure.localhost' . $path));
        self::assertSame(200, $response->getStatusCode());
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        // json_decode() turns the integer keys of byUid into strings, restore them
        $result['byUid'] = array_combine(array_map(intval(...), array_keys($result['byUid'])), $result['byUid']);
        /** @var array{list: list<string>, count: int, byUid: array<int, string|null>} $result */
        return $result;
    }
}
