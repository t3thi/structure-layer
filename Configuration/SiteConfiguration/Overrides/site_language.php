<?php

declare(strict_types=1);

use T3thi\StructureLayer\Backend\SiteConfiguration\FallbackLanguageItems;

defined('TYPO3') or die();

// Plan §4 G: output is decided by "fallbacks" alone, fallbackType is ignored on all sites.
$GLOBALS['SiteConfiguration']['site_language']['columns']['fallbackType']['config']['readOnly'] = true;
$GLOBALS['SiteConfiguration']['site_language']['columns']['fallbackType']['description']
    = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:siteLanguage.fallbackType.description';
// The structure layer (0) is never a fallback.
$GLOBALS['SiteConfiguration']['site_language']['columns']['fallbacks']['config']['itemsProcFunc']
    = FallbackLanguageItems::class . '->populate';
