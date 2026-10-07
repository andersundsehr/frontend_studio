<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/** Keep request-wide debug styling out of component HTML snapshots. */
final class DebuggerState extends DebuggerUtility
{
    /** @param callable(): string $render */
    public static function withoutStylesheet(callable $render): string
    {
        $oldStylesheetEchoed = self::$stylesheetEchoed;
        self::$stylesheetEchoed = true;
        try {
            return $render();
        } finally {
            self::$stylesheetEchoed = $oldStylesheetEchoed;
        }
    }
}
