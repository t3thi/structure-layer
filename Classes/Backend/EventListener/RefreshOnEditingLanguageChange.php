<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\Middleware\EditingLanguageInitialization;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Domain\Model\Element\ImmediateActionElement;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Layout module: once the Editing Language changed here (e.g. via the comparison view or
 * "available in" links), the page tree, which resolves titles in it (Phase 4), and the top bar
 * item showing it (toolbar/editing-language-menu.js) are reloaded.
 */
#[AsEventListener('structure-layer/refresh-on-editing-language-change')]
final readonly class RefreshOnEditingLanguageChange
{
    public const string EVENT = 'structure-layer:editing-language:changed';

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        if ($event->getRequest()->getAttribute(EditingLanguageInitialization::CHANGED_ATTRIBUTE) !== true) {
            return;
        }
        $event->addHeaderContent((string)ImmediateActionElement::dispatchCustomEvent('typo3:pagetree:refresh', null, true));
        $event->addHeaderContent((string)ImmediateActionElement::dispatchCustomEvent(self::EVENT, null, true));
    }
}
