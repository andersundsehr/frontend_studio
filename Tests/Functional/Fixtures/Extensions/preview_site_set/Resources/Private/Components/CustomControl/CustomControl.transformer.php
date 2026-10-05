<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;
use Andersundsehr\FrontendStudio\Control\Attribute\Control;

return new ArgumentTransformers(
    body: static fn(#[Control('test.custom')] string $text): Stringable => new readonly class ($text) implements Stringable {
        public function __construct(private string $text)
        {
        }

        public function __toString(): string
        {
            return $this->text;
        }
    },
);
