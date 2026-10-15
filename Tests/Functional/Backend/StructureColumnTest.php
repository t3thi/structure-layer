<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Messaging\FlashMessageService;

/**
 * Phase 3a (plan §4 H): the comparison view with the structure column visible.
 */
final class StructureColumnTest extends AbstractPageModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setPageTsConfig(1, 'mod.web_layout.structureLayer.showStructureColumn = 1');
    }

    private function renderComparison(): string
    {
        return $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
    }

    #[Test]
    public function rowsAreShadowDrivenWithTheStructureColumnFirst(): void
    {
        $html = $this->renderComparison();

        $rows = $this->contentRows($html);
        self::assertSame([
            [[100], [101], [102]],
            [[110], [111], [112]],
            [[120], [121], []],
            [[125], [], [126]],
            [[130]],
            [[140], [141], [142]],
        ], $rows);
        self::assertSame(['0', '1', '2'], $this->headerLanguageIds($html));
    }

    #[Test]
    public function structureColumnIsLabelledStructure(): void
    {
        $html = $this->renderComparison();

        $header = $this->headerCell('0', $html);
        self::assertStringContainsString('Structure', $header);
        self::assertStringContainsString('structure-layer-structure-column', $header);
    }

    #[Test]
    public function shadowCellsAreCompactAndShowTheResolvedLabel(): void
    {
        $html = $this->renderComparison();

        $shadow = $this->recordElement(100, $html);
        self::assertStringContainsString('structure-layer-shadow', $shadow);
        // Resolved at display time (plan §3.2): first translation in site-language order.
        self::assertStringContainsString('Element 1 (de)', $shadow);
        // An en-only element resolves to its English translation.
        self::assertStringContainsString('Element 4 (en only)', $this->recordElement(125, $html));
    }

    #[Test]
    public function noLanguageModeBadgeOrInconsistencyWarningIsShown(): void
    {
        $html = $this->renderComparison();

        // The labels themselves are part of the inline JavaScript label catalog, so match markup only.
        self::assertDoesNotMatchRegularExpression('#>\s*(Connected|Mixed|Free) Mode\s*<#', $html);
        self::assertStringNotContainsString('t3-page-ce-warning', $html);
        self::assertSame([], $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages());
    }

    #[Test]
    public function translationsOfferNewContentAfterThemInTheirLanguage(): void
    {
        $html = $this->renderComparison();

        $translation = $this->recordElement(126, $html);
        self::assertStringContainsString('sys_language_uid=2', $this->newContentUrl($translation));
        self::assertStringContainsString('uid_pid=-126', $this->newContentUrl($translation));
    }

    #[Test]
    public function translationsAreDraggable(): void
    {
        $html = $this->renderComparison();

        self::assertStringContainsString('draggable="true"', $this->recordElement(102, $html));
        self::assertStringContainsString('draggable="true"', $this->recordElement(126, $html));
    }

    #[Test]
    public function allLanguagesRecordSpansEveryColumnAndEditsTheOriginal(): void
    {
        $html = $this->renderComparison();

        $row = $this->tableRowContaining('id="element-tt_content-130"', $html);
        self::assertSame(1, substr_count($row, '<td'));
        self::assertMatchesRegularExpression('#<td\b[^>]*colspan="3"#', $row);
        self::assertStringContainsString('edit%5Btt_content%5D%5B130%5D=edit', $row);
    }
}
