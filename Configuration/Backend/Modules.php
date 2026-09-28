<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;

return [
    'admin_frontendstudio' => [
        'parent' => 'admin',
        'position' => [
            'after' => 'system_reports',
        ],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/admin/frontend-studio',
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
