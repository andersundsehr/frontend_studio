<?php

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        'backend.module',
        'backend.navigation-component',
    ],
    'imports' => [
        '@andersundsehr/frontend-studio/backend/' => 'EXT:frontend_studio/Resources/Public/JavaScript/Backend/',
    ],
];
