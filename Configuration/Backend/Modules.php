<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;

return [
    'developer' => [
        'access' => 'user',
        'workspaces' => '*',
        'position' => ['after' => 'admin'],
        'path' => '/module/developer',
        'iconIdentifier' => 'module-frontend-studio-developer',
        'labels' => 'frontend_studio.modules.developer',
        'appearance' => [
            'dependsOnSubmodules' => true,
            'promotesSingleSubmoduleToStandalone' => true,
        ],
        'showSubmoduleOverview' => true,
    ],
    'developer_frontendstudio' => [
        'parent' => 'developer',
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/developer/frontend-studio',
        'iconIdentifier' => 'module-frontend-studio-extension',
        'navigationComponent' => '@andersundsehr/frontend-studio/backend/component-tree-container',
        'labels' => 'frontend_studio.modules.frontend_studio',
        'routes' => [
            '_default' => [
                'target' => FrontendStudioModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
