<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\ConfigurationModuleProvider\TypeTransformersProvider;
use Andersundsehr\FrontendStudio\Control\RichText\RichTextProvider;
use Andersundsehr\FrontendStudio\Transformer\Defaults\DefaultTransformer;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\ProviderRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TypeTransformersProviderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'lowlevel'];

    protected array $testExtensionsToLoad = [__DIR__ . '/../../..'];

    public function testProviderIsRegisteredAndListsBuiltInTransformers(): void
    {
        $GLOBALS['BE_USER'] = new BackendUserAuthentication();
        $provider = $this->get(ProviderRegistry::class)->getProvider('frontendStudioTypeTransformers');
        self::assertInstanceOf(TypeTransformersProvider::class, $provider);
        $configuration = $provider->getConfiguration();

        self::assertCount(6, $configuration);
        self::assertSame(DefaultTransformer::class, $configuration[Uri::class][0]['handler']);
        self::assertSame('uri', $configuration[Uri::class][0]['method']);
        self::assertSame(100, $configuration[Uri::class][0]['priority']);
        self::assertTrue($configuration[Uri::class][0]['active']);
        self::assertSame([
            'url' => ['type' => 'string', 'required' => false, 'defaultValue' => 'https://extensions.typo3.org/extension/frontend_studio'],
        ], $configuration[Uri::class][0]['arguments']);
        self::assertSame(RichTextProvider::class, $configuration['Stringable|string'][0]['handler']);
        self::assertSame('stringable', $configuration['Stringable|string'][0]['method']);
        self::assertSame(200, $configuration['Stringable|string'][0]['priority']);
    }
}
