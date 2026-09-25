<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Psr\Http\Message\ServerRequestInterface;

interface PreviewTypoScriptContextBuilderInterface
{
    public function build(ServerRequestInterface $request): ServerRequestInterface;
}
