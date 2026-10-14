<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Spike;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Spike S1: language 0 first (disabled, /_structure/) vs. primary language first in YAML.
 */
final class SiteLanguageOrderSpikeTest extends AbstractStructureLayerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StructureSite.csv');
        $this->setUpFrontendRootPage(1, ['EXT:structure_layer/Tests/Functional/Fixtures/Frontend/JsonRenderer.typoscript']);
    }

    public static function siteVariantsDataProvider(): array
    {
        return [
            'language 0 first' => ['structure-site', 0],
            'primary language first' => ['structure-site-primary-first', 1],
        ];
    }

    #[DataProvider('siteVariantsDataProvider')]
    #[Test]
    public function defaultLanguageIsFirstLanguageInYamlOrder(string $fixture, int $expectedDefaultLanguageId): void
    {
        $this->installSiteConfiguration($fixture);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        self::assertSame($expectedDefaultLanguageId, $site->getDefaultLanguage()->getLanguageId());
        self::assertSame([1, 2, 3], array_keys($site->getLanguages()));
        self::assertSame(0, $site->getLanguageById(0)->getLanguageId());
    }

    public static function routingDataProvider(): array
    {
        $cases = [];
        foreach (self::siteVariantsDataProvider() as $variant => [$fixture]) {
            $cases[$variant . ': / resolves German root'] = [$fixture, 'https://structure.localhost/', 200, 1, 'Startseite'];
            $cases[$variant . ': /en/ resolves English root'] = [$fixture, 'https://structure.localhost/en/', 200, 2, 'Home'];
            $cases[$variant . ': /en-gb/ resolves UK root'] = [$fixture, 'https://structure.localhost/en-gb/', 200, 3, 'Home (UK)'];
            $cases[$variant . ': /ueber-uns resolves German subpage'] = [$fixture, 'https://structure.localhost/ueber-uns', 200, 1, 'Über uns'];
            $cases[$variant . ': /en/about-us resolves English subpage'] = [$fixture, 'https://structure.localhost/en/about-us', 200, 2, 'About us'];
            $cases[$variant . ': /_structure/ is not reachable'] = [$fixture, 'https://structure.localhost/_structure/', 404, null, null];
        }
        return $cases;
    }

    #[DataProvider('routingDataProvider')]
    #[Test]
    public function frontendRoutingRespectsStructureLayer(string $fixture, string $url, int $expectedStatus, ?int $expectedLanguageId, ?string $expectedTitle): void
    {
        $this->installSiteConfiguration($fixture);
        $result = $this->renderFrontend($url);
        self::assertSame($expectedStatus, $result['status']);
        if ($expectedStatus === 200) {
            self::assertSame($expectedLanguageId, (int)$result['scope']['languageId']);
            self::assertSame($expectedTitle, $result['scope']['pageTitle']);
        }
    }
}
