<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

final class ExtLocalconfTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $typo3Configuration;

    protected function setUp(): void
    {
        $this->typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->typo3Configuration;
    }

    public function testExcludesComponentVariantSlotsFromCacheHash(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = ['existing'];

        require dirname(__DIR__, 3) . '/ext_localconf.php';
        require dirname(__DIR__, 3) . '/ext_localconf.php';

        self::assertSame([
            'existing',
            'componentVariant',
            'componentVariantName',
            'componentPath',
            'componentVariantValues',
            'componentVariantSlots',
            'frontendStudioPreviewFormat',
            'site',
            'language',
        ], $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters']);
    }
}
