<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Structural fields (plan §3.3, Phase 0 S3): maintained once on the shadow page and synchronized
// to every translation. Page lookup and access checks read the shadow page.
$GLOBALS['TCA']['pages']['columns']['doktype']['l10n_mode'] = 'exclude';
$GLOBALS['TCA']['pages']['columns']['fe_group']['l10n_mode'] = 'exclude';
