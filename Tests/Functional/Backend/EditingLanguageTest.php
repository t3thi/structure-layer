<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\PageViewMode;

/**
 * Phase 3b (plan §4 H): the Editing Language replaces "default language" in the Layout module.
 * - It is always a concrete language, never the structure layer; default: the primary language.
 * - Choosing a language (top bar, "available in" links) makes it the Editing Language, for every page.
 * - The Layout view has no language selector; the comparison view's selector adds languages
 *   to the Editing Language, which is always shown.
 * - Single-language view: the Editing Language. Comparison view: the Editing Language first,
 *   the structure column hidden, rows still shadow-driven.
 * - A page missing in the Editing Language shows an empty state with "Translate page".
 */
final class EditingLanguageTest extends AbstractPageModuleTestCase
{
    private const string PAGE_TREE_REFRESH = 'typo3:pagetree:refresh';
    private const string TOP_BAR_UPDATE = 'structure-layer:editing-language:changed';

    #[Test]
    public function layoutViewShowsThePrimaryLanguageByDefault(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertSame([101, 111, 121, 130, 141], $this->renderedContentUids($html));
        self::assertStringContainsString('Startseite', $this->pageTitle($html));
    }

    #[Test]
    public function layoutViewHasNoLanguageSelector(): void
    {
        // The top bar switches the Editing Language (EditingLanguageToolbarItem).
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertSame('', $this->languageSelector($html));
    }

    #[Test]
    public function comparisonViewSelectorOffersOnlyAdditionalLanguages(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1]]);

        $selector = $this->languageSelector($html);
        // English to add; en-GB as a new page translation. Neither the structure layer nor the Editing Language.
        self::assertSame(['flags-us', 'flags-gb'], $this->selectorFlags($selector));

        $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
        self::assertSame(['flags-de', 'flags-gb'], $this->selectorFlags($this->languageSelector($html)));
    }

    #[Test]
    public function comparisonViewSelectorCountsShownColumnsAndSelectableLanguages(): void
    {
        // Core counts language 0 as always shown, and the Editing Language as selectable.
        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1]]);
        $selector = $this->languageSelector($html);
        self::assertStringContainsString('Languages (1)', $selector);
        self::assertStringContainsString('Check all (1)', $selector);

        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
        $selector = $this->languageSelector($html);
        self::assertStringContainsString('Languages (2)', $selector);
        self::assertStringContainsString('Uncheck all (1)', $selector);

        // The structure column, if shown, is a column of its own
        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2], 'showStructure' => '1']);
        self::assertStringContainsString('Languages (3)', $this->languageSelector($html));
    }

    #[Test]
    public function deselectingAnAdditionalLanguageKeepsTheEditingLanguage(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
        self::assertSame(['1', '2'], $this->headerLanguageIds($html));

        preg_match('#<a\b[^>]*data-dropdowntoggle-status="active"[^>]*href="([^"]*)"|<a\b[^>]*href="([^"]*)"[^>]*data-dropdowntoggle-status="active"#', $this->languageSelector($html), $match);
        parse_str((string)parse_url(html_entity_decode(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')), PHP_URL_QUERY), $query);
        self::assertIsArray($query['languages'] ?? null);

        $html = $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => $query['languages']]);
        self::assertSame(['1'], $this->headerLanguageIds($html));
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
    public function changingTheEditingLanguageRefreshesThePageTree(): void
    {
        // The page tree shows titles in the Editing Language (Phase 4).
        self::assertStringNotContainsString(self::PAGE_TREE_REFRESH, $this->renderPageModule(1, PageViewMode::LayoutView));
        self::assertStringNotContainsString(self::PAGE_TREE_REFRESH, $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [1]]));

        self::assertStringContainsString(self::PAGE_TREE_REFRESH, $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]));

        self::assertStringNotContainsString(self::PAGE_TREE_REFRESH, $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]));
        self::assertStringNotContainsString(self::PAGE_TREE_REFRESH, $this->renderPageModule(20, PageViewMode::LayoutView));
    }

    #[Test]
    public function changingTheEditingLanguageUpdatesTheTopBar(): void
    {
        // EditingLanguageToolbarItem shows the Editing Language; it is reloaded on this event.
        self::assertStringNotContainsString(self::TOP_BAR_UPDATE, $this->renderPageModule(1, PageViewMode::LayoutView));

        self::assertStringContainsString(self::TOP_BAR_UPDATE, $this->renderPageModule(20, PageViewMode::LayoutView, ['languages' => [2]]));

        self::assertStringNotContainsString(self::TOP_BAR_UPDATE, $this->renderPageModule(20, PageViewMode::LayoutView));
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

    /**
     * The docheader's language selector, empty if there is none.
     */
    private function languageSelector(string $html): string
    {
        preg_match('#module-docheader-column-breadcrumb.*?</div>\s*<div class="module-docheader-column">(.*?)</div>\s*</div>\s*<div class="module-docheader module-docheader-buttons#s', $html, $match);
        return trim($match[1] ?? '');
    }

    /**
     * Flags of the selector's items, in order.
     *
     * @return list<string>
     */
    private function selectorFlags(string $selector): array
    {
        $menu = substr($selector, (int)strpos($selector, 'dropdown-menu'));
        preg_match_all('#identifier="(flags-[a-z-]+)"#', $menu, $matches);
        return $matches[1];
    }

    private function emptyState(string $html): string
    {
        $start = strpos($html, 'structure-layer-missing-page');
        self::assertNotFalse($start, 'empty state is rendered');
        return substr($html, $start, 4000);
    }
}
