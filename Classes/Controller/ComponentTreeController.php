<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Service\ComponentFolderArchiveProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final readonly class ComponentTreeController
{
    public function __construct(
        private ComponentTreeDataProvider $componentTreeDataProvider,
        private ComponentFolderArchiveProvider $componentFolderArchiveProvider,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function fetchDataAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->componentTreeDataProvider->getTreeNodes());
    }

    public function filterDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $searchTerm = isset($queryParams['q']) ? (string)$queryParams['q'] : '';

        return new JsonResponse($this->componentTreeDataProvider->getFilteredTreeNodes($searchTerm));
    }

    public function renameVariantAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'The request body is invalid.',
            ], 400);
        }

        $identifier = isset($body['identifier']) ? (string)$body['identifier'] : '';
        $name = isset($body['name']) ? (string)$body['name'] : '';

        try {
            return new JsonResponse([
                'success' => true,
                'variant' => $this->componentTreeDataProvider->renameVariant($identifier, $name),
            ]);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable $throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function createVariantAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'The request body is invalid.',
            ], 400);
        }

        $identifier = isset($body['identifier']) ? (string)$body['identifier'] : '';
        $name = isset($body['name']) ? (string)$body['name'] : '';

        try {
            return new JsonResponse([
                'success' => true,
                'variant' => $this->componentTreeDataProvider->createVariant($identifier, $name),
            ]);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable $throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function deleteVariantAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'The request body is invalid.',
            ], 400);
        }

        $identifier = isset($body['identifier']) ? (string)$body['identifier'] : '';

        try {
            return new JsonResponse([
                'success' => true,
                'variant' => $this->componentTreeDataProvider->deleteVariant($identifier),
            ]);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable $throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function downloadComponentAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = isset($queryParams['identifier']) ? (string)$queryParams['identifier'] : '';

        try {
            $archive = $this->componentFolderArchiveProvider->createArchive($identifier);
            $response = $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($archive['filename'], '"\\') . '"')
                ->withHeader('Content-Transfer-Encoding', 'binary')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Cache-Control', 'no-cache, no-store')
                ->withBody($this->streamFactory->createStreamFromFile($archive['path']));

            @unlink($archive['path']);

            return $response;
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable $throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], $throwable->getCode() === 404 ? 404 : 500);
        }
    }

    public function updateVariantValuesAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'The request body is invalid.',
            ], 400);
        }

        $identifier = isset($body['identifier']) ? (string)$body['identifier'] : '';
        $values = isset($body['values']) && is_array($body['values']) ? $body['values'] : [];

        try {
            return new JsonResponse([
                'success' => true,
                'variant' => $this->componentTreeDataProvider->updateVariantValues($identifier, $values),
            ]);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable $throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}
