<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\PageViewMode;

/**
 * Phase 5 (plan §5, Option B of 2026-05-29): moves are always all-language, but in one language's
 * column, shadows of other languages are invisible. A drop after a visible element is ambiguous if
 * such shadows follow it: the element could land before or after each of them. Only then the Layout
 * module asks where in the shared structure the element goes.
 *
 * The server side is a map per language column and colPos: visible element uid (0 = top of the
 * column) => the structural positions after it that this column does not show. The JavaScript
 * module (move-disambiguation.js) reads it on drop.
 *
 * Fixtures/PageModule.csv, page 1, colPos 0, in structure order:
 * 100 (de 101, en 102), 110 (de 111, en 112), 120 (de 121 only), 125 (en 126 only), 130 ("-1"),
 * 140 (de 141, en 142)
 */
final class MoveDisambiguationTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function hiddenPositionsAreListedPerLanguageColumn(): void
    {
        $map = $this->hiddenPositions($this->renderPageModule(1, PageViewMode::LayoutView));

        // English column: after "Element 2 (en)" comes 120, which has no English translation
        self::assertSame([120], array_column($map[2][0][112] ?? [], 'uid'));
        // German column: after "Element 3 (de only)" comes 125 (English only)
        self::assertSame([125], array_column($map[1][0][121] ?? [], 'uid'));
        // Only ambiguous anchors are listed
        self::assertSame([112], array_keys($map[2][0]));
        self::assertSame([121], array_keys($map[1][0]));
    }

    #[Test]
    public function hiddenPositionsCarryTheLabelOfTheirTranslation(): void
    {
        $map = $this->hiddenPositions($this->renderPageModule(1, PageViewMode::LayoutView));

        self::assertSame(
            ['uid' => 120, 'label' => 'Element 3 (de only)', 'language' => 'Deutsch', 'flag' => 'flags-de'],
            $map[2][0][112][0]
        );
    }

    #[Test]
    public function hiddenPositionsAtTheTopOfAColumnUseAnchorZero(): void
    {
        // 300 has no English translation and sits before everything else
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 300, 'pid' => 1, 'sorting' => 64, 'sys_language_uid' => 0, 'CType' => 'text', 'header' => '']);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 301, 'pid' => 1, 'sorting' => 64, 'sys_language_uid' => 1, 'l18n_parent' => 300, 'CType' => 'text', 'header' => 'Element 0 (de only)']);

        $map = $this->hiddenPositions($this->renderPageModule(1, PageViewMode::LayoutView));

        self::assertSame([300], array_column($map[2][0][0] ?? [], 'uid'));
    }

    #[Test]
    public function moveDisambiguationModuleIsLoaded(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView);

        self::assertStringContainsString('@t3thi/structure-layer/move-disambiguation.js', $html);
    }

    /**
     * @return array<int, array<int, array<int, list<array<string, mixed>>>>> language => colPos => anchor uid => positions
     */
    private function hiddenPositions(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="structure-layer-hidden-positions">(.*?)</script>#s', $html, $match), 'map is rendered');
        return json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
