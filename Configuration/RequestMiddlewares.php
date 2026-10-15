<?php

declare(strict_types=1);

use T3thi\StructureLayer\Backend\Middleware\EditingLanguageInitialization;

return [
    'backend' => [
        'structure-layer/editing-language' => [
            'target' => EditingLanguageInitialization::class,
            'after' => [
                'typo3/cms-backend/page-context',
            ],
        ],
    ],
];
