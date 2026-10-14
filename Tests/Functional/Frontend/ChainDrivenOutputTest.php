<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;

/**
 * Phase 1: output is driven by the fallback chain alone (plan §4 G).
 *
 * Fixture site: 0 = Structure, 1 de (no chain), 2 en (chain 1), 3 en-GB (chain 2,1).
 * Fixture data: Fixtures/ChainOutput.csv, shadows have empty headers and titles.
 */
final class ChainDrivenOutputTest extends AbstractStructureLayerTestCase
{
    private const BASE = 'https://structure.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ChainOutput.csv');
        $this->setUpFrontendRootPage(1, [
            'EXT:structure_layer/Tests/Functional/Fixtures/Frontend/JsonRenderer.typoscript',
            'EXT:structure_layer/Tests/Functional/Frontend/Fixtures/StructureLayer.typoscript',
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function siteFixtureDataProvider(): array
    {
        return [
            'chain-only site' => ['structure-site'],
            // fallbackType strict/free and "0" in fallbacks must make no difference
            'legacy fallbackType values' => ['structure-site-legacy-fallback-types'],
        ];
    }

    public static function contentDataProvider(): \Generator
    {
        $cases = [
            'de, no chain' => [
                '/',
                'off',
                ['Element 1 (de)', 'Element 2 (de)', 'Element 4 (all languages)', 'Element 5 (de)'],
            ],
            'en, chain 1' => [
                '/en/',
                'mixed',
                // Element 2: en exists but is disabled → nothing, no fallback to de
                ['Element 1 (en)', 'Element 3 (en only)', 'Element 4 (all languages)', 'Element 5 (en)'],
            ],
            'en-GB, chain 2,1' => [
                '/en-gb/',
                'mixed',
                // Element 2: en-GB missing, en disabled → chain continues to de
                // Element 5: en-GB exists but is disabled → nothing
                ['Element 1 (en-GB)', 'Element 2 (de)', 'Element 3 (en only)', 'Element 4 (all languages)'],
            ],
        ];
        foreach (self::siteFixtureDataProvider() as $siteLabel => [$siteFixture]) {
            foreach ($cases as $label => $case) {
                yield $label . ' (' . $siteLabel . ')' => [$siteFixture, ...$case];
            }
        }
    }

    /**
     * @param list<string> $expectedHeaders
     */
    #[DataProvider('contentDataProvider')]
    #[Test]
    public function contentFollowsTheFallbackChain(string $siteFixture, string $path, string $expectedOverlayType, array $expectedHeaders): void
    {
        $this->installSiteConfiguration($siteFixture);
        $result = $this->renderFrontend(self::BASE . $path);
        self::assertSame(200, $result['status']);
        self::assertSame($expectedOverlayType, $result['scope']['overlayType']);
        self::assertSame($expectedHeaders, $this->renderedContentHeaders($result));
    }

    #[Test]
    public function deletingTheDisabledTranslationReEnablesFallback(): void
    {
        $this->installSiteConfiguration();
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 112]);
        $result = $this->renderFrontend(self::BASE . '/en/');
        self::assertSame(
            ['Element 1 (en)', 'Element 2 (de)', 'Element 3 (en only)', 'Element 4 (all languages)', 'Element 5 (en)'],
            $this->renderedContentHeaders($result)
        );
    }

    public static function pageDataProvider(): \Generator
    {
        $cases = [
            'de page' => ['/ueber-uns', 200, 'Über uns'],
            'en page' => ['/en/about-us', 200, 'About us'],
            'en-GB missing page falls back to en' => ['/en-gb/about-us', 200, 'About us'],
            'en disabled page in chain language → 404, no fallback' => ['/en/contact', 404, null],
            'de page of a disabled en page' => ['/kontakt', 200, 'Kontakt'],
            'en-GB missing, en disabled → chain continues to de' => ['/en-gb/kontakt', 200, 'Kontakt'],
            'de disabled page in no-chain language → 404' => ['/impressum', 404, null],
            'en page of a disabled de page' => ['/en/imprint', 200, 'Imprint'],
            'en-GB falls back to en, de disabled' => ['/en-gb/imprint', 200, 'Imprint'],
            'hidden in all languages, de' => ['/versteckt', 404, null],
            'hidden in all languages, en' => ['/en/hidden', 404, null],
            'hidden in all languages, en-GB' => ['/en-gb/hidden', 404, null],
            'structure language is not reachable' => ['/_structure/', 404, null],
            'structure language subpage is not reachable' => ['/_structure/about', 404, null],
        ];
        foreach (self::siteFixtureDataProvider() as $siteLabel => [$siteFixture]) {
            foreach ($cases as $label => $case) {
                yield $label . ' (' . $siteLabel . ')' => [$siteFixture, ...$case];
            }
        }
    }

    #[DataProvider('pageDataProvider')]
    #[Test]
    public function pagesFollowTheFallbackChain(string $siteFixture, string $path, int $expectedStatus, ?string $expectedTitle): void
    {
        $this->installSiteConfiguration($siteFixture);
        $result = $this->renderFrontend(self::BASE . $path);
        self::assertSame($expectedStatus, $result['status']);
        if ($expectedTitle !== null) {
            self::assertSame($expectedTitle, $result['scope']['pageTitle']);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function menuDataProvider(): array
    {
        return [
            'de' => ['/', 'Über uns,Kontakt,Verweise,'],
            'en' => ['/en/', 'About us,Imprint,References,'],
            'en-GB' => ['/en-gb/', 'About us,Kontakt,Imprint,References (UK),'],
        ];
    }

    #[DataProvider('menuDataProvider')]
    #[Test]
    public function menusFollowTheFallbackChain(string $path, string $expectedMenu): void
    {
        $this->installSiteConfiguration();
        $result = $this->renderFrontend(self::BASE . $path);
        self::assertSame($expectedMenu, $result['scope']['menu']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function languageMenuDataProvider(): array
    {
        return [
            'translated everywhere, en-GB by fallback' => ['/en/about-us', '1:available,2:available,3:available,'],
            'disabled en translation' => ['/kontakt', '1:available,2:unavailable,3:available,'],
            'disabled de translation' => ['/en/imprint', '1:unavailable,2:available,3:available,'],
        ];
    }

    #[DataProvider('languageMenuDataProvider')]
    #[Test]
    public function languageMenuFollowsTheFallbackChain(string $path, string $expectedLanguageMenu): void
    {
        $this->installSiteConfiguration();
        $result = $this->renderFrontend(self::BASE . $path);
        self::assertSame($expectedLanguageMenu, $result['scope']['languageMenu']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function shortcutDataProvider(): array
    {
        return [
            // no chain: RECORDS fetches the shadow by uid and gets no overlay in free mode (plan §4 G #5)
            'de' => ['/verweise', ['Shortcut to element 1 (de)', 'Element 1 (de)', 'Shortcut to element 2 (de)', 'Element 2 (de)']],
            'en' => ['/en/references', ['Shortcut to element 1 (en)', 'Element 1 (en)', 'Shortcut to element 2 (en)']],
            'en-GB' => ['/en-gb/references', ['Shortcut to element 1 (en-GB)', 'Element 1 (en-GB)', 'Shortcut to element 2 (en-GB)', 'Element 2 (de)']],
        ];
    }

    /**
     * @param list<string> $expectedHeaders
     */
    #[DataProvider('shortcutDataProvider')]
    #[Test]
    public function recordsReferencedByShadowUidRenderTheRequestedLanguage(string $path, array $expectedHeaders): void
    {
        $this->installSiteConfiguration();
        $result = $this->renderFrontend(self::BASE . $path);
        self::assertSame(200, $result['status']);
        self::assertSame($expectedHeaders, $this->renderedContentHeaders($result));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function categoryDataProvider(): array
    {
        return [
            // Known gap: without overlays a language-restricted query on sys_category only selects
            // categories of the requested language, but the relation stores the shadow uid.
            'de, no chain' => ['/', [], ['Kategorie']],
            'en, chain 1' => ['/en/', ['Category'], ['Category']],
            'en-GB, chain 2,1 (no en-GB category)' => ['/en-gb/', ['Category'], ['Category']],
        ];
    }

    /**
     * @param list<string> $expectedRestricted
     * @param list<string> $expectedUnrestricted
     */
    #[DataProvider('categoryDataProvider')]
    #[Test]
    public function categoryRelationsToShadowCategories(string $path, array $expectedRestricted, array $expectedUnrestricted): void
    {
        $this->installSiteConfiguration();
        $result = $this->renderFrontend(self::BASE . $path);
        $element = null;
        foreach ($result['structure']['pages:1']['__contents'] ?? [] as $content) {
            if (str_starts_with((string)($content['header'] ?? ''), 'Element 1 ')) {
                $element = $content;
            }
        }
        self::assertIsArray($element);
        $titles = static fn(string $field): array => array_values(array_map(
            static fn(array $category): string => (string)$category['title'],
            // without rendered children, the field holds the relation count
            is_array($element[$field] ?? null) ? $element[$field] : []
        ));
        self::assertSame($expectedRestricted, $titles('categories'), 'language-restricted query');
        self::assertSame($expectedUnrestricted, $titles('categoriesUnrestricted'), 'unrestricted query + overlay listener');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function recordLinkDataProvider(): array
    {
        return [
            // Known gap: without overlays, DatabaseRecordLinkBuilder never asks for a translation, so
            // element 3 (no de translation) is linked although its target renders nothing.
            'de, no chain' => ['/', true, true],
            'en, chain 1: disabled element 2 is not linked' => ['/en/', false, true],
            'en-GB, chain 2,1: element 2 falls back to de' => ['/en-gb/', true, true],
        ];
    }

    #[DataProvider('recordLinkDataProvider')]
    #[Test]
    public function recordLinksToShadowUids(string $path, bool $element2Linked, bool $element3Linked): void
    {
        $this->installSiteConfiguration();
        $links = $this->renderFrontend(self::BASE . $path)['scope']['recordLinks'];
        self::assertIsArray($links);
        self::assertSame($element2Linked, str_contains((string)$links['element2'], 'record=110'), (string)$links['element2']);
        self::assertSame($element3Linked, str_contains((string)$links['element3'], 'record=120'), (string)$links['element3']);
    }
}
