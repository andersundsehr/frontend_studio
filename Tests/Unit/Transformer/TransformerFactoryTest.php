<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer;

use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\Identifier;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\LabelledLink;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\LinkTarget;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\MenuItem;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\NamedConstructorOnly;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TagList;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserCard;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserLink;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserList;
use DateTimeImmutable;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
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

    public function testNestedValueObjectsBecomeDottedInputs(): void
    {
        $transformer = $this->createSubject()->fromConstructor(TeaserCard::class);
        self::assertNotNull($transformer);

        $inputs = [];
        foreach ($transformer->arguments as $name => $definition) {
            $inputs[$name] = [$definition->getType(), $definition->isRequired()];
        }

        self::assertSame([
            'headline' => ['string', true],
            'link.url' => ['string', true],
            'link.target' => [LinkTarget::class, false],
            'link.title' => ['string', false],
            'link.position' => ['int', false],
            'secondaryLink.url' => ['string', false],
            'secondaryLink.target' => [LinkTarget::class, false],
            'secondaryLink.title' => ['string', false],
            'secondaryLink.position' => ['int', false],
        ], $inputs);
        self::assertSame(
            ['link.title' => 'frontend-studio.rich-text', 'secondaryLink.title' => 'frontend-studio.rich-text'],
            $transformer->controls,
        );

        $card = $transformer->execute(['headline' => 'News', 'link' => ['url' => '/news']]);
        self::assertEquals(new TeaserCard('News', new TeaserLink('/news')), $card);
    }

    public function testArraysUseTheElementTypeFromPhpDoc(): void
    {
        $transformer = $this->createSubject()->fromConstructor(TeaserList::class);
        self::assertNotNull($transformer);

        $descriptions = [];
        foreach ($transformer->arguments as $name => $definition) {
            $descriptions[$name] = [$definition->getType(), $definition->getDescription()];
        }

        self::assertSame([
            'headline' => ['string', ''],
            'links' => ['array', 'List of ' . TeaserLink::class],
            'targets' => ['array', 'List of ' . LinkTarget::class],
            'dates' => ['array', 'List of ' . DateTimeImmutable::class],
            'tags' => ['array', ''],
        ], $descriptions);

        $list = $transformer->execute([
            'headline' => 'All',
            'links' => [['url' => '/a', 'target' => 'Blank'], ['url' => '/b', 'position' => '2']],
            'targets' => ['Blank'],
            'dates' => ['start' => '2026-11-16T10:00:00'],
            'tags' => ['x', 1],
        ]);

        self::assertInstanceOf(TeaserList::class, $list);
        self::assertEquals([new TeaserLink('/a', LinkTarget::Blank), new TeaserLink('/b', LinkTarget::Self, null, 2)], $list->links);
        self::assertSame([LinkTarget::Blank], $list->targets);
        self::assertSame(['start'], array_keys($list->dates));
        self::assertSame('10:00', $list->dates['start']->format('H:i'));
        self::assertSame(['x', 1], $list->tags);
    }

    public function testMissingInputOfOptionalNestedObjectIsReported(): void
    {
        $transformer = $this->createSubject()->fromConstructor(TeaserCard::class);
        self::assertNotNull($transformer);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing input "secondaryLink.url"');

        $transformer->execute(['headline' => 'News', 'link' => ['url' => '/news'], 'secondaryLink' => ['title' => 'More']]);
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
            'interface parameter' => [LabelledLink::class],
            'recursive value object' => [MenuItem::class],
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
