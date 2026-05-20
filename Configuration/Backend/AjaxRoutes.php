<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Controller\ComponentTreeController;

return [
    'frontend_studio_component_tree_data' => [
        'path' => '/frontend-studio/component-tree/fetch-data',
        'target' => ComponentTreeController::class . '::fetchDataAction',
    ],
    'frontend_studio_component_tree_filter' => [
        'path' => '/frontend-studio/component-tree/filter-data',
        'target' => ComponentTreeController::class . '::filterDataAction',
    ],
    'frontend_studio_component_tree_rename_variant' => [
        'path' => '/frontend-studio/component-tree/rename-variant',
        'target' => ComponentTreeController::class . '::renameVariantAction',
    ],
    'frontend_studio_component_tree_create_variant' => [
        'path' => '/frontend-studio/component-tree/create-variant',
        'target' => ComponentTreeController::class . '::createVariantAction',
    ],
    'frontend_studio_component_tree_delete_variant' => [
        'path' => '/frontend-studio/component-tree/delete-variant',
        'target' => ComponentTreeController::class . '::deleteVariantAction',
    ],
    'frontend_studio_component_tree_update_variant_values' => [
        'path' => '/frontend-studio/component-tree/update-variant-values',
        'target' => ComponentTreeController::class . '::updateVariantValuesAction',
    ],
    'frontend_studio_component_tree_download_component' => [
        'path' => '/frontend-studio/component-tree/download-component',
        'target' => ComponentTreeController::class . '::downloadComponentAction',
    ],
];
