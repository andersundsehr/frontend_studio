<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Controller;

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(FrontendStudioModuleController::class)]
final class FrontendStudioModuleControllerSidebarHeightTest extends TestCase
{
    #[DataProvider('sidebarHeights')]
    public function testReadsSidebarHeight(mixed $height, ?int $expected): void
    {
        $controller = new ReflectionClass(FrontendStudioModuleController::class)->newInstanceWithoutConstructor();
        $settings = ['frontendStudio' => ['variantView' => ['sidebarWidth' => 420, 'sidebarHeight' => $height]]];

        self::assertSame(
            $expected,
            new ReflectionMethod(FrontendStudioModuleController::class, 'getVariantSidebarHeight')->invoke($controller, $settings),
        );
        self::assertSame(
            420,
            new ReflectionMethod(FrontendStudioModuleController::class, 'getVariantSidebarWidth')->invoke($controller, $settings),
        );
    }

    /**
     * @return array<string, array{mixed, ?int}>
     */
    public static function sidebarHeights(): array
    {
        return [
            'missing height' => [null, null],
            'integer height' => [480, 480],
            'numeric string' => ['360', 360],
            'short viewport height' => [80, 80],
            'zero' => [0, 0],
            'negative integer' => [-1, 0],
            'empty string' => ['', null],
            'CSS length' => ['45vh', null],
            'negative string' => ['-1', null],
            'float' => [360.5, null],
            'boolean' => [true, null],
            'array' => [[], null],
        ];
    }

    public function testUsesDefaultHeightWhenPreferenceIsAbsent(): void
    {
        $controller = new ReflectionClass(FrontendStudioModuleController::class)->newInstanceWithoutConstructor();

        self::assertNull(
            new ReflectionMethod(FrontendStudioModuleController::class, 'getVariantSidebarHeight')->invoke($controller, []),
        );
    }
}
