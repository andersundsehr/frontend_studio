<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-frontend-studio-developer' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/Developer.svg',
    ],
    'module-frontend-studio-extension' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/Extension.svg',
    ],
    'actions-bookmark' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/ActionsBookmark.svg',
    ],
    'frontend-studio-atoms' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/atoms.svg',
    ],
    'frontend-studio-molecules' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/molecules.svg',
    ],
    'frontend-studio-redux' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/redux.svg',
    ],
    'frontend-studio-views' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/views.svg',
    ],
    'frontend-studio-folder' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/folder.svg',
    ],
    'frontend-studio-global' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:frontend_studio/Resources/Public/Icons/folder/global.svg',
    ],
];
