<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\EventListener;

use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use TYPO3\CMS\Backend\Routing\Event\BeforePagePreviewUriGeneratedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Page preview ("View" in the page tree context menu, the Layout module, ...): a preview in
 * language 0 would open the disabled structure layer (/_structure/). It shows the Editing
 * Language instead, or the first translation in site-language order if the page lacks it -
 * the same language the page tree takes the title from (ResolvePageTreeTitles).
 */
#[AsEventListener('structure-layer/shadow-page-preview-language')]
final readonly class ResolveShadowPagePreviewLanguage
{
    public function __construct(
        private ShadowLabelResolver $shadowLabelResolver,
        private EditingLanguage $editingLanguage,
        private SiteFinder $siteFinder,
    ) {}

    public function __invoke(BeforePagePreviewUriGeneratedEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $pageId = $event->getPageId();
        if ($event->getLanguageId() !== 0 || $pageId <= 0 || !$backendUser instanceof BackendUserAuthentication) {
            return;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return;
        }
        $resolved = $this->shadowLabelResolver->resolve(
            'pages',
            $pageId,
            $pageId,
            $this->editingLanguage->get($backendUser, $site),
            $backendUser->workspace
        );
        if ($resolved !== null) {
            $event->setLanguageId($resolved['language']->getLanguageId());
        }
    }
}
