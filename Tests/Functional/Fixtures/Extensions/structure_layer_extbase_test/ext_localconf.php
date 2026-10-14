<?php

declare(strict_types=1);

use T3thi\StructureLayerExtbaseTest\Controller\ContentController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::configurePlugin(
    'StructureLayerExtbaseTest',
    'Content',
    [ContentController::class => 'index'],
    [ContentController::class => 'index'],
);
