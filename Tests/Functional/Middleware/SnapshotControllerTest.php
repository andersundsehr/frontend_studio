<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Controller\SnapshotController;
use ReflectionProperty;
use Symfony\Component\Filesystem\Path;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

final class SnapshotControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [__DIR__ . '/../../..', __DIR__ . '/../Fixtures/Extensions/preview_site_set'];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    public function testAllComponentVariantNamespaceAndFolderScopesUseCompleteCatalog(): void
    {
        $this->login();
        foreach (['', 'site:', 'site:card', 'site:card:Default', 'site:nested.'] as $scope) {
            $response = $this->dispatch(['scope' => $scope]);
            self::assertSame(200, $response->getStatusCode(), $scope . ': ' . $response->getBody());
            $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($payload['identifiers']);
            self::assertContains('site:card:Default', $payload['catalog']);
        }
    }

    public function testRejectsInvalidIdentifiersContextAndBatchExecution(): void
    {
        $this->login();
        foreach ([['scope' => '../../etc/passwd'], ['scope' => 'site:unknown'], ['site' => 'missing'], ['language' => 'missing'], ['operation' => 'run', 'scope' => 'site:card'], ['scope' => []]] as $body) {
            self::assertSame(400, $this->dispatch($body)->getStatusCode());
        }
    }

    public function testMissingBaselineAndProductionRulesAreSharedWithCli(): void
    {
        $this->login();
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        $path = '';
        try {
            $property->setValue(null, new ApplicationContext('Production/Staging'));
            $response = $this->dispatch(['operation' => 'run']);
            self::assertSame(200, $response->getStatusCode());
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('missing', $result['status'], $result['message']);
            self::assertFalse(Path::isAbsolute($result['path']));
            self::assertStringNotContainsString(Environment::getProjectPath(), $result['path']);
            $path = Environment::getProjectPath() . '/' . $result['path'];
            self::assertStringEndsWith('.snapshot.html', $path);
            self::assertFileDoesNotExist($path);
            $property->setValue(null, $original);
            $response = $this->dispatch(['operation' => 'run']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('missing', $result['status'], $result['message']);
            self::assertFileExists($path);
            $property->setValue(null, new ApplicationContext('Production'));
            $response = $this->dispatch(['operation' => 'run']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('passed', $result['status'], $result['message']);
            file_put_contents($path, '<p>Reviewed old content</p>');
            $response = $this->dispatch(['operation' => 'run']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('failed', $result['status'], $result['message']);
            self::assertNotEmpty($result['diff']);
            self::assertTrue(array_any($result['diff'], static fn(array $row): bool => $row['changed']));
            self::assertSame('', $result['trace']);
            self::assertNull($result['exception']);
        } finally {
            $property->setValue(null, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    public function testTestErrorsExposeReadableTechnicalDetailsWithoutExceptionObjects(): void
    {
        $this->login();
        $response = $this->dispatch(['operation' => 'run', 'scope' => 'site:missingTransformer:Default']);
        self::assertSame(200, $response->getStatusCode());
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
        self::assertSame('error', $result['status']);
        self::assertNotEmpty($result['message']);
        self::assertNotEmpty($result['trace']);
        self::assertSame([], $result['diff']);
        self::assertNull($result['exception']);
    }

    public function testBackendLoginIsRequiredAndGetCannotRun(): void
    {
        $controller = $this->get(SnapshotController::class);
        self::assertSame(403, $controller->runAction(new ServerRequest('/', 'POST'))->getStatusCode());
        self::assertSame(405, $controller->runAction(new ServerRequest('/', 'GET'))->getStatusCode());
    }

    public function testMissingRouteTokenIsRejected(): void
    {
        $this->login();
        $this->expectException(MissingRequestTokenException::class);
        $this->dispatch([], '');
    }

    public function testInvalidRouteTokenIsRejected(): void
    {
        $this->login();
        $this->expectException(InvalidRequestTokenException::class);
        $this->dispatch([], 'invalid');
    }

    private function login(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->setUpBackendUser(1);
    }

    /** @param array<string, mixed> $body */
    private function dispatch(array $body = [], ?string $token = null): ResponseInterface
    {
        $configuration = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
        $routeName = 'ajax_frontend_studio_snapshot';
        $route = new Route($configuration['frontend_studio_snapshot']['path'], [...$configuration['frontend_studio_snapshot'], '_identifier' => $routeName]);
        $request = new ServerRequest('https://example.test/typo3/ajax/frontend-studio/snapshots', 'POST')
            ->withAttribute('applicationType', 2)
            ->withAttribute('route', $route)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
        $token ??= $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('route', $routeName);
        return $this->get(RouteDispatcher::class)->dispatch($request->withParsedBody([
            'operation' => 'discover', 'scope' => 'site:wrappedCard:Default', 'site' => 'preview', 'language' => 'de', ...$body, 'token' => $token,
        ]));
    }
}
