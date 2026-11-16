<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer;

use Andersundsehr\FrontendStudio\Transformer\InputPath;
use PHPUnit\Framework\TestCase;

final class InputPathTest extends TestCase
{
    public function testReadsNestedValues(): void
    {
        $values = ['link' => ['url' => '/news', 'title' => null], 'headline' => 'News'];

        self::assertTrue(InputPath::has($values, 'headline'));
        self::assertTrue(InputPath::has($values, 'link.url'));
        self::assertTrue(InputPath::has($values, 'link.title'));
        self::assertFalse(InputPath::has($values, 'link.target'));
        self::assertFalse(InputPath::has($values, 'headline.text'));
        self::assertSame('/news', InputPath::get($values, 'link.url'));
        self::assertNull(InputPath::get($values, 'link.target'));
    }

    public function testWritesNestedValues(): void
    {
        $values = ['link' => ['url' => '/news']];
        InputPath::set($values, 'link.title', 'Read more');
        InputPath::set($values, 'secondaryLink.url', '/more');

        self::assertSame([
            'link' => ['url' => '/news', 'title' => 'Read more'],
            'secondaryLink' => ['url' => '/more'],
        ], $values);
    }
}
