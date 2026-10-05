<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Service\ComponentTransformerGenerator;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use InvalidArgumentException;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final readonly class ComponentTransformerController
{
    public function __construct(private ComponentTransformerGenerator $generator, private Context $context)
    {
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['success' => false, 'message' => 'Use POST to create a transformer file.'], 405, ['Allow' => 'POST']);
        }

        if (!$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)) {
            return new JsonResponse(['success' => false, 'message' => 'A backend login is required.'], 403);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['identifier'] ?? null)) {
            return new JsonResponse(['success' => false, 'message' => 'A variant identifier is required.'], 400);
        }

        try {
            return new JsonResponse(['success' => true, ...$this->generator->create($body['identifier'])]);
        } catch (ComponentWriteDeniedException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], $exception->getCode() === 1791193101 ? 409 : 500);
        }
    }
}
