<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(PreviewContextResolver::class)]
final class PreviewContextResolverTest extends TestCase
{
    public function testResolvesExplicitSiteAndLanguage(): void
    {
        $site = $this->createSite('target', 'target.test');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['target' => $site]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertSame(
            ['target', 'de'],
            $resolver->resolve(new ServerRequest('https://incoming.test/__frontendStudio/preview'), 'target', 'de'),
        );
    }

    public function testUsesTheRequestOriginAndFirstLanguageWhenContextIsOmitted(): void
    {
        $firstSite = $this->createSite('first', 'first.test');
        $originSite = $this->createSite('origin', 'incoming.test');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([
            'first' => $firstSite,
            'origin' => $originSite,
        ]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertSame(
            ['origin', 'en'],
            $resolver->resolve(new ServerRequest('https://INCOMING.test:443/__frontendStudio/preview')),
        );
    }

    public function testFallsBackToTheFirstSiteAndLanguageForUnknownValuesAndOrigin(): void
    {
        $firstSite = $this->createSite('first', 'first.test');
        $secondSite = $this->createSite('second', 'second.test');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([
            'first' => $firstSite,
            'second' => $secondSite,
        ]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertSame(
            ['first', 'en'],
            $resolver->resolve(new ServerRequest('https://incoming.test/__frontendStudio/preview'), 'missing', 'missing'),
        );
    }

    public function testUsesTheFirstConfiguredLanguageWhenItsIdIsNotZero(): void
    {
        $site = new Site('nonzero', 1, [
            'base' => 'https://nonzero.test/',
            'languages' => [
                3 => [
                    'languageId' => 3,
                    'title' => 'German',
                    'locale' => 'de-AT',
                    'hreflang' => 'de',
                    'base' => 'https://nonzero.test/',
                ],
                8 => [
                    'languageId' => 8,
                    'title' => 'English',
                    'locale' => 'en-US',
                    'hreflang' => 'en',
                    'base' => 'https://nonzero.test/en/',
                ],
            ],
        ]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['nonzero' => $site]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertSame(['nonzero', 'de'], $resolver->resolve(new ServerRequest('/__frontendStudio/preview')));
    }

    public function testReturnsNullWhenNoSitesAreConfigured(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertNull($resolver->resolve(new ServerRequest('https://incoming.test/__frontendStudio/preview')));
    }

    public function testReturnsAnEmptyLanguageWhenTheSelectedSiteHasNoEnabledLanguages(): void
    {
        $site = new Site('empty', 1, [
            'base' => '/',
            'languages' => [
                0 => [
                    'languageId' => 0,
                    'title' => 'Disabled',
                    'locale' => 'en-US',
                    'hreflang' => 'en',
                    'base' => '/',
                    'enabled' => false,
                ],
            ],
        ]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['empty' => $site]);
        $resolver = new PreviewContextResolver($siteFinder);

        self::assertSame(['empty', ''], $resolver->resolve(new ServerRequest('/__frontendStudio/preview')));
    }

    private function createSite(string $identifier, string $host): Site
    {
        return new Site($identifier, 1, [
            'base' => 'https://' . $host . '/',
            'languages' => [
                0 => [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en-US',
                    'hreflang' => 'en',
                    'base' => 'https://' . $host . '/',
                ],
                1 => [
                    'languageId' => 1,
                    'title' => 'German',
                    'locale' => 'de-AT',
                    'hreflang' => 'de',
                    'base' => 'https://' . $host . '/de/',
                ],
            ],
        ]);
    }
}
