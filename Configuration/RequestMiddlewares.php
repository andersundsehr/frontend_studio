<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;

return [
    'frontend' => [
        'andersundsehr/frontend-studio/component-preview-context' => [
            'target' => ComponentPreviewContextMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'andersundsehr/frontend-studio/component-preview' => [
            'target' => ComponentPreviewMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/maintenance-mode',
                'typo3/cms-frontend/backend-user-authentication',
            ],
            'before' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/shortcut-and-mountpoint-redirect',
                'typo3/cms-frontend/content-length-headers',
                'typo3/cms-core/response-propagation ',
            ],
        ],
    ],
];
