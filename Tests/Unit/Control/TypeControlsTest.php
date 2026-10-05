<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Control;

use Andersundsehr\FrontendStudio\Control\ControlContext;
use Andersundsehr\FrontendStudio\Control\ControlDefinition;
use Andersundsehr\FrontendStudio\Control\TypeControls;
use Andersundsehr\FrontendStudio\Dto\ComponentArgumentMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TypeControlsTest extends TestCase
{
    public function testExactUnionMatchPrecedesWildcardAndPreservesContext(): void
    {
        $registry = new TypeControls();
        $context = new ControlContext(new ComponentArgumentMetadata('body', 'string|\\Stringable', '', false, '', 'default', []), '<p>Hello</p>', true);
        $provider = new readonly class ($context) {
            public function __construct(private ControlContext $expected)
            {
            }

            public function provide(ControlContext $context): ControlDefinition
            {
                TestCase::assertSame($this->expected, $context);
                return new ControlDefinition('EXT:example/Control.html', '@example/control.js');
            }
        };
        $registry->addControl($provider, 'provide', 'html', 'Stringable|string', 0);
        $registry->addControl(new class {
            public function provide(ControlContext $context): ?ControlDefinition
            {
                TestCase::fail('Wildcard must not override an exact match.');
            }
        }, 'provide', 'fallback', '*', 1000);
        self::assertSame('@example/control.js', $registry->resolve($context)?->module);
        self::assertSame('@example/control.js', $registry->resolve($context, 'html')?->module);
    }

    public function testFallbackAndUnknownOverride(): void
    {
        $registry = new TypeControls();
        $context = new ControlContext(new ComponentArgumentMetadata('body', 'string', '', false, '', 'default', []), '', false);
        self::assertNull($registry->resolve($context));
        $this->expectException(InvalidArgumentException::class);
        $registry->resolve($context, 'missing');
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $registry = new TypeControls();
        $provider = new class {
            public function provide(ControlContext $context): null
            {
                return null;
            }
        };
        $registry->addControl($provider, 'provide', 'duplicate', '*', 0);
        $this->expectException(InvalidArgumentException::class);
        $registry->addControl($provider, 'provide', 'duplicate', '*', 1);
    }

    public function testUntrustedTemplatePathsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ControlDefinition('../../etc/passwd', '@example/control.js');
    }
}
