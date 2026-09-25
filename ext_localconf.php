<?php

declare(strict_types=1);

$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_values(array_unique([
    ...($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? []),
    'componentVariant',
    'componentVariantName',
    'componentPath',
    'componentVariantValues',
    'componentVariantSlots',
    'frontendStudioPreviewFormat',
    'site',
    'language',
]));
