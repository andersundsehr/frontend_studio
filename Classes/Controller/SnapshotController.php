<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use Andersundsehr\FrontendStudio\Service\Snapshot\InlineDiff;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Filesystem\Path;
use TYPO3\CMS\Core\Core\Environment;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[AsController]
final readonly class SnapshotController
{
    public function __construct(
        private Runner $runner,
        private Context $context,
        private SiteFinder $siteFinder,
        private ExtensionConfiguration $extensionConfiguration,
        private ModuleProvider $moduleProvider,
    ) {
    }

    public function runAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['success' => false, 'message' => 'Use POST to run snapshots.'], 405, ['Allow' => 'POST']);
        }

        if (!$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)) {
            return new JsonResponse(['success' => false, 'message' => 'A backend login is required.'], 403);
        }

        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$this->moduleProvider->accessGranted('admin_frontendstudio', $user)) {
            return new JsonResponse(['success' => false, 'message' => 'Frontend Studio module access is required.'], 403);
        }

        if (!(bool)$this->extensionConfiguration->get('frontend_studio', 'enableGuiTesting')) {
            return new JsonResponse(['success' => false, 'message' => 'GUI snapshot testing is disabled in extension settings.'], 403);
        }

        $body = $request->getParsedBody();
        if (
            !is_array($body) || !is_string($body['scope'] ?? null) || !is_string($body['site'] ?? null) || !is_string($body['language'] ?? null)
            || !in_array($body['operation'] ?? null, ['discover', 'run', 'update'], true)
        ) {
            return new JsonResponse(['success' => false, 'message' => 'Provide a scope, site, language and valid operation.'], 400);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($body['site']);
            if (!array_any($site->getLanguages(), static fn(SiteLanguage $language): bool => $language->getHreflang() === $body['language'])) {
                return new JsonResponse(['success' => false, 'message' => 'Unknown site language.'], 400);
            }

            $identifiers = $this->runner->discover($body['scope']);
            if ($body['operation'] === 'discover') {
                return new JsonResponse(['success' => true, 'identifiers' => $identifiers, 'catalog' => $this->runner->discover()], 200, ['Cache-Control' => 'no-store']);
            }

            // One variant per request bounds server execution and allows progress/cancellation.
            if (count($identifiers) !== 1 || $identifiers[0] !== $body['scope']) {
                return new JsonResponse(['success' => false, 'message' => 'Run one catalog variant per request.'], 400);
            }

            $result = $this->runner->run($body['scope'], $body['site'], $body['language'], $body['operation'] === 'update');
            $result['diff'] = $result['status'] === 'failed' ? new InlineDiff()->compare($result['expected'], $result['actual']) : [];
            $result['trace'] = $result['exception']?->getTraceAsString() ?? '';
            $result['exception'] = null;
            if ($result['path'] !== '') {
                $result['path'] = Path::makeRelative($result['path'], Environment::getProjectPath());
            }

            return new JsonResponse(['success' => true, 'result' => $result], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $throwable) {
            return new JsonResponse(['success' => false, 'message' => $throwable->getMessage()], 400, ['Cache-Control' => 'no-store']);
        }
    }
}
