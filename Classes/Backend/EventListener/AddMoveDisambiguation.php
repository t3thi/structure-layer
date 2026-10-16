<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\HiddenPositions;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Layout module: the structural positions each language column does not show, for the drop
 * handler of move-disambiguation.js (plan §5, Phase 5, Option B of 2026-05-29).
 */
#[AsEventListener('structure-layer/move-disambiguation')]
final readonly class AddMoveDisambiguation
{
    public function __construct(
        private HiddenPositions $hiddenPositions,
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageContext = $event->getRequest()->getAttribute('pageContext');
        if (!$pageContext instanceof PageContext || $pageContext->pageId === 0 || !$pageContext->site instanceof Site) {
            return;
        }
        $languageIds = [];
        foreach ($pageContext->site->getAllLanguages() as $language) {
            if ($language->getLanguageId() > 0) {
                $languageIds[] = $language->getLanguageId();
            }
        }
        $map = $this->hiddenPositions->forPage($pageContext->pageId, $languageIds, (int)($GLOBALS['BE_USER']->workspace ?? 0));
        $this->pageRenderer->loadJavaScriptModule('@t3thi/structure-layer/move-disambiguation.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:structure_layer/Resources/Private/Language/locallang.xlf', 'moveDisambiguation.');
        $event->addFooterContent(
            '<script type="application/json" id="structure-layer-hidden-positions">'
            . json_encode((object)$map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
            . '</script>'
        );
    }
}
