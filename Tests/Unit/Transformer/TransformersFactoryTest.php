<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer;

use Throwable;
use Stringable;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use Andersundsehr\FrontendStudio\Transformer\MissingTransformerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use RuntimeException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final class TransformersFactoryTest extends UnitTestCase
{
    public function testMissingTransformerIncludesStructuredArgumentAndFileDetails(): void
    {
        $template = __DIR__ . '/../../Functional/Fixtures/Extensions/preview_site_set/Resources/Private/Components/Card/Card.html';
        $paths = new TemplatePaths();
        $paths->setTemplatePathAndFilename($template);

        /** @var ComponentDefinitionProviderInterface&ComponentTemplateResolverInterface&MockObject $collection */
        $collection = $this->createMockForIntersectionOfInterfaces([
            ComponentDefinitionProviderInterface::class,
            ComponentTemplateResolverInterface::class,
        ]);
        $collection->method('getTemplatePaths')->willReturn($paths);
        $collection->method('resolveTemplateName')->willReturn('Card');
        $collection->method('getComponentDefinition')->willReturn($this->createComponentDefinition(Stringable::class));

        try {
            $this->createSubject()->get($collection, 'Card');
            self::fail('A missing transformer must raise a structured exception.');
        } catch (MissingTransformerException $missingTransformerException) {
            self::assertSame('title', $missingTransformerException->argumentName);
            self::assertSame(Stringable::class, $missingTransformerException->argumentType);
            self::assertSame(substr($template, 0, -5) . '.transformer.php', $missingTransformerException->transformerFile);
            self::assertSame(6790927084, $missingTransformerException->getCode());
        }
    }

    #[DataProvider('validReturnTypesDataProvider')]
    public function testValidateReturnTypeAcceptsCompatibleTypes(string $targetType, string $returnType): void
    {
        $this->expectNotToPerformAssertions();

        $this->createSubject()->validateReturnType(
            $this->createComponentDefinition($targetType),
            $this->createTransformers($returnType),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validReturnTypesDataProvider(): array
    {
        return [
            'exact scalar match' => ['string', 'string'],
            'mixed accepts any return type' => ['mixed', 'string'],
            'array satisfies array-shape-like target' => ['string[]', 'array'],
            'subclass satisfies parent/interface target' => [Throwable::class, RuntimeException::class],
            'exact union type match' => ['string|int', 'string|int'],
            'order different union type match' => ['string|int', 'int|string'],
            'narrower scalar satisfies union target' => ['string|int', 'string'],
            'class satisfies union target' => [Throwable::class . '|' . Stringable::class, RuntimeException::class],
        ];
    }

    #[DataProvider('invalidReturnTypesDataProvider')]
    public function testValidateReturnTypeThrowsForIncompatibleTypes(string $targetType, string $returnType): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(4128088840);

        $this->createSubject()->validateReturnType(
            $this->createComponentDefinition($targetType),
            $this->createTransformers($returnType),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidReturnTypesDataProvider(): array
    {
        return [
            'different scalar type' => ['string', 'int'],
            'broader union does not satisfy narrower target' => ['string', 'string|int'],
        ];
    }

    private function createSubject(): TransformersFactory
    {
        $transformerFactory = new TransformerFactory(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('No services available in unit test container.', 1589909511);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });

        return new TransformersFactory(
            new TypeTransformers($transformerFactory),
            $transformerFactory,
        );
    }

    private function createComponentDefinition(string $targetType): ComponentDefinition
    {
        return new ComponentDefinition(
            'storybook:test',
            [
                'title' => new ArgumentDefinition(
                    'title',
                    $targetType,
                    'Test argument',
                    false,
                    null,
                    false,
                ),
            ],
            false,
            [],
        );
    }

    private function createTransformers(string $returnType): Transformers
    {
        return new Transformers(
            [
                'title' => new Transformer(
                    static fn(): mixed => null,
                    'test',
                    $returnType,
                    [],
                    [],
                ),
            ],
            'test.transformer.php',
        );
    }
}
