<?php

declare(strict_types=1);

return [
    'dependencies' => [
        'backend',
        'core',
        'rte_ckeditor',
    ],
    'tags' => [
        'backend.module',
        'backend.navigation-component',
    ],
    'imports' => [
        '@andersundsehr/frontend-studio/vendor/markdown-converter' => 'EXT:frontend_studio/Resources/Public/Contrib/markdown-converter.js',
        '@andersundsehr/frontend-studio/backend/' => 'EXT:frontend_studio/Resources/Public/JavaScript/Backend/',
    ],
];
