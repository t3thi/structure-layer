<?php

declare(strict_types=1);

use T3thi\StructureLayer\DataHandling\DataHandlerHook;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['structure_layer'] = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['structure_layer'] = DataHandlerHook::class;

// Phase 5: structure operations (move, copy, structural fields, hide/delete in all languages) need this permission
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['structure_layer'] = [
    'header' => 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:permission.header',
    'items' => [
        'structure' => ['LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:permission.structure'],
    ],
];
