<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\StructureColumnVisibility;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownGeneric;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Layout module, comparison view: "Show structure layer" in Core's "View" menu (next to "Show
 * hidden content elements"). The link sets the user's choice, see StructureColumnVisibility;
 * the effective state is in page TSconfig, written by EditingLanguageInitialization.
 */
#[AsEventListener('structure-layer/structure-column-toggle')]
final readonly class AddStructureColumnToggle
{
    private const string LABELS = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->getAttribute('route');
        $pageContext = $request->getAttribute('pageContext');
        $moduleData = $request->getAttribute('moduleData');
        if (!$route instanceof Route || $route->getOption('_identifier') !== 'web_layout'
            || !$pageContext instanceof PageContext || $pageContext->pageId === 0
            || !$moduleData instanceof ModuleData
            || PageViewMode::tryFrom((int)$moduleData->get('viewMode')) !== PageViewMode::LanguageComparisonView
        ) {
            return;
        }
        $buttons = $event->getButtons();
        $viewMenu = $this->findViewMenu($buttons);
        if ($viewMenu === null) {
            return;
        }
        $visible = (bool)($pageContext->pageTsConfig['mod']['web_layout']['structureLayer']['showStructureColumn'] ?? false);
        $toggle = $this->componentFactory->createDropDownToggle()
            ->setActive($visible)
            ->setLabel($this->getLanguageService()->sL(self::LABELS . 'structureColumn.toggle'))
            ->setIcon($this->iconFactory->getIcon('flags-multiple', IconSize::SMALL))
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_layout', [
                'id' => $pageContext->pageId,
                StructureColumnVisibility::PARAMETER => $visible ? '0' : '1',
            ]))
            ->setAttribute('class', 'structure-layer-toggle-structure-column');
        $viewMenu->addItem($toggle);
        $event->setButtons($buttons);
    }

    /**
     * Core's "View" menu: the drop-down holding "Show hidden content elements".
     *
     * @param array<array-key, mixed> $buttons
     */
    private function findViewMenu(array $buttons): ?DropDownButton
    {
        foreach ($buttons as $groups) {
            foreach ((array)$groups as $group) {
                foreach ((array)$group as $button) {
                    if (!$button instanceof DropDownButton) {
                        continue;
                    }
                    foreach ($button->getItems() as $item) {
                        if ($item instanceof DropDownGeneric && $item->getTag() === 'typo3-backend-page-layout-toggle-hidden') {
                            return $button;
                        }
                    }
                }
            }
        }
        return null;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
