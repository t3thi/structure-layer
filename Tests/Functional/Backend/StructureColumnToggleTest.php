<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\StructureColumnVisibility;
use TYPO3\CMS\Backend\View\PageViewMode;

/**
 * Layout module "View" menu: "Show structure layer" toggles the structure column of the
 * comparison view per backend user. Without a choice, page TSconfig
 * mod.web_layout.structureLayer.showStructureColumn applies (default 0).
 */
final class StructureColumnToggleTest extends AbstractPageModuleTestCase
{
    /**
     * @param array<string, mixed> $queryParams
     */
    private function renderComparison(array $queryParams = []): string
    {
        return $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]] + $queryParams);
    }

    #[Test]
    public function viewMenuOffersTheToggleInactiveByDefault(): void
    {
        $html = $this->renderComparison();

        self::assertSame(['1', '2'], $this->headerLanguageIds($html));
        $toggle = $this->toggle($html);
        self::assertStringContainsString('data-dropdowntoggle-status="inactive"', $toggle);
        self::assertStringContainsString('showStructure=1', html_entity_decode($toggle));
        self::assertStringContainsString('Show structure layer', $toggle);
    }

    #[Test]
    public function enablingShowsTheStructureColumnAndIsRemembered(): void
    {
        $html = $this->renderComparison(['showStructure' => '1']);

        self::assertSame(['0', '1', '2'], $this->headerLanguageIds($html));
        $toggle = $this->toggle($html);
        self::assertStringContainsString('data-dropdowntoggle-status="active"', $toggle);
        self::assertStringContainsString('showStructure=0', html_entity_decode($toggle));

        self::assertSame(['0', '1', '2'], $this->headerLanguageIds($this->renderComparison()));
        // Rows stay shadow-driven, with the shadow in the structure column.
        self::assertSame([[100], [101], [102]], $this->contentRows($this->renderComparison())[0]);
    }

    #[Test]
    public function disablingHidesTheStructureColumnAgain(): void
    {
        // Not via a first render: ButtonBar is a shared service, its buttons would pile up within one test.
        $this->get(StructureColumnVisibility::class)->setVisible($this->backendUser, true);

        $html = $this->renderComparison(['showStructure' => '0']);

        self::assertSame(['1', '2'], $this->headerLanguageIds($html));
        self::assertStringContainsString('data-dropdowntoggle-status="inactive"', $this->toggle($html));
    }

    #[Test]
    public function userChoiceOverridesPageTsConfig(): void
    {
        $this->setPageTsConfig(1, 'mod.web_layout.structureLayer.showStructureColumn = 1');
        self::assertSame(['0', '1', '2'], $this->headerLanguageIds($this->renderComparison()));

        self::assertSame(['1', '2'], $this->headerLanguageIds($this->renderComparison(['showStructure' => '0'])));
    }

    #[Test]
    public function layoutViewDoesNotOfferTheToggle(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertStringContainsString('typo3-backend-page-layout-toggle-hidden', $html);
        self::assertSame('', $this->toggle($html));
    }

    private function toggle(string $html): string
    {
        preg_match('#<a\b[^>]*structure-layer-toggle-structure-column[^>]*>.*?</a>#s', $html, $match);
        return $match[0] ?? '';
    }
}
