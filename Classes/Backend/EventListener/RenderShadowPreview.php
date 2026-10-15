<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use TYPO3\CMS\Backend\View\Event\PageContentPreviewRenderingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Domain\Record;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * A shadow carries no payload (plan §3.2), so its page-module preview is a compact
 * structure cell: the label of a translation plus that translation's flag.
 */
#[AsEventListener('structure-layer/shadow-preview')]
final readonly class RenderShadowPreview
{
    public function __construct(
        private ShadowLabelResolver $shadowLabelResolver,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(PageContentPreviewRenderingEvent $event): void
    {
        $record = $event->getRecord();
        if (!$record instanceof Record || $record->getLanguageId() !== 0) {
            return;
        }
        $context = $event->getPageLayoutContext();
        $resolved = $this->shadowLabelResolver->resolve(
            $event->getTable(),
            $record->getUid(),
            $record->getPid(),
            null,
            $context->getBackendUser()->workspace
        );
        if ($resolved === null) {
            $event->setPreviewContent('<span class="structure-layer-shadow badge badge-danger">no translation</span>');
            return;
        }
        $event->setPreviewContent(
            '<span class="structure-layer-shadow text-body-secondary">'
            . $this->iconFactory->getIcon($resolved['language']->getFlagIdentifier(), IconSize::SMALL)->setTitle($resolved['language']->getTitle())->render()
            . ' ' . htmlspecialchars($resolved['label'])
            . '</span>'
        );
    }
}
