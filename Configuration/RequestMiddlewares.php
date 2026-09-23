<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;

return [
    'frontend' => [
        'andersundsehr/frontend-studio/component-preview' => [
            'target' => ComponentPreviewMiddleware::class,
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
                'typo3/cms-core/response-propagation ',
            ],
        ],
    ],
];
