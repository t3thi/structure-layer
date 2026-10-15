<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\PageViewMode;

/**
 * Phase 3b (plan §4 H): the Editing Language replaces "default language" in the Layout module.
 * - It is always a concrete language, never the structure layer; default: the primary language.
 * - Choosing a language in the selector makes it the Editing Language, for every page.
 * - Single-language view: the Editing Language. Comparison view: the Editing Language first,
 *   the structure column hidden, rows still shadow-driven.
 * - A page missing in the Editing Language shows an empty state with "Translate page".
 */
final class EditingLanguageTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function layoutViewShowsThePrimaryLanguageByDefault(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertSame([101, 111, 121, 130, 141], $this->renderedContentUids($html));
        self::assertStringContainsString('Startseite', $this->pageTitle($html));
    }

    #[Test]
    public function structureLayerIsNotOfferedInTheLanguageSelector(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertStringNotContainsString('languages%5B0%5D=0', $html);
        self::assertStringContainsString('languages%5B0%5D=1', $html);
        self::assertStringContainsString('languages%5B0%5D=2', $html);
    }

    #[Test]
    public function selectingTheStructureLayerKeepsTheEditingLanguage(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [0]]);

        self::assertSame([101, 111, 121, 130, 141], $this->renderedContentUids($html));
    }

    #[Test]
    public function selectedLanguageBecomesTheEditingLanguageForEveryPage(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        self::assertSame([102, 112, 126, 130, 142], $this->renderedContentUids($html));

        // Page 20 has no Core page preference; the Editing Language applies nevertheless.
        $html = $this->renderPageModule(20, PageViewMode::LayoutView);
        self::assertStringContainsString('structure-layer-missing-page', $html);

        $html = $this->renderPageModule(1, PageViewMode::LayoutView);
        self::assertSame([102, 112, 126, 130, 142], $this->renderedContentUids($html));
    }

    #[Test]
    public function pageMissingInTheEditingLanguageShowsAnEmptyStateWithTranslatePage(): void
    {
        $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        $html = $this->renderPageModule(20, PageViewMode::LayoutView);

        self::assertSame([], $this->renderedContentUids($html));
        $emptyState = $this->emptyState($html);
        self::assertStringContainsString('English', $emptyState);
        // Available in other languages, with a link that switches the Editing Language
        self::assertStringContainsString('Deutsch', $emptyState);
        self::assertStringContainsString('languages%5B0%5D=1', $emptyState);
        self::assertMatchesRegularExpression(
            '#<typo3-backend-localization-button[^>]*record-type="pages"[^>]*record-uid="20"[^>]*target-language="2"#s',
            $emptyState
        );
        self::assertStringNotContainsString('<typo3-backend-new-content-element-wizard-button', $html);
    }

    #[Test]
    public function comparisonViewHidesTheStructureColumnAndPutsTheEditingLanguageFirst(): void
    {
        $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);

        self::assertSame(['2', '1'], $this->headerLanguageIds($html));
        self::assertSame([
            [[102], [101]],
            [[112], [111]],
            [[], [121]],
            [[126], []],
            [[130]],
            [[142], [141]],
        ], $this->contentRows($html));
        self::assertMatchesRegularExpression('#<td\b[^>]*colspan="2"#', $this->tableRowContaining('id="element-tt_content-130"', $html));
    }

    #[Test]
    public function emptyCellsShowWhereTheElementExists(): void
    {
        $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);

        $row = $this->tableRowContaining('id="element-tt_content-121"', $html);
        self::assertStringContainsString('structure-layer-placeholder', $row);
        self::assertStringContainsString('Element 3 (de only)', strip_tags($row));
    }

    private function pageTitle(string $html): string
    {
        preg_match('#<typo3-backend-editable-page-title[^>]*pageTitle="([^"]*)"#', $html, $match);
        return $match[1] ?? '';
    }

    private function emptyState(string $html): string
    {
        $start = strpos($html, 'structure-layer-missing-page');
        self::assertNotFalse($start, 'empty state is rendered');
        return substr($html, $start, 4000);
    }
}
