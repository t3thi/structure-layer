<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\ModifyHrefLangTagsEvent;

/**
 * EXT:seo sets x-default only if the page exists in the first site language. With the
 * structure layer, no language is guaranteed to have the page, so x-default would vanish
 * whenever the primary language lacks it. Use the first available language instead.
 */
#[AsEventListener('structure-layer/hreflang-x-default', after: 'typo3-seo/hreflangGenerator')]
final readonly class AddHrefLangXDefault
{
    public function __invoke(ModifyHrefLangTagsEvent $event): void
    {
        $hrefLangs = $event->getHrefLangs();
        if (count($hrefLangs) < 2 || isset($hrefLangs['x-default'])) {
            return;
        }
        // EXT:seo adds entries in site-language order
        $hrefLangs['x-default'] = reset($hrefLangs);
        $event->setHrefLangs($hrefLangs);
    }
}
