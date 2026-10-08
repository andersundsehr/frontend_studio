<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Service;

use Andersundsehr\FrontendStudio\Controller\ComponentDocumentationController;
use Andersundsehr\FrontendStudio\Service\ComponentDocumentation;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use InvalidArgumentException;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use ReflectionProperty;

final class ComponentDocumentationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/frontend_studio',
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Extensions/preview_site_set',
    ];

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $template = $this->get(ComponentMetadataProvider::class)->getComponentTemplateForIdentifier('site:card');
        self::assertNotNull($template?->absolutePath);
        $this->path = dirname($template->absolutePath) . '/Card.md';
        self::assertFileDoesNotExist($this->path);
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && (file_exists($this->path) || is_link($this->path))) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function testMissingFileExplicitSaveUtf8AndStaleRevision(): void
    {
        $service = $this->get(ComponentDocumentation::class);
        $initial = $service->read('site:card');
        self::assertSame('', $initial['markdown']);
        self::assertFileDoesNotExist($this->path);
        $saved = $service->save('site:card', "# Über uns\n\nこんにちは\n", $initial['revision']);
        self::assertSame($saved, $service->read('site:card'));
        self::assertSame($saved['markdown'], file_get_contents($this->path));
        file_put_contents($this->path, 'External change');
        try {
            $service->save('site:card', 'Stale content', $saved['revision']);
            self::fail('Stale save must fail.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(1791201001, $runtimeException->getCode());
        }

        self::assertSame('External change', file_get_contents($this->path));
    }

    public function testEmptySaveRemovesDocumentationAndRestoresMissingRevision(): void
    {
        $service = $this->get(ComponentDocumentation::class);
        $initial = $service->read('site:card');
        self::assertSame($initial, $service->save('site:card', '', $initial['revision']));
        self::assertFileDoesNotExist($this->path);
        $saved = $service->save('site:card', 'Documentation', $initial['revision']);
        self::assertSame($initial, $service->save('site:card', '', $saved['revision']));
        self::assertFileDoesNotExist($this->path);
        self::assertSame($initial, $service->read('site:card'));
    }

    public function testExistingEmptyFileIsRemoved(): void
    {
        $service = $this->get(ComponentDocumentation::class);
        $missing = $service->read('site:card');
        file_put_contents($this->path, '');
        $empty = $service->read('site:card');
        self::assertNotSame($missing['revision'], $empty['revision']);
        self::assertSame($missing, $service->save('site:card', '', $empty['revision']));
        self::assertFileDoesNotExist($this->path);
    }

    public function testStaleDeletionPreservesExternalChanges(): void
    {
        $service = $this->get(ComponentDocumentation::class);
        $saved = $service->save('site:card', 'Original', $service->read('site:card')['revision']);
        file_put_contents($this->path, 'External change');
        try {
            $service->save('site:card', '', $saved['revision']);
            self::fail('Stale deletion must fail.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(1791201001, $runtimeException->getCode());
        }

        self::assertSame('External change', file_get_contents($this->path));
    }

    public function testPathsCannotBeSuppliedByTheClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->get(ComponentDocumentation::class)->read('site:../../composer');
    }

    public function testSymlinksAreRejected(): void
    {
        symlink(__FILE__, $this->path);
        $this->expectException(InvalidArgumentException::class);
        $this->get(ComponentDocumentation::class)->read('site:card');
    }

    public function testUnwritableDirectoryPreservesExistingContent(): void
    {
        $service = $this->get(ComponentDocumentation::class);
        $saved = $service->save('site:card', 'Original', $service->read('site:card')['revision']);
        $directory = dirname($this->path);
        $permissions = fileperms($directory) & 0777;
        chmod($directory, 0555);
        try {
            clearstatcache();
            self::assertFalse(is_writable($directory));
            $this->expectException(RuntimeException::class);
            $service->save('site:card', 'Changed', $saved['revision']);
        } finally {
            chmod($directory, $permissions);
            self::assertSame('Original', file_get_contents($this->path));
        }
    }

    public function testProductionAndSubcontextsDenyServiceAndRouteWrites(): void
    {
        $this->login();
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            foreach (['Production', 'Production/Staging'] as $context) {
                $property->setValue(null, new ApplicationContext($context));
                $service = $this->get(ComponentDocumentation::class);
                $initial = $service->read('site:card');
                self::assertTrue($initial['readOnly']);
                self::assertSame(403, $this->dispatch(['markdown' => 'Blocked', 'revision' => $initial['revision']])->getStatusCode());
                try {
                    $service->save('site:card', 'Blocked', $initial['revision']);
                    self::fail('Production must be read-only.');
                } catch (ComponentWriteDeniedException) {
                    self::assertFileDoesNotExist($this->path);
                }
            }
        } finally {
            $property->setValue(null, $original);
        }
    }

    public function testProductionCannotDeleteExistingDocumentation(): void
    {
        $this->login();
        $service = $this->get(ComponentDocumentation::class);
        $saved = $service->save('site:card', 'Keep this documentation', $service->read('site:card')['revision']);
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            $property->setValue(null, new ApplicationContext('Production'));
            self::assertSame(403, $this->dispatch(['markdown' => '', 'revision' => $saved['revision']])->getStatusCode());
            $this->expectException(ComponentWriteDeniedException::class);
            $service->save('site:card', '', $saved['revision']);
        } finally {
            $property->setValue(null, $original);
            self::assertSame('Keep this documentation', file_get_contents($this->path));
        }
    }

    public function testAuthenticatedRouteRoundtripAndConflict(): void
    {
        $this->login();
        $initial = $this->get(ComponentDocumentation::class)->read('site:card');
        $payload = ['markdown' => '# Shared documentation', 'revision' => $initial['revision']];
        self::assertSame(200, $this->dispatch($payload)->getStatusCode());
        self::assertSame(409, $this->dispatch($payload)->getStatusCode());
        $saved = $this->get(ComponentDocumentation::class)->read('site:card');
        self::assertSame('# Shared documentation', $saved['markdown']);
        self::assertSame(200, $this->dispatch(['markdown' => '', 'revision' => $saved['revision']])->getStatusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testReadAndWriteRequireBackendLogin(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $response = $this->get(ComponentDocumentationController::class)->handleRequest(new ServerRequest('https://example.test', $method));
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function testModulePermissionAllowsNonAdminReadCreateUpdateAndDelete(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DocumentationBackendUsers.csv');
        $this->setUpBackendUser(2);
        self::assertFalse($GLOBALS['BE_USER']->isAdmin());
        $initial = $this->get(ComponentDocumentation::class)->read('site:card');
        self::assertSame(200, $this->dispatch([], method: 'GET')->getStatusCode());
        self::assertSame(200, $this->dispatch(['markdown' => 'Created', 'revision' => $initial['revision']])->getStatusCode());
        $created = $this->get(ComponentDocumentation::class)->read('site:card');
        self::assertSame(200, $this->dispatch(['markdown' => 'Updated', 'revision' => $created['revision']])->getStatusCode());
        self::assertSame('Updated', file_get_contents($this->path));
        $updated = $this->get(ComponentDocumentation::class)->read('site:card');
        self::assertSame(200, $this->dispatch(['markdown' => '', 'revision' => $updated['revision']])->getStatusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testLoginWithoutModulePermissionCannotReadCreateUpdateOrDelete(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DocumentationBackendUsers.csv');
        $this->setUpBackendUser(3);
        $service = $this->get(ComponentDocumentation::class);
        $initial = $service->read('site:card');
        self::assertSame(403, $this->dispatch([], method: 'GET')->getStatusCode());
        self::assertSame(403, $this->dispatch(['markdown' => 'Denied', 'revision' => $initial['revision']])->getStatusCode());
        self::assertFileDoesNotExist($this->path);
        $saved = $service->save('site:card', 'Original', $initial['revision']);
        foreach (['Changed', ''] as $markdown) {
            self::assertSame(403, $this->dispatch(['markdown' => $markdown, 'revision' => $saved['revision']])->getStatusCode());
            self::assertSame('Original', file_get_contents($this->path));
        }
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->login();
        $this->expectException(MissingRequestTokenException::class);
        $this->dispatch([], '');
    }

    public function testInvalidTokenIsRejected(): void
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

    /** @param array<string, string> $payload */
    private function dispatch(array $payload, ?string $token = null, string $method = 'POST'): ResponseInterface
    {
        $configuration = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
        $routeName = 'ajax_frontend_studio_component_documentation';
        $route = new Route($configuration['frontend_studio_component_documentation']['path'], [
            ...$configuration['frontend_studio_component_documentation'], '_identifier' => $routeName,
        ]);
        $request = new ServerRequest('https://example.test/typo3/ajax/frontend-studio/component/documentation', $method)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
        $token ??= $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('route', $routeName);
        $parameters = ['identifier' => 'site:card', 'token' => $token, ...$payload];
        return $this->get(RouteDispatcher::class)->dispatch(
            $method === 'GET' ? $request->withQueryParams($parameters) : $request->withParsedBody($parameters)
        );
    }
}
