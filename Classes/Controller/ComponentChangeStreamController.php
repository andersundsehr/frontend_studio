<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Http\ComponentChangeEventStream;
use Andersundsehr\FrontendStudio\Service\ComponentTemplateRootWatcher;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;

#[AsController]
final readonly class ComponentChangeStreamController
{
    public function __construct(
        private ComponentTemplateRootWatcher $componentTemplateRootWatcher,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function streamAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new ComponentChangeEventStream($this->componentTemplateRootWatcher));
    }
}
