<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Control\RichText;

use Andersundsehr\FrontendStudio\Control\RichText\RichTextProvider;
use Andersundsehr\FrontendStudio\Control\RichText\RichTextValue;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML;

final class RichTextValueTest extends TestCase
{
    public function testPreservesFormattingAndEmptyText(): void
    {
        self::assertSame('', (string)new RichTextValue(''));
        self::assertSame('<p><strong>Bold</strong> <em>italic</em></p>', (string)new RichTextValue('<p><strong>Bold</strong> <em>italic</em></p>'));
        self::assertInstanceOf(UnsafeHTML::class, new RichTextProvider()->stringable('<p>Text</p>'));
        self::assertInstanceOf(UnsafeHTML::class, new RichTextProvider()->unsafeHtml('<p>Text</p>'));
    }

    public function testRejectsExecutableHtmlAndDangerousLinks(): void
    {
        $html = (string)new RichTextValue('<script>alert(1)</script><p onclick="alert(1)">Hi</p><a href="javascript:alert(1)">link</a><img src="x" onerror="alert(1)">');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' onclick=', $html);
        self::assertStringNotContainsString(' onerror=', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('<p>Hi</p>', $html);
    }
}
