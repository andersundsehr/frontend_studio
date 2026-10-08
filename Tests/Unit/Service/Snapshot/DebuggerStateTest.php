<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\DebuggerState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

final class DebuggerStateTest extends TestCase
{
    #[DataProvider('stylesheetStates')]
    public function testDebugContentIsPreservedAndStylesheetStateIsRestored(bool $alreadyEchoed): void
    {
        $state = new ReflectionProperty(DebuggerUtility::class, 'stylesheetEchoed');
        $original = $state->getValue();
        $state->setValue(null, $alreadyEchoed);
        try {
            $render = static fn(): string => DebuggerUtility::var_dump('Example value', return: true);
            $first = DebuggerState::withoutStylesheet($render);
            $second = DebuggerState::withoutStylesheet($render);
            self::assertSame($first, $second);
            self::assertStringContainsString('Example value', $first);
            self::assertStringContainsString('extbase-debugger-inline', $first);
            self::assertStringNotContainsString('<style', $first);
            self::assertSame($alreadyEchoed, $state->getValue());
        } finally {
            $state->setValue(null, $original);
        }
    }

    #[DataProvider('stylesheetStates')]
    public function testStylesheetStateIsRestoredWhenRenderingFails(bool $alreadyEchoed): void
    {
        $state = new ReflectionProperty(DebuggerUtility::class, 'stylesheetEchoed');
        $original = $state->getValue();
        $state->setValue(null, $alreadyEchoed);
        $failure = new RuntimeException('Rendering failed');
        try {
            try {
                DebuggerState::withoutStylesheet(static fn(): string => throw $failure);
                self::fail('The rendering exception must propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            self::assertSame($alreadyEchoed, $state->getValue());
        } finally {
            $state->setValue(null, $original);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function stylesheetStates(): iterable
    {
        yield 'first debug output in process' => [false];
        yield 'stylesheet previously emitted' => [true];
    }
}
