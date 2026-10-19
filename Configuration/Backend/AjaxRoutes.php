<?php

declare(strict_types=1);

use T3thi\StructureLayer\Backend\Controller\EditingLanguageController;

return [
    'structure_layer_editing_language' => [
        'path' => '/structure-layer/editing-language',
        'target' => EditingLanguageController::class . '::setAction',
        'methods' => ['POST'],
    ],
];
