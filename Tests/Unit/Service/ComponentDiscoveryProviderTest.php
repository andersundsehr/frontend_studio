<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use stdClass;
use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\View\TemplatePaths;

#[CoversClass(ComponentDiscoveryProvider::class)]
final class ComponentDiscoveryProviderTest extends TestCase
{
    #[DataProvider('templatePaths')]
    public function testFiltersSnapshotDirectories(string $templateName, ?string $templatePath, bool $available): void
    {
        $paths = $this->createMock(TemplatePaths::class);
        $paths->method('resolveTemplateFileForControllerAndActionAndFormat')
            ->with('Default', $templateName)->willReturn($templatePath);
        $resolver = $this->createMockForIntersectionOfInterfaces([
            ComponentListProviderInterface::class,
            ComponentTemplateResolverInterface::class,
        ]);
        $resolver->method('getAvailableComponents')->willReturn(['element.text']);
        $resolver->method('resolveTemplateName')->with('element.text')->willReturn($templateName);
        $resolver->method('getTemplatePaths')->willReturn($paths);

        $provider = new ComponentDiscoveryProvider();
        self::assertSame($available ? ['element.text'] : [], $provider->getAvailableComponents($resolver));
        self::assertSame($available, $provider->hasComponent($resolver, 'element.text'));
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function templatePaths(): iterable
    {
        yield 'ordinary component' => ['Element/Text/Text', '/components/Element/Text/Text.fluid.html', true];
        yield 'nested snapshot directory' => ['Element/Text/Text.fluid.html-snapshots/html-Default', '/components/Element/Text/Text.fluid.html-snapshots/html-Default.html', false];
        yield 'flat component pattern' => ['Text.fluid.html-snapshots/html-Default', '/components/Text.fluid.html-snapshots/html-Default.html', false];
        yield 'snapshot directory above template root' => ['Text/Text', '/components/Archive-snapshots/Text/Text.html', false];
        yield 'custom resolver hides snapshot location' => ['Text', '/components/Text.html-snapshots/html-Default.html', false];
        yield 'Windows snapshot path' => ['Text', 'C:\\components\\Text.html-snapshots\\html-Default.html', false];
        yield 'unresolved snapshot template' => ['Text.html-snapshots/html-Default', null, false];
        yield 'unresolved ordinary template' => ['Text/Text', null, true];
        yield 'Windows unresolved snapshot template' => ['Text.html-snapshots\\html-Default', null, false];
        yield 'suffix must end directory name' => ['Text-snapshots-extra/Text', '/components/Text-snapshots-extra/Text.html', true];
        yield 'snapshot suffix in filename is allowed' => ['Text-snapshots', '/components/Text-snapshots.html', true];
        yield 'ordinary snapshots folder is allowed' => ['Snapshots/Text', '/components/Snapshots/Text.html', true];
        yield 'empty wildcard matches directory' => ['-snapshots/Text', '/components/-snapshots/Text.html', false];
    }

    public function testNormalizesNamesWithoutATemplateResolver(): void
    {
        $resolver = $this->createMock(ComponentListProviderInterface::class);
        $resolver->method('getAvailableComponents')->willReturn(['teaser', 'card', 'card', '', 'card2']);

        self::assertSame(['card', 'card2', 'teaser'], new ComponentDiscoveryProvider()->getAvailableComponents($resolver));
    }

    public function testIgnoresDelegatesWithoutAComponentList(): void
    {
        $provider = new ComponentDiscoveryProvider();
        self::assertSame([], $provider->getAvailableComponents(new stdClass()));
        self::assertFalse($provider->hasComponent(new stdClass(), 'element.text'));
    }
}
