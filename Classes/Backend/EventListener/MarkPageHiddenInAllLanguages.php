<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\AllLanguagesVisibility;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Layout module header: "This page is hidden in all languages" (plan §4 H, decision 3), derived
 * from the page translations. There is no toggle for it here; "hidden" sent for the page shadow
 * still hides every translation (DataHandlerHook, I5).
 */
#[AsEventListener('structure-layer/page-hidden-in-all-languages')]
final readonly class MarkPageHiddenInAllLanguages
{
    private const string LABELS = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private AllLanguagesVisibility $allLanguagesVisibility,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageContext = $event->getRequest()->getAttribute('pageContext');
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$pageContext instanceof PageContext || $pageContext->pageId === 0 || $backendUser === null
            || $pageContext->languageInformation->existingTranslations === []
        ) {
            return;
        }
        $state = $this->allLanguagesVisibility->getState('pages', $pageContext->pageId, $backendUser->workspace);
        if (!($state['hiddenEverywhere'] ?? false)) {
            return;
        }
        $event->addHeaderContent(
            '<div class="structure-layer-page-visibility mb-3"><span class="badge badge-warning structure-layer-hidden-everywhere">'
            . htmlspecialchars($this->getLanguageService()->sL(self::LABELS . 'allLanguages.page.hiddenEverywhere')) . '</span></div>'
        );
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
