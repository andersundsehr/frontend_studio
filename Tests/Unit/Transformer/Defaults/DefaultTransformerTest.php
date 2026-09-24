<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Defaults;

use Andersundsehr\FrontendStudio\Transformer\Defaults\DefaultTransformer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DefaultTransformerTest extends TestCase
{
    private const string DEFAULT_URL = 'https://extensions.typo3.org/extension/frontend_studio';

    private const string DEFAULT_FILE = 'EXT:frontend_studio/Resources/Public/Image/FrontendStudioDeveloper.png';

    public function testDefinesSaneTypeTransformerDefaults(): void
    {
        $uri = new ReflectionMethod(DefaultTransformer::class, 'uri');
        $file = new ReflectionMethod(DefaultTransformer::class, 'file');
        $typolink = new ReflectionMethod(DefaultTransformer::class, 'typolinkParameter');

        self::assertSame(self::DEFAULT_URL, $uri->getParameters()[0]->getDefaultValue());
        self::assertSame(self::DEFAULT_FILE, $file->getParameters()[0]->getDefaultValue());
        self::assertSame(self::DEFAULT_URL, $typolink->getParameters()[0]->getDefaultValue());
    }
}
