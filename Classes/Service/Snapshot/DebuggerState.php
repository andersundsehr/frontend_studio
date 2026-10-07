<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Keep TYPO3's shared debugger stylesheet out of component HTML snapshots.
 *
 * Inline f:debug output emits its stylesheet only once per PHP process, controlled
 * by DebuggerUtility::$stylesheetEchoed. Rendering a variant twice would otherwise
 * include a style tag in the first sample but omit it from the second. This
 * structural difference cannot be handled by inline dynamic markers and causes
 * an unsafe snapshot alignment error.
 *
 * FrontendRenderer::render() wraps component rendering in withoutStylesheet(),
 * which temporarily overrides the protected flag to true. Debug content remains
 * in the snapshot, while the shared stylesheet is omitted from both samples.
 * The original flag is restored in finally, including when rendering throws.
 * Inheritance provides access to the flag because TYPO3 has no public switch.
 *
 * @see FrontendRenderer::render()
 * @see self::withoutStylesheet()
 */
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
