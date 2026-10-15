<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\AllLanguagesVisibility;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Layout module header: "hide / show page in all languages" (plan §4 H, decision 3), sent as
 * "hidden" for the page shadow; DataHandler writes it on every page translation (I5).
 */
#[AsEventListener('structure-layer/page-visibility-toggle')]
final readonly class AddPageVisibilityToggle
{
    private const string LABELS = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private AllLanguagesVisibility $allLanguagesVisibility,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageContext = $request->getAttribute('pageContext');
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$pageContext instanceof PageContext || $pageContext->pageId === 0 || $backendUser === null
            || !$pageContext->pagePermissions->isGranted(Permission::PAGE_EDIT)
            || $pageContext->languageInformation->existingTranslations === []
        ) {
            return;
        }
        $toggle = $this->allLanguagesVisibility->getToggle(
            'pages',
            $pageContext->pageId,
            $request->getAttribute('normalizedParams')?->getRequestUri() ?? '',
            $backendUser->workspace
        );
        if ($toggle === null) {
            return;
        }
        $languageService = $this->getLanguageService();
        $label = $languageService->sL(self::LABELS . ($toggle['hiddenEverywhere'] ? 'allLanguages.page.show' : 'allLanguages.page.hide'));
        $html = '<div class="structure-layer-page-visibility mb-3">';
        if ($toggle['hiddenEverywhere']) {
            $html .= '<span class="badge badge-warning structure-layer-hidden-everywhere me-2">'
                . htmlspecialchars($languageService->sL(self::LABELS . 'allLanguages.page.hiddenEverywhere')) . '</span>';
        }
        $html .= '<a class="btn btn-default btn-sm structure-layer-toggle-all-languages" href="' . htmlspecialchars($toggle['url']) . '">'
            . $this->iconFactory->getIcon($toggle['icon'], IconSize::SMALL, 'flags-multiple')->render()
            . ' ' . htmlspecialchars($label) . '</a></div>';
        $event->addHeaderContent($html);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
