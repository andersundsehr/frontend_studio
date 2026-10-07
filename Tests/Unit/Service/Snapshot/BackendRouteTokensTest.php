<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\BackendRouteTokens;
use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

final class BackendRouteTokensTest extends TestCase
{
    #[DataProvider('generatedUrls')]
    public function testGeneratedRouteUrlsUseFreshRealisticTokensWithoutChangingOtherContent(string $url, bool $escape): void
    {
        $builder = $this->createStub(UriBuilder::class);
        $state = new ReflectionProperty(UriBuilder::class, 'generated');
        $cached = ['route-test' => new Uri($url)];
        $state->setValue($builder, $cached);
        $value = $escape ? htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $url;
        $html = '<a href="' . $value . '">Link</a><div data-uri="' . $value . '">dummyToken</div>';
        $first = BackendRouteTokens::normalize($html, $builder);
        $second = BackendRouteTokens::normalize($html, $builder);
        preg_match_all('/token=([a-f0-9]{64})/', $first, $tokens);
        self::assertCount(2, $tokens[1]);
        self::assertSame($tokens[1][0], $tokens[1][1], 'Repeated URLs keep one token within a sample.');
        self::assertNotSame($first, $second);
        self::assertSame($cached, $state->getValue($builder));
        $mask = static fn(string $output): string => preg_replace('/token=(dummyToken|[a-f0-9]{64})/i', 'token=' . Comparison::MARKER, $output) ?? $output;
        self::assertSame($mask($html), $mask($first));
        self::assertSame($mask($html), new Comparison()->create($first, $second));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function generatedUrls(): iterable
    {
        foreach (['CLI placeholder' => 'dummyToken', 'backend session hash' => str_repeat('abcdef01', 8)] as $name => $token) {
            yield $name . ' relative URL' => ['/typo3/ajax/save?token=' . $token, false];
            yield $name . ' absolute URL' => ['https://example.test/custom-backend/save?token=' . $token, false];
            yield $name . ' escaped parameters and fragment' => ['/typo3/ajax/save?token=' . $token . '&page=1&label=Caf%C3%A9#top', true];
            yield $name . ' token after another parameter' => ['/typo3/ajax/save?page=1&token=' . $token . '&action=save#top', true];
        }
    }

    public function testUnrelatedUrlsAndTokenTextArePreserved(): void
    {
        $builder = $this->createStub(UriBuilder::class);
        $url = '/typo3/ajax/save?token=dummyToken';
        new ReflectionProperty(UriBuilder::class, 'generated')->setValue($builder, ['route-test' => new Uri($url)]);
        $html = '<p>dummyToken token=dummyToken</p><a href="/other?token=dummyToken">Other</a>'
            . '<a href="' . $url . 'Extra">Longer token</a><a href="' . $url . '&page=2">Different URL</a>';
        self::assertSame($html, BackendRouteTokens::normalize($html, $builder));
    }

    public function testDifferentGeneratedRoutesUseDifferentTokens(): void
    {
        $builder = $this->createStub(UriBuilder::class);
        $save = '/typo3/ajax/save?token=dummyToken';
        $copy = '/typo3/ajax/copy?token=dummyToken';
        new ReflectionProperty(UriBuilder::class, 'generated')->setValue($builder, ['route-save' => new Uri($save), 'route-copy' => new Uri($copy)]);
        $html = BackendRouteTokens::normalize('<a href="' . $save . '">Save</a><a href="' . $copy . '">Copy</a>', $builder);
        preg_match_all('/token=([a-f0-9]{64})/', $html, $tokens);
        self::assertCount(2, $tokens[1]);
        self::assertNotSame($tokens[1][0], $tokens[1][1]);
    }

    public function testPublicAndCustomTokenUrlsStayLiteral(): void
    {
        $builder = $this->createStub(UriBuilder::class);
        $public = '/typo3/public?page=1';
        $custom = '/typo3/public?token=custom-value';
        new ReflectionProperty(UriBuilder::class, 'generated')->setValue($builder, ['public' => new Uri($public), 'custom' => new Uri($custom)]);
        $html = '<a href="' . $public . '">Public</a><a href="' . $custom . '">Custom</a>';
        self::assertSame($html, BackendRouteTokens::normalize($html, $builder));
    }
}
