<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\ConfigurationModuleProvider\TypeTransformersProvider;
use Andersundsehr\FrontendStudio\Control\RichText\RichTextProvider;
use Andersundsehr\FrontendStudio\Tests\Functional\Fixtures\ConfigurationModuleTransformer;
use Andersundsehr\FrontendStudio\Transformer\Defaults\DefaultTransformer;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\ProviderRegistry;
use TYPO3\CMS\Lowlevel\Controller\ConfigurationController;
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
        self::assertSame('true', $configuration[Uri::class][0]['active']);
        self::assertSame([
            'url' => ['type' => 'string', 'required' => 'false', 'defaultValue' => 'https://extensions.typo3.org/extension/frontend_studio'],
        ], $configuration[Uri::class][0]['arguments']);
        self::assertSame(RichTextProvider::class, $configuration['Stringable|string'][0]['handler']);
        self::assertSame('stringable', $configuration['Stringable|string'][0]['method']);
        self::assertSame(200, $configuration['Stringable|string'][0]['priority']);
    }

    public function testConfigurationModuleRendersFlagsAndDefaultsUnambiguously(): void
    {
        $GLOBALS['BE_USER'] = new BackendUserAuthentication();
        $registry = $this->get(TypeTransformers::class);
        foreach ([200, 100] as $priority) {
            $registry->addTransformer(new ConfigurationModuleTransformer(), 'transform', 'array', $priority);
        }

        $provider = $this->get(ProviderRegistry::class)->getProvider('frontendStudioTypeTransformers');
        // Exercise the Configuration module's real renderer, including its scalar casts and enum handling.
        $html = new ReflectionMethod(ConfigurationController::class, 'renderTree')->invoke(
            $this->get(ConfigurationController::class),
            $provider->getConfiguration(),
            'type-transformers',
        );
        self::assertIsString($html);
        $document = new DOMDocument();
        self::assertTrue(@$document->loadHTML('<html><body>' . $html . '</body></html>'));
        $xpath = new DOMXPath($document);

        foreach (['true', 'false'] as $index => $active) {
            $expectedValues = [
                'active' => $active,
                'arguments/value/required' => 'true',
                'arguments/value/defaultValue' => '(no default)',
                'arguments/enabled/required' => 'false',
                'arguments/enabled/defaultValue' => 'true',
                'arguments/disabled/defaultValue' => 'false',
                'arguments/nullable/defaultValue' => 'null',
                'arguments/empty/defaultValue' => "''",
                'arguments/count/defaultValue' => '5',
                'arguments/ratio/defaultValue' => '1.5',
                'arguments/target/defaultValue' => '_blank',
                'arguments/options/defaultValue/enabled' => 'true',
                'arguments/options/defaultValue/disabled' => 'false',
                'arguments/options/defaultValue/nullable' => 'null',
                'arguments/options/defaultValue/empty' => "''",
                'arguments/options/defaultValue/nested/values/0' => 'true',
                'arguments/options/defaultValue/nested/values/1' => 'false',
                'arguments/options/defaultValue/nested/values/2' => 'null',
                'arguments/options/defaultValue/nested/values/3' => "''",
                'arguments/options/defaultValue/nested/values/4' => '0',
                'arguments/options/defaultValue/nested/values/5' => '1.5',
                'arguments/options/defaultValue/nested/values/6' => 'value',
                'arguments/options/defaultValue/nested/values/7' => '_blank',
            ];
            foreach ($expectedValues as $path => $expectedValue) {
                $query = '/html/body/ul/li[span/span[@class="treelist-label"]="array"]/div/ul/li[span/span[@class="treelist-label"]="' . $index . '"]';
                foreach (explode('/', $path) as $key) {
                    $query .= '/div/ul/li[span/span[@class="treelist-label"]="' . $key . '"]';
                }

                $query .= '/span/span[@class="treelist-value"]';
                $values = $xpath->query($query);
                self::assertNotFalse($values);
                self::assertSame(1, $values->length, $path);
                self::assertSame($expectedValue, $xpath->evaluate('string(' . $query . ')'), $path);
            }
        }
    }
}
