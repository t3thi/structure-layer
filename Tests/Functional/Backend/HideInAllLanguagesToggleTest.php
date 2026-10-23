<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Backend\ContextMenu\ContextMenu;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 3b (plan §4 H, decision 3): every element gets "hide / show in all languages" in its
 * context menu, next to Core's per-language "Disable" / "Enable". The item sends "hidden" for
 * the shadow; the DataHandler hook (Phase 2) writes it on every translation. "Hidden in all
 * languages" is derived (all existing translations hidden), there is no stored flag. Pages have
 * no such toggle in the Layout module; they only show the derived state.
 */
final class HideInAllLanguagesToggleTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function translationContextMenuOffersHideInAllLanguagesForItsShadow(): void
    {
        $items = $this->contextMenu(102);

        self::assertArrayNotHasKey('enableInAllLanguages', $items);
        self::assertSame(['tt_content' => [100 => ['hidden' => '1']]], $this->toggleData($items['disableInAllLanguages']));
        // Right after Core's per-language toggle
        $keys = array_keys($items);
        self::assertSame('disableInAllLanguages', $keys[array_search('disable', $keys, true) + 1]);
    }

    #[Test]
    public function elementHiddenInAllLanguagesIsMarkedAndOffersShowInAllLanguages(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        self::assertStringContainsString('structure-layer-hidden-everywhere', $this->recordElement(142, $html));
        self::assertStringNotContainsString('structure-layer-hidden-everywhere', $this->recordElement(102, $html));

        $items = $this->contextMenu(142);
        self::assertArrayNotHasKey('disableInAllLanguages', $items);
        self::assertSame(['tt_content' => [140 => ['hidden' => '0']]], $this->toggleData($items['enableInAllLanguages']));
    }

    #[Test]
    public function elementHeaderHasNoAllLanguagesButton(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        self::assertStringNotContainsString('structure-layer-toggle-all-languages', $this->recordElement(102, $html));
    }

    #[Test]
    public function allLanguagesRecordAndShadowHaveNoExtraItem(): void
    {
        self::assertArrayNotHasKey('disableInAllLanguages', $this->contextMenu(130));
        self::assertArrayNotHasKey('disableInAllLanguages', $this->contextMenu(100));
    }

    #[Test]
    public function toggleHidesEveryTranslationAndKeepsTheShadowVisible(): void
    {
        $data = $this->toggleData($this->contextMenu(102)['disableInAllLanguages']);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $hidden = $queryBuilder->select('uid', 'hidden')->from('tt_content')
            ->where($queryBuilder->expr()->in('uid', [100, 101, 102]))->orderBy('uid')->executeQuery()->fetchAllKeyValue();
        self::assertSame([100 => 0, 101 => 1, 102 => 1], array_map(intval(...), $hidden));
        self::assertSame([], array_map(strval(...), $this->get(InvariantChecker::class)->check()));

        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        self::assertStringContainsString('structure-layer-hidden-everywhere', $this->recordElement(102, $html));
        self::assertArrayHasKey('enableInAllLanguages', $this->contextMenu(102));
    }

    #[Test]
    public function pageHasNoHideInAllLanguagesButton(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        self::assertStringNotContainsString('structure-layer-toggle-all-languages', $html);
    }

    #[Test]
    public function pageHiddenInAllLanguagesIsMarked(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['l10n_parent' => 1]);

        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        self::assertStringContainsString('structure-layer-hidden-everywhere', $html);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contextMenu(int $uid): array
    {
        return $this->get(ContextMenu::class)->getItems('tt_content', (string)$uid);
    }

    /**
     * The datamap the context menu item sends (context-menu-actions.js).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function toggleData(array $item): array
    {
        self::assertSame('@t3thi/structure-layer/context-menu-actions', $item['additionalAttributes']['data-callback-module']);
        self::assertSame('setHiddenInAllLanguages', $item['callbackAction']);
        $attributes = $item['additionalAttributes'];
        return ['tt_content' => [(int)$attributes['data-shadow-uid'] => [$attributes['data-disable-field'] => $attributes['data-hidden']]]];
    }
}
