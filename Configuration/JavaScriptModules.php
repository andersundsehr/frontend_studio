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
        '@andersundsehr/frontend-studio/vendor/code-highlighting' => 'EXT:frontend_studio/Resources/Public/Contrib/code-highlighting.js',
        '@andersundsehr/frontend-studio/vendor/inline-documentation-editor' => 'EXT:frontend_studio/Resources/Public/Contrib/inline-documentation-editor.js',
        '@andersundsehr/frontend-studio/vendor/markdown-converter' => 'EXT:frontend_studio/Resources/Public/Contrib/markdown-converter.js',
        '@andersundsehr/frontend-studio/backend/' => 'EXT:frontend_studio/Resources/Public/JavaScript/Backend/',
    ],
];
