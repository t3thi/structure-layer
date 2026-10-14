<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Structural field (plan §3.3): maintained once on the shadow and synchronized to every translation.
// Under "free" output only the translation's value is checked, so it must not diverge.
$GLOBALS['TCA']['tt_content']['columns']['fe_group']['l10n_mode'] = 'exclude';
