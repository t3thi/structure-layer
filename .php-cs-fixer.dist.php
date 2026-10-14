<?php

declare(strict_types=1);

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__)
    // Skip everything git ignores (.Build, var, config, …).
    ->ignoreVCSIgnored(true)
;

return $config;
