<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\BaselineStorage;
use Andersundsehr\FrontendStudio\Service\ComponentWritePolicy;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\ApplicationContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

final class BaselineStorageTest extends TestCase
{
    public function testProductionAndSubcontextsCanReadButNeverCreate(): void
    {
        $directory = sys_get_temp_dir() . '/snapshot-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/Text.fluid.html', '<p>card</p>');
        $storage = new BaselineStorage(new ComponentWritePolicy());
        $path = $storage->path($directory . '/Text.fluid.html', 'Default', 'main', 'en-us');
        self::assertSame($directory . '/Text.fluid.html-snapshots/html-Default@main@en-us.snapshot.html', $path);
        self::assertSame($directory . '/Text.fluid.html-snapshots/html-a%2Fb@main@en-us.snapshot.html', $storage->path($directory . '/Text.fluid.html', 'a/b', 'main', 'en-us'));
        self::assertNotSame($storage->path($directory . '/Text.fluid.html', 'a/b', 'main', 'en-us'), $storage->path($directory . '/Text.fluid.html', 'a-b', 'main', 'en-us'));
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            foreach (['Production', 'Production/Staging'] as $context) {
                $property->setValue(null, new ApplicationContext($context));
                self::assertNull($storage->read($path));
                try {
                    $storage->create($path, 'baseline');
                    self::fail('Production must not write.');
                } catch (ComponentWriteDeniedException) {
                    self::assertDirectoryDoesNotExist(dirname($path));
                }
            }

            $property->setValue(null, new ApplicationContext('Testing'));
            $storage->create($path, 'baseline');
            try {
                $storage->create($path, 'replacement');
                self::fail('Existing baselines must never be replaced.');
            } catch (RuntimeException) {
                self::assertFileExists($path);
            }

            $property->setValue(null, new ApplicationContext('Production'));
            foreach (['Production', 'Production/Staging'] as $context) {
                $property->setValue(null, new ApplicationContext($context));
                try {
                    $storage->update($path, 'replacement');
                    self::fail('Production must not update snapshots.');
                } catch (ComponentWriteDeniedException) {
                    self::assertSame('baseline', $storage->read($path));
                }
            }

            $property->setValue(null, new ApplicationContext('Testing'));
            $storage->update($path, 'replacement');
            self::assertSame('replacement', $storage->read($path));
            self::assertSame(['.', '..', 'html-Default@main@en-us.snapshot.html'], scandir(dirname($path)));
            unlink($path);
            $storage->update($path, 'new snapshot');
            self::assertSame('new snapshot', $storage->read($path));
        } finally {
            $property->setValue(null, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }

            unlink($directory . '/Text.fluid.html');
            rmdir($directory);
        }
    }

    /**
     * @param array{string, string, string} $first
     * @param array{string, string, string} $second
     */
    #[DataProvider('distinctContexts')]
    public function testVariantSiteAndLanguagePathsCannotCollide(array $first, array $second): void
    {
        $template = tempnam(sys_get_temp_dir(), 'snapshot-context-');
        self::assertNotFalse($template);
        try {
            $storage = new BaselineStorage(new ComponentWritePolicy());
            $firstPath = $storage->path($template, ...$first);
            $secondPath = $storage->path($template, ...$second);
            self::assertNotSame($firstPath, $secondPath);
            self::assertSame($firstPath, $storage->path($template, ...$first));
            self::assertSame($template . '-snapshots', dirname($firstPath));
            self::assertSame($template . '-snapshots', dirname($secondPath));
            self::assertStringEndsWith('.snapshot.html', $firstPath);
        } finally {
            unlink($template);
        }
    }

    /** @return iterable<string, array{array{string, string, string}, array{string, string, string}}> */
    public static function distinctContexts(): iterable
    {
        yield 'different variants' => [['Default', 'main', 'en'], ['Other', 'main', 'en']];
        yield 'different sites' => [['Default', 'main', 'en'], ['Default', 'other', 'en']];
        yield 'different languages' => [['Default', 'main', 'en'], ['Default', 'main', 'de']];
        yield 'different hreflang regions' => [['Default', 'main', 'en-us'], ['Default', 'main', 'en-gb']];
        yield 'slash and hyphen variants' => [['a/b', 'main', 'en'], ['a-b', 'main', 'en']];
        yield 'slash and hyphen sites' => [['Default', 'a/b', 'en'], ['Default', 'a-b', 'en']];
        yield 'slash and hyphen languages' => [['Default', 'main', 'a/b'], ['Default', 'main', 'a-b']];
        yield 'variant and site delimiter' => [['a@b', 'c', 'en'], ['a', 'b@c', 'en']];
        yield 'site and language delimiter' => [['Default', 'a@b', 'c'], ['Default', 'a', 'b@c']];
        yield 'variant percent escape is literal' => [['a/b', 'main', 'en'], ['a%2Fb', 'main', 'en']];
        yield 'site percent escape is literal' => [['Default', 'a/b', 'en'], ['Default', 'a%2Fb', 'en']];
        yield 'language percent escape is literal' => [['Default', 'main', 'a/b'], ['Default', 'main', 'a%2Fb']];
        yield 'Unicode names stay distinct' => [['Café', 'main', 'en'], ['Cafe', 'main', 'en']];
        yield 'spaces cannot collapse into hyphens' => [['a b', 'main', 'en'], ['a-b', 'main', 'en']];
        yield 'context segments cannot traverse directories' => [['../Default', '../main', '../en'], ['Default', 'main', 'en']];
    }

    public function testSeparateTemplatesUseSeparateSnapshotDirectories(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'snapshot-first-');
        $second = tempnam(sys_get_temp_dir(), 'snapshot-second-');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        try {
            $storage = new BaselineStorage(new ComponentWritePolicy());
            self::assertNotSame($storage->path($first, 'Default', 'main', 'en'), $storage->path($second, 'Default', 'main', 'en'));
        } finally {
            unlink($first);
            unlink($second);
        }
    }
}
