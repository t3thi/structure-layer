<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\ContextMenu;

use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ProviderInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Page tree context menu: "Enable" / "Disable" of a shadow page act on the translation the tree
 * shows (ResolvePageTreeTitles): the Editing Language, or the fallback shown instead.
 *
 * Core's PageProvider reads the shadow, which is never hidden (I5), so it only offers "Disable",
 * and "hidden" on a shadow means "in all languages" (DataHandlerHook). This provider runs after
 * it and replaces both items. Pages offer no "hide in all languages" in the backend UI.
 */
final class ShadowPageVisibilityItemProvider implements ProviderInterface
{
    private string $table = '';
    private string $identifier = '';

    public function __construct(
        private readonly ShadowLabelResolver $shadowLabelResolver,
        private readonly EditingLanguage $editingLanguage,
        private readonly SiteFinder $siteFinder,
        private readonly IconFactory $iconFactory,
    ) {}

    public function setContext(string $table, string $identifier, string $context = ''): void
    {
        $this->table = $table;
        $this->identifier = $identifier;
    }

    public function canHandle(): bool
    {
        return $this->table === 'pages' && MathUtility::canBeInterpretedAsInteger($this->identifier) && (int)$this->identifier > 0;
    }

    /**
     * After Core's PageProvider (100).
     */
    public function getPriority(): int
    {
        return 45;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        $current = isset($items['disable']) ? 'disable' : (isset($items['enable']) ? 'enable' : null);
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($current === null || !$backendUser instanceof BackendUserAuthentication) {
            // Core decided the user may not toggle visibility here
            return $items;
        }
        $pageId = (int)$this->identifier;
        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if ($page === null || (int)($page['sys_language_uid'] ?? 0) !== 0) {
            return $items;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return $items;
        }
        $resolved = $this->shadowLabelResolver->resolve('pages', $pageId, $pageId, $this->editingLanguage->get($backendUser, $site), $backendUser->workspace);
        if ($resolved === null || !$backendUser->checkLanguageAccess($resolved['language']->getLanguageId())) {
            unset($items['enable'], $items['disable']);
            return $items;
        }
        $translation = $resolved['record'];
        BackendUtility::workspaceOL('pages', $translation, $backendUser->workspace);
        if (!is_array($translation)) {
            unset($items['enable'], $items['disable']);
            return $items;
        }

        $hidden = (bool)($translation['hidden'] ?? false);
        $name = $hidden ? 'enable' : 'disable';
        $label = $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:' . $name)
            . ' (' . $resolved['language']->getTitle() . ')';
        $item = [
            'type' => 'item',
            'label' => htmlspecialchars($label),
            'icon' => $this->iconFactory->getIcon($hidden ? 'actions-edit-unhide' : 'actions-edit-hide', IconSize::SMALL)->render('inline'),
            'additionalAttributes' => [
                'data-callback-module' => '@t3thi/structure-layer/context-menu-actions',
                'data-translation-uid' => (string)$translation['uid'],
                'data-disable-field' => 'hidden',
            ],
            'callbackAction' => $hidden ? 'enableTranslation' : 'disableTranslation',
        ];
        // Same position in the menu
        $replaced = [];
        foreach ($items as $key => $value) {
            if ($key === $current) {
                $replaced[$name] = $item;
            } elseif ($key !== 'enable' && $key !== 'disable') {
                $replaced[$key] = $value;
            }
        }
        return $replaced;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
