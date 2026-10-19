<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Backend\Dto\Tree\Status\StatusInformation;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The page tree reads shadow pages (plan §4 H, "Page tree"). Their titles are resolved like every
 * other shadow label: the Editing Language first, otherwise the first available translation in
 * site-language order. A title from another language is marked with that language's flag.
 * Icon and overlay (hidden, ...) follow the same translation; the shadow itself is never hidden.
 */
#[AsEventListener('structure-layer/page-tree-titles')]
final readonly class ResolvePageTreeTitles
{
    public function __construct(
        private ShadowLabelResolver $shadowLabelResolver,
        private EditingLanguage $editingLanguage,
        private SiteFinder $siteFinder,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(AfterPageTreeItemsPreparedEvent $event): void
    {
        $backendUser = $event->getRequest()?->getAttribute('backend.user') ?? $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return;
        }
        $items = $event->getItems();
        foreach ($items as &$item) {
            $pageId = (int)($item['identifier'] ?? 0);
            if ($pageId <= 0 || ($item['recordType'] ?? '') !== 'pages' || (int)($item['_page']['sys_language_uid'] ?? 0) !== 0) {
                continue;
            }
            try {
                $site = $this->siteFinder->getSiteByPageId($pageId);
            } catch (SiteNotFoundException) {
                continue;
            }
            $editingLanguageId = $this->editingLanguage->get($backendUser, $site);
            $resolved = $this->shadowLabelResolver->resolve('pages', $pageId, $pageId, $editingLanguageId, $backendUser->workspace);
            if ($resolved === null) {
                // I4 violation, reported by structure-layer:check
                continue;
            }
            $item['name'] = $resolved['label'];
            // Shadows are never hidden (I5): icon and overlay (hidden, start/end time, nav_hide, ...)
            // show the state of the translation the title comes from.
            $record = $resolved['record'];
            BackendUtility::workspaceOL('pages', $record, $backendUser->workspace);
            if (is_array($record)) {
                $icon = $this->iconFactory->getIconForRecord('pages', $record, IconSize::SMALL);
                $item['icon'] = $icon->getIdentifier();
                $item['overlayIcon'] = $icon->getOverlayIcon()?->getIdentifier() ?? '';
            }
            $languageId = $resolved['language']->getLanguageId();
            if ($editingLanguageId === null || $languageId === $editingLanguageId) {
                continue;
            }
            $label = sprintf(
                $this->getLanguageService()?->sL('LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:pageTree.otherLanguage') ?: 'Not available in %s, title from %s',
                $site->getLanguageById($editingLanguageId)->getTitle(),
                $resolved['language']->getTitle()
            );
            $item['statusInformation'][] = new StatusInformation(
                label: $label,
                icon: $resolved['language']->getFlagIdentifier(),
            );
            $item['tooltip'] = trim((string)($item['tooltip'] ?? '') . ' - ' . $label, ' -');
        }
        unset($item);
        $event->setItems($items);
    }

    private function getLanguageService(): ?LanguageService
    {
        return $GLOBALS['LANG'] ?? null;
    }
}
