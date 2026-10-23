<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Layout module docheader language selector. The event is dispatched after PageLayoutController
 * built it.
 *
 * - Layout view: no selector. Its single language is the Editing Language, switched in the top
 *   bar (EditingLanguageToolbarItem); a page missing in it offers "Translate page" (MissingPage).
 * - Comparison view: the selector adds languages to the Editing Language, which it does not offer
 *   (EditingLanguageInitialization). Core's counts assume language 0 is always shown and every
 *   translation is selectable; they are corrected to the shown columns (the structure column
 *   only if shown) and the selectable languages.
 */
#[AsEventListener('structure-layer/adjust-language-selector')]
final readonly class AdjustLanguageSelector
{
    private const array TOGGLE_ALL_ICONS = ['actions-selection-elements-all', 'actions-selection-elements-none'];

    public function __construct(
        private StructureLayer $structureLayer,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageContext = $request->getAttribute('pageContext');
        if (!$pageContext instanceof PageContext
            || !$pageContext->site instanceof Site
            || !$this->structureLayer->isStructureSite($pageContext->site)
        ) {
            return;
        }
        $docHeader = $event->getModuleTemplate()->getDocHeaderComponent();
        if (!$this->isComparisonView($pageContext, $request->getAttribute('moduleData'))) {
            $docHeader->setLanguageSelector(null);
            return;
        }
        $selector = $docHeader->getLanguageSelector();
        if (!$selector instanceof DropDownButton) {
            return;
        }
        $shownColumns = count($pageContext->selectedLanguageIds)
            + (($pageContext->pageTsConfig['mod']['web_layout']['structureLayer']['showStructureColumn'] ?? false) ? 1 : 0);
        $selector->setLabel($this->withCount($selector->getLabel(), $shownColumns));

        // The Editing Language comes first (EditingLanguageInitialization)
        $editingLanguageId = $pageContext->selectedLanguageIds[0] ?? 0;
        $selectable = count(array_diff_key($pageContext->languageInformation->existingTranslations, [$editingLanguageId => true]));
        foreach ($selector->getItems() as $item) {
            if ($item instanceof DropDownItem && in_array($item->getIcon()?->getIdentifier(), self::TOGGLE_ALL_ICONS, true)) {
                $item->setLabel($this->withCount((string)$item->getLabel(), $selectable));
            }
        }
    }

    /**
     * Like PageLayoutController::getEffectiveViewMode(): the comparison view needs a translation
     * and may be blinded in page TSconfig.
     */
    private function isComparisonView(PageContext $pageContext, mixed $moduleData): bool
    {
        $comparisonView = PageViewMode::LanguageComparisonView->value;
        return $moduleData instanceof ModuleData
            && (int)$moduleData->get('viewMode') === $comparisonView
            && $pageContext->languageInformation->existingTranslations !== []
            && (bool)($pageContext->getModuleTsConfig('web_layout')['menu']['functions'][$comparisonView] ?? true);
    }

    /**
     * Replaces Core's " (n)" suffix, keeping the localized label.
     */
    private function withCount(string $label, int $count): string
    {
        return (string)preg_replace('/\(\d+\)$/', '(' . $count . ')', $label);
    }
}
