<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Service\ComponentDocumentation;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use InvalidArgumentException;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final readonly class ComponentDocumentationController
{
    public function __construct(
        private ComponentDocumentation $documentation,
        private Context $context,
        private ModuleProvider $moduleProvider,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)) {
            return new JsonResponse(['message' => 'A backend login is required.'], 403);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (
            !$backendUser instanceof BackendUserAuthentication
            || !$this->moduleProvider->accessGranted('admin_frontendstudio', $backendUser)
        ) {
            return new JsonResponse(['message' => 'Access to the Frontend Studio backend module is required.'], 403);
        }

        if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return new JsonResponse(['message' => 'Use GET or POST.'], 405, ['Allow' => 'GET, POST']);
        }

        $body = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        if (!is_array($body) || !is_string($body['identifier'] ?? null)) {
            return new JsonResponse(['message' => 'A component identifier is required.'], 400);
        }

        try {
            if ($request->getMethod() === 'GET') {
                return new JsonResponse($this->documentation->read($body['identifier']));
            }

            if (!is_string($body['markdown'] ?? null) || !is_string($body['revision'] ?? null)) {
                return new JsonResponse(['message' => 'Markdown and its original revision are required.'], 400);
            }

            return new JsonResponse($this->documentation->save($body['identifier'], $body['markdown'], $body['revision']));
        } catch (ComponentWriteDeniedException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->getCode() === 1791201001 ? 409 : 500);
        }
    }
}
