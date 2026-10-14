<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Spike;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;

/**
 * Spike S5 baseline: what unpatched Core renders for the structure site. Not a target, only the
 * "before" picture for Phase 1. Shadows have empty headers, so "" in the list is a rendered shadow.
 */
final class OutputBaselineSpikeTest extends AbstractStructureLayerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StructureSite.csv');
        $this->setUpFrontendRootPage(1, ['EXT:structure_layer/Tests/Functional/Fixtures/Frontend/JsonRenderer.typoscript']);
        $this->installSiteConfiguration();
    }

    public static function urlDataProvider(): array
    {
        return [
            'de' => ['https://structure.localhost/'],
            'en' => ['https://structure.localhost/en/'],
            'en-gb' => ['https://structure.localhost/en-gb/'],
            'en-gb about (missing page translation)' => ['https://structure.localhost/en-gb/about-us'],
        ];
    }

    #[DataProvider('urlDataProvider')]
    #[Test]
    public function recordUnpatchedOutput(string $url): void
    {
        $result = $this->renderFrontend($url);
        fwrite(STDERR, LF . 'BASELINE ' . $url . ' status=' . $result['status'] . ' scope=' . json_encode($result['scope']) . ' headers=' . json_encode($this->renderedContentHeaders($result), JSON_UNESCAPED_UNICODE) . LF);
        self::assertContains($result['status'], [200, 404]);
    }
}
