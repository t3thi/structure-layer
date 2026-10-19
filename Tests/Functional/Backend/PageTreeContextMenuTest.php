<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\EditingLanguage;
use TYPO3\CMS\Backend\ContextMenu\ContextMenu;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Page tree context menu: "Enable" / "Disable" act on the translation the tree shows (Editing
 * Language, or the fallback), like the hidden state the tree displays. Core's items would read
 * and write the shadow, which is never hidden (I5): "Enable" never appeared, and "Disable" hid the
 * page in all languages.
 *
 * Fixtures/PageModule.csv: page 30 (de 31, en 32), page 20 (de 21 only).
 */
final class PageTreeContextMenuTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function visibleTranslationOffersDisableForThatTranslation(): void
    {
        $items = $this->contextMenu(30);

        self::assertArrayNotHasKey('enable', $items);
        self::assertSame('disableTranslation', $items['disable']['callbackAction']);
        self::assertSame('@t3thi/structure-layer/context-menu-actions', $items['disable']['additionalAttributes']['data-callback-module']);
        self::assertSame('31', $items['disable']['additionalAttributes']['data-translation-uid']);
        self::assertStringContainsString('Deutsch', $items['disable']['label']);
    }

    #[Test]
    public function hiddenTranslationInTheEditingLanguageOffersEnable(): void
    {
        $this->hide(32);
        $this->setEditingLanguage(2);

        $items = $this->contextMenu(30);

        self::assertArrayNotHasKey('disable', $items);
        self::assertSame('enableTranslation', $items['enable']['callbackAction']);
        self::assertSame('32', $items['enable']['additionalAttributes']['data-translation-uid']);
        self::assertStringContainsString('English', $items['enable']['label']);
    }

    #[Test]
    public function hiddenFallbackTranslationOffersEnableForTheFallback(): void
    {
        $this->hide(21);
        $this->setEditingLanguage(2);

        $items = $this->contextMenu(20);

        self::assertArrayNotHasKey('disable', $items);
        self::assertSame('21', $items['enable']['additionalAttributes']['data-translation-uid']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contextMenu(int $pageId): array
    {
        return $this->get(ContextMenu::class)->getItems('pages', (string)$pageId, 'tree');
    }

    private function hide(int $uid): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => $uid]);
    }

    private function setEditingLanguage(int $languageId): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        $this->get(EditingLanguage::class)->set($this->backendUser, $site, $languageId);
    }
}
