<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\EventListener;

use T3thi\StructureLayer\Frontend\TranslationLookup;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Event\BeforePageLanguageOverlayEvent;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Chain-driven output for pages (plan §4 G, step 3): a page translation of the requested
 * language that exists but is disabled blocks the fallback along the chain.
 *
 * There is no after-event for page overlays, and a page overlay must never be null. So the
 * page is dropped from the overlay lookup instead: the shadow stays untranslated, which gives
 * a 404 (PageInformationFactory), an omitted menu item (isPageSuitableForLanguage, l18n_cfg)
 * and an unavailable language menu entry.
 */
#[AsEventListener('structure-layer/page-output-policy')]
final readonly class EnforcePageOutputPolicy
{
    public function __construct(
        private TranslationLookup $translationLookup,
        private Context $context,
    ) {}

    public function __invoke(BeforePageLanguageOverlayEvent $event): void
    {
        $languageAspect = $event->getLanguageAspect();
        $requestedLanguageId = $languageAspect->getId();
        $hasChain = array_filter(
            $languageAspect->getFallbackChain(),
            static fn(mixed $languageId): bool => MathUtility::canBeInterpretedAsInteger($languageId) && (int)$languageId > 0
        ) !== [];
        // Without a chain, the overlay lookup already finds nothing for a disabled translation.
        if ($requestedLanguageId <= 0 || !$hasChain) {
            return;
        }
        $pageIds = array_values(array_filter(array_map(intval(...), $event->getPageIds())));
        $blockedPageIds = $this->translationLookup->findPagesWithDisabledTranslation($pageIds, $requestedLanguageId, $this->context);
        if ($blockedPageIds !== []) {
            $event->setPageIds(array_values(array_diff($event->getPageIds(), $blockedPageIds)));
        }
    }
}
