<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Controller\SnapshotController;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;

final class SnapshotControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [__DIR__ . '/../../..', __DIR__ . '/../Fixtures/Extensions/preview_site_set'];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    #[DataProvider('modulePermissions')]
    public function testAllOperationsRequireFrontendStudioModuleAccess(int $userId, string $operation, bool $authorized): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SnapshotBackendUsers.csv');
        $this->setUpBackendUser($userId);
        $response = $this->dispatch(['operation' => $operation]);
        self::assertSame($authorized ? 200 : 403, $response->getStatusCode(), (string)$response->getBody());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($authorized, $payload['success']);
        if (!$authorized) {
            self::assertSame('Frontend Studio module access is required.', $payload['message']);
        }

        if (isset($payload['result'])) {
            self::assertNotSame('error', $payload['result']['status'], $payload['result']['message']);
            $path = Environment::getProjectPath() . '/' . $payload['result']['path'];
            if ($operation === 'run') {
                self::assertSame('missing', $payload['result']['status']);
                self::assertFileDoesNotExist($path);
                self::assertDirectoryDoesNotExist(dirname($path));
            } else {
                self::assertSame('created', $payload['result']['status']);
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function modulePermissions(): iterable
    {
        foreach (['discover', 'run', 'update'] as $operation) {
            yield 'administrator can ' . $operation => [1, $operation, true];
            yield 'authorized developer can ' . $operation => [2, $operation, true];
            yield 'user without module access cannot ' . $operation => [3, $operation, false];
        }
    }

    #[DataProvider('guiSettings')]
    public function testBackendSettingsExposeTheCheckboxAndProductionWritePolicy(?string $enabled, string $context, bool $expectedEnabled, bool $readOnly): void
    {
        if ($enabled !== null) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['enableGuiTesting'] = $enabled;
        }

        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            $property->setValue(null, new ApplicationContext($context));
            $this->get(EventDispatcherInterface::class)->dispatch(new AfterBackendPageRenderEvent('', $this->createStub(ViewInterface::class)));
            $settings = new ReflectionProperty(PageRenderer::class, 'inlineSettings')->getValue($this->get(PageRenderer::class));
            self::assertSame($expectedEnabled, $settings['frontendStudio']['snapshotTestingEnabled']);
            self::assertSame($readOnly, $settings['frontendStudio']['snapshotReadOnly']);
        } finally {
            $property->setValue(null, $original);
        }
    }

    /** @return iterable<string, array{?string, string, bool, bool}> */
    public static function guiSettings(): iterable
    {
        yield 'enabled by default' => [null, 'Development', true, false];
        yield 'checkbox disabled' => ['0', 'Development', false, false];
        yield 'checkbox enabled' => ['1', 'Development', true, false];
        yield 'Production can compare but not update' => ['1', 'Production/Staging', true, true];
    }

    public function testDisabledGuiRejectsAllOperationsWhileTheSharedRunnerRemainsAvailable(): void
    {
        $this->login();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['enableGuiTesting'] = '0';
        foreach (['discover', 'run', 'update'] as $operation) {
            $response = $this->dispatch(['operation' => $operation]);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('GUI snapshot testing is disabled', (string)$response->getBody());
        }

        $runner = $this->get(Runner::class);
        self::assertContains('site:wrappedCard:Default', $runner->discover());
        $result = $runner->run('site:wrappedCard:Default', 'preview', 'de', true);
        try {
            self::assertSame('created', $result['status'], $result['message']);
            self::assertFileExists($result['path']);
            self::assertSame('passed', $runner->run('site:wrappedCard:Default', 'preview', 'de')['status']);
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
                rmdir(dirname($result['path']));
            }
        }
    }

    public function testUpdateWritesOnlyTheSelectedVariantAndLanguageThenRequiresAnotherRun(): void
    {
        $this->login();
        $paths = [];
        try {
            foreach (['en', 'de'] as $language) {
                $response = $this->dispatch(['operation' => 'update', 'language' => $language]);
                $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
                $paths[$language] = Environment::getProjectPath() . '/' . $result['path'];
                file_put_contents($paths[$language], '<p>Old ' . $language . ' snapshot</p>');
            }

            $response = $this->dispatch(['operation' => 'update']);
            self::assertSame(200, $response->getStatusCode());
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('updated', $result['status'], $result['message']);
            self::assertSame('site:wrappedCard:Default', $result['identifier']);
            self::assertSame([], $result['diff']);
            self::assertSame('', $result['trace']);
            self::assertSame($result['expected'], file_get_contents($paths['de']));
            self::assertSame('<p>Old en snapshot</p>', file_get_contents($paths['en']));
            $response = $this->dispatch(['operation' => 'run']);
            self::assertSame('passed', json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result']['status']);
            $response = $this->dispatch(['operation' => 'update']);
            self::assertSame('unchanged', json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result']['status']);
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }

            if ($paths !== []) {
                rmdir(dirname(array_values($paths)[0]));
            }
        }
    }

    public function testProductionUpdateCannotOverwriteAnExistingSnapshot(): void
    {
        $this->login();
        $response = $this->dispatch(['operation' => 'update']);
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
        $path = Environment::getProjectPath() . '/' . $result['path'];
        file_put_contents($path, '<p>Reviewed snapshot</p>');
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            $property->setValue(null, new ApplicationContext('Production/Staging'));
            $response = $this->dispatch(['operation' => 'update']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('error', $result['status']);
            self::assertStringContainsString('read-only in Production', $result['message']);
            self::assertSame('<p>Reviewed snapshot</p>', file_get_contents($path));
        } finally {
            $property->setValue(null, $original);
            unlink($path);
            rmdir(dirname($path));
        }
    }

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

    public function testTreeTestabilityUsesAllFixturesBeforeFiltering(): void
    {
        $fixture = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/Nested/Card/Card.fixture.yaml';
        $content = file_get_contents($fixture);
        self::assertIsString($content);
        try {
            file_put_contents($fixture, "variants: {}\n");
            $provider = $this->get(ComponentTreeDataProvider::class);
            $nodes = array_column($provider->getTreeNodes(), null, 'identifier');
            self::assertFalse($nodes['site:nested.']['testable'] ?? true);
            self::assertFalse($nodes['site:nested.card']['testable'] ?? true);
            self::assertTrue($nodes['site:']['testable'] ?? false);
            self::assertTrue($nodes['site:card']['testable'] ?? false);
            self::assertTrue($nodes['site:card:Default']['testable'] ?? false);
            $filtered = array_column($provider->getFilteredTreeNodes('site:nested.card'), null, 'identifier');
            self::assertArrayNotHasKey('site:card:Default', $filtered);
            self::assertTrue($filtered['site:']['testable'] ?? false);
            self::assertFalse($filtered['site:nested.card']['testable'] ?? true);
        } finally {
            file_put_contents($fixture, $content);
        }
    }

    #[DataProvider('singleVariantOperations')]
    public function testSingleVariantOperationsDoNotDiscoverUnrelatedFixtures(string $operation, string $status): void
    {
        $this->login();
        $fixture = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/Card/Card.fixture.yaml';
        $content = file_get_contents($fixture);
        self::assertIsString($content);
        $path = '';
        try {
            file_put_contents($fixture, "variants: [\n");
            $response = $this->dispatch(['operation' => $operation]);
            self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            $path = Environment::getProjectPath() . '/' . $result['path'];
            self::assertSame($status, $result['status'], $result['message']);
            self::assertSame(400, $this->dispatch(['operation' => 'discover'])->getStatusCode());
        } finally {
            file_put_contents($fixture, $content);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function singleVariantOperations(): iterable
    {
        yield 'run' => ['run', 'missing'];
        yield 'update' => ['update', 'created'];
    }

    public function testRejectsInvalidIdentifiersContextAndBatchExecution(): void
    {
        $this->login();
        foreach ([['scope' => '../../etc/passwd'], ['scope' => 'site:unknown'], ['site' => 'missing'], ['language' => 'missing'], ['operation' => 'run', 'scope' => 'site:card'], ['operation' => 'update', 'scope' => 'site:card'], ['operation' => 'update', 'scope' => '../../etc/passwd'], ['operation' => 'delete'], ['scope' => []]] as $body) {
            self::assertSame(400, $this->dispatch($body)->getStatusCode());
        }
    }

    #[DataProvider('invalidVariantIdentifiers')]
    public function testSingleVariantOperationsRejectUnknownVariantsAndBatchScopes(string $identifier): void
    {
        $this->login();
        foreach (['run', 'update'] as $operation) {
            $response = $this->dispatch(['operation' => $operation, 'scope' => $identifier]);
            self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVariantIdentifiers(): iterable
    {
        foreach (['', 'site:', 'site:card', 'site:nested.', 'unknown:card:Default', 'site:unknown:Default', 'site:card:Unknown', ' site:card:Default ', '../../etc/passwd'] as $identifier) {
            yield $identifier => [$identifier];
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
            self::assertSame('', $result['expected']);
            self::assertFileDoesNotExist($path);
            self::assertDirectoryDoesNotExist(dirname($path));
            $response = $this->dispatch(['operation' => 'update']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('created', $result['status'], $result['message']);
            self::assertSame([], $result['diff']);
            self::assertSame($result['expected'], file_get_contents($path));
            $response = $this->dispatch(['operation' => 'update']);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertSame('unchanged', $result['status'], $result['message']);
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

    #[DataProvider('executingOperations')]
    public function testTestErrorsExposeReadableTechnicalDetailsWithoutExceptionObjects(string $operation): void
    {
        $this->login();
        $response = $this->dispatch(['operation' => $operation, 'scope' => 'site:missingTransformer:Default']);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success'], 'Transport success does not imply that the snapshot operation succeeded.');
        $result = $payload['result'];
        self::assertSame('error', $result['status']);
        self::assertNotEmpty($result['message']);
        self::assertNotEmpty($result['trace']);
        self::assertSame([], $result['diff']);
        self::assertNull($result['exception']);
    }

    /** @return iterable<string, array{string}> */
    public static function executingOperations(): iterable
    {
        yield 'run failure' => ['run'];
        yield 'application-level update failure' => ['update'];
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
