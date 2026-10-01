<?php

declare(strict_types=1);

return (int)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] ?? 0) === 1
    ? ['frontend.studio' => ['Andersundsehr\\FrontendStudio\\Components']]
    : [];
