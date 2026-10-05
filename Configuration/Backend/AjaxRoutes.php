<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Controller\ComponentTreeController;
use Andersundsehr\FrontendStudio\Controller\ComponentPreviewController;
use Andersundsehr\FrontendStudio\Controller\ComponentTransformerController;
use Andersundsehr\FrontendStudio\Controller\ComponentChangeStreamController;

return [
    'frontend_studio_component_preview' => [
        'path' => '/frontend-studio/component/preview',
        'target' => ComponentPreviewController::class . '::renderAction',
    ],
    'frontend_studio_component_create_transformer' => [
        'path' => '/frontend-studio/component/create-transformer',
        'target' => ComponentTransformerController::class . '::createAction',

    ],
    'frontend_studio_component_change_stream' => [
        'path' => '/frontend-studio/component-changes/stream',
        'target' => ComponentChangeStreamController::class . '::streamAction',
    ],
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
    'frontend_studio_component_tree_copy_variant' => [
        'path' => '/frontend-studio/component-tree/copy-variant',
        'target' => ComponentTreeController::class . '::copyVariantAction',
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
