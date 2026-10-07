<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control\RichText;

use TYPO3\HtmlSanitizer\Builder\CommonBuilder;
use TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML;

final readonly class RichTextValue implements UnsafeHTML
{
    private string $html;

    public function __construct(string $html)
    {
        $this->html = new CommonBuilder()->build()->sanitize($html);
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
