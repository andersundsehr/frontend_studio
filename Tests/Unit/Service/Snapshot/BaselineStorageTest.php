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

final class BaselineStorageTest extends TestCase
{
    public function testProductionAndSubcontextsCanReadButNeverCreate(): void
    {
        $directory = sys_get_temp_dir() . '/snapshot-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/Text.fluid.html', '<p>card</p>');
        $storage = new BaselineStorage(new ComponentWritePolicy());
        $path = $storage->path($directory . '/Text.fluid.html', 'Default');
        self::assertSame($directory . '/Text.fluid.html-snapshots/html-Default.snapshot.html', $path);
        self::assertSame($directory . '/Text.fluid.html-snapshots/html-a-b.snapshot.html', $storage->path($directory . '/Text.fluid.html', 'a/b'));
        self::assertSame($storage->path($directory . '/Text.fluid.html', 'a/b'), $storage->path($directory . '/Text.fluid.html', 'a-b'));
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
            self::assertSame(['.', '..', 'html-Default.snapshot.html'], scandir(dirname($path)));
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
}
