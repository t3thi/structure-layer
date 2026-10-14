<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Phase 1: hreflang follows the chain-driven availability; the structure language never
 * appears, x-default is the primary (first enabled) language.
 */
final class HrefLangTest extends AbstractStructureLayerTestCase
{
    protected array $coreExtensionsToLoad = ['seo'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ChainOutput.csv');
        $this->setUpFrontendRootPage(1, ['EXT:structure_layer/Tests/Functional/Frontend/Fixtures/HrefLang.typoscript']);
        $this->installSiteConfiguration();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function hrefLangDataProvider(): array
    {
        $base = 'https://structure.localhost';
        return [
            'translated in de and en, en-GB by page fallback' => ['/en/about-us', [
                'de-DE' => $base . '/ueber-uns',
                'en-US' => $base . '/en/about-us',
                'en-GB' => $base . '/en-gb/about-us',
                'x-default' => $base . '/ueber-uns',
            ]],
            'en translation disabled' => ['/kontakt', [
                'de-DE' => $base . '/kontakt',
                'en-GB' => $base . '/en-gb/kontakt',
                'x-default' => $base . '/kontakt',
            ]],
            'de translation disabled: x-default is the first available language' => ['/en/imprint', [
                'en-US' => $base . '/en/imprint',
                'en-GB' => $base . '/en-gb/imprint',
                'x-default' => $base . '/en/imprint',
            ]],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('hrefLangDataProvider')]
    #[Test]
    public function hrefLangFollowsTheFallbackChain(string $path, array $expected): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://structure.localhost' . $path));
        self::assertSame(200, $response->getStatusCode());
        preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', (string)$response->getBody(), $matches);
        self::assertSame($expected, array_combine($matches[1], $matches[2]));
    }
}
