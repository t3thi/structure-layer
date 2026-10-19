<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\Middleware\EditingLanguageInitialization;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Domain\Model\Element\ImmediateActionElement;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Layout module: the page tree resolves titles in the Editing Language (Phase 4), so it is
 * reloaded once the Editing Language changed.
 */
#[AsEventListener('structure-layer/refresh-page-tree')]
final readonly class RefreshPageTreeOnEditingLanguageChange
{
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        if ($event->getRequest()->getAttribute(EditingLanguageInitialization::CHANGED_ATTRIBUTE) !== true) {
            return;
        }
        $event->addHeaderContent((string)ImmediateActionElement::dispatchCustomEvent('typo3:pagetree:refresh', null, true));
    }
}
