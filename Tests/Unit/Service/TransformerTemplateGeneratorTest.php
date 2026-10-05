<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\TransformerTemplateGenerator;
use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Closure;

final class TransformerTemplateGeneratorTest extends TestCase
{
    public function testMultipleArgumentsIncludeSafeDefaultsAndExplicitTodos(): void
    {
        $template = new TransformerTemplateGenerator()->generate(['payload' => 'stdClass', 'domain' => 'ArrayObject', 'optional' => '?Stringable', 'union' => 'ArrayObject|Stringable', 'items' => 'ArrayObject[]']);
        self::assertTrue($template['hasTodos']);
        $transformers = $this->load($template['source']);
        self::assertSame(['payload', 'domain', 'optional', 'union', 'items'], array_keys($transformers->arguments));
        self::assertInstanceOf(stdClass::class, ($transformers->arguments['payload'])());
        /** @var Closure(string=):stdClass $payload */
        $payload = $transformers->arguments['payload'];
        self::assertSame('Example', $payload('{"value":"Example"}')->value);
        foreach (['domain', 'optional', 'union', 'items'] as $name) {
            try {
                ($transformers->arguments[$name])();
                self::fail('Unknown domain construction must remain a TODO.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('TODO: implement transformer', $exception->getMessage());
            }
        }
    }

    public function testKnownObjectRejectsNonObjectJson(): void
    {
        $template = new TransformerTemplateGenerator()->generate(['payload' => 'stdClass']);
        self::assertFalse($template['hasTodos']);
        $transformers = $this->load($template['source']);
        $this->expectException(InvalidArgumentException::class);
        /** @var Closure(string=):stdClass $payload */
        $payload = $transformers->arguments['payload'];
        $payload('[]');
    }

    public function testArgumentNamesAreQuotedAsData(): void
    {
        $name = "name'); throw new RuntimeException('injected'); //";
        $transformers = $this->load(new TransformerTemplateGenerator()->generate([$name => 'stdClass'])['source']);
        self::assertSame([$name], array_keys($transformers->arguments));
        self::assertInstanceOf(stdClass::class, ($transformers->arguments[$name])());
    }

    #[DataProvider('invalidTypes')]
    public function testInvalidTypesCannotInjectPhp(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TransformerTemplateGenerator()->generate(['payload' => $type]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTypes(): iterable
    {
        yield 'injection' => ["stdClass {}; echo 'injected';"];
        yield 'comment' => ['stdClass/*comment*/'];
        yield 'empty' => [''];
        yield 'unclosed union' => ['stdClass|'];
        yield 'nullable union' => ['?stdClass|string'];
        yield 'void union' => ['void|stdClass'];
        yield 'nullable mixed' => ['?mixed'];
        yield 'nullable null' => ['?null'];
        yield 'bool false' => ['bool|false'];
        yield 'true false' => ['true|false'];
        yield 'iterable array' => ['iterable|array'];
        yield 'mixed union' => ['mixed|ArrayObject'];
    }

    private function load(string $source): ArgumentTransformers
    {
        $path = tempnam(sys_get_temp_dir(), 'transformer-template-');
        self::assertNotFalse($path);
        file_put_contents($path, $source);
        try {
            $result = require $path;
            self::assertInstanceOf(ArgumentTransformers::class, $result);
            return $result;
        } finally {
            unlink($path);
        }
    }
}
