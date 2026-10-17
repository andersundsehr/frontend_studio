<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\Transformer\Defaults\DefaultTransformer;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use Stringable;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WithoutLowlevelTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [__DIR__ . '/../../..'];

    public function testTransformersRemainAvailableWithoutLowlevel(): void
    {
        self::assertFalse(ExtensionManagementUtility::isLoaded('lowlevel'));
        self::assertSame(
            DefaultTransformer::class . '::stringable',
            $this->get(TypeTransformers::class)->get(Stringable::class)->from,
        );
    }
}
