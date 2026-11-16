<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer;

use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\Identifier;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\LinkTarget;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\NamedConstructorOnly;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TagList;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserCard;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserLink;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Stringable;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TransformerFactoryTest extends UnitTestCase
{
    public function testFromConstructorDerivesInputsFromConstructorParameters(): void
    {
        $transformer = $this->createSubject()->fromConstructor(TeaserLink::class);

        self::assertNotNull($transformer);
        self::assertSame(TeaserLink::class . '::__construct', $transformer->from);
        self::assertSame(TeaserLink::class, $transformer->returnType);
        self::assertSame([], $transformer->services);
        self::assertSame(['title' => 'frontend-studio.rich-text'], $transformer->controls);
        self::assertSame(['url', 'target', 'title', 'position'], array_keys($transformer->arguments));

        $inputs = [];
        foreach ($transformer->arguments as $name => $definition) {
            $inputs[$name] = [$definition->getType(), $definition->isRequired(), $definition->getDefaultValue()];
        }

        self::assertSame([
            'url' => ['string', true, null],
            'target' => [LinkTarget::class, false, LinkTarget::Self],
            'title' => ['string', false, null],
            'position' => ['int', false, null],
        ], $inputs);
    }

    public function testConstructorTransformerPassesInputsAsNamedArguments(): void
    {
        $transformer = $this->createSubject()->fromConstructor(TeaserLink::class);
        self::assertNotNull($transformer);

        $link = $transformer->execute(['title' => 'Read more', 'url' => '/news']);

        self::assertInstanceOf(TeaserLink::class, $link);
        self::assertSame('/news', $link->url);
        self::assertSame(LinkTarget::Self, $link->target);
        self::assertSame('Read more', $link->title);
        self::assertNull($link->position);
    }

    #[DataProvider('typesWithoutConstructorTransformerDataProvider')]
    public function testFromConstructorReturnsNullWhenInputsCannotBeEdited(string $type): void
    {
        self::assertNull($this->createSubject()->fromConstructor($type));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function typesWithoutConstructorTransformerDataProvider(): array
    {
        return [
            'scalar type' => ['string'],
            'unknown class' => ['Vendor\\DoesNotExist'],
            'interface' => [Stringable::class],
            'enum' => [LinkTarget::class],
            'class without constructor parameters' => [stdClass::class],
            'private constructor' => [NamedConstructorOnly::class],
            'nested object parameter' => [TeaserCard::class],
            'variadic parameter' => [TagList::class],
            'ambiguous union parameter' => [Identifier::class],
        ];
    }

    private function createSubject(): TransformerFactory
    {
        return new TransformerFactory(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('No services available in unit test container.', 1763333101);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });
    }
}
