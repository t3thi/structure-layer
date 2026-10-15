<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\SiteConfiguration\FallbackLanguageItems;
use TYPO3\CMS\Backend\Configuration\SiteTcaConfiguration;

/**
 * Phase 3 (plan §5): the Sites module shows fallbackType read-only, output is decided by
 * "fallbacks" alone (plan §4 G). The structure layer is never offered as a fallback.
 */
final class SiteConfigurationTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function fallbackTypeIsReadOnly(): void
    {
        $tca = $this->get(SiteTcaConfiguration::class)->getTca();

        self::assertTrue($tca['site_language']['columns']['fallbackType']['config']['readOnly'] ?? false);
        self::assertNotEmpty($tca['site_language']['columns']['fallbackType']['description'] ?? '');
    }

    #[Test]
    public function structureLayerIsNotOfferedAsFallback(): void
    {
        $tca = $this->get(SiteTcaConfiguration::class)->getTca();
        self::assertSame(
            FallbackLanguageItems::class . '->populate',
            $tca['site_language']['columns']['fallbacks']['config']['itemsProcFunc'] ?? null
        );

        /** @var array<array-key, mixed> $fieldDefinition */
        $fieldDefinition = ['items' => [], 'row' => ['languageId' => [2]]];
        $this->get(FallbackLanguageItems::class)->populate($fieldDefinition);

        self::assertSame([1, 3], array_column($fieldDefinition['items'], 'value'));
    }
}
