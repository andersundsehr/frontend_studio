<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Service;

use ReflectionFunction;
use Andersundsehr\FrontendStudio\Controller\ComponentTransformerController;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTransformerGenerator;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use stdClass;
use TYPO3\TestingFramework\Core\Testbase;

final class ComponentTransformerGeneratorTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/frontend_studio',
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:missingTransformer:Default');
        self::assertNotNull($metadata?->missingTransformers);
        $this->path = $metadata->missingTransformers->absolutePath;
        self::assertStringEndsWith('/MissingTransformer.transformer.php', $this->path);
        self::assertFileDoesNotExist($this->path);
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function testAuthenticatedProtectedRouteCreatesAUsableStartingTemplate(): void
    {
        $this->login();
        $response = $this->dispatch();
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertFalse($payload['hasTodos']);
        self::assertStringEndsWith('MissingTransformer.transformer.php', $payload['path']);
        self::assertStringNotContainsString(Environment::getProjectPath(), $payload['path']);
        $transformers = require $this->path;
        self::assertInstanceOf(ArgumentTransformers::class, $transformers);
        self::assertInstanceOf(stdClass::class, ($transformers->arguments['payload'])());
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:missingTransformer:Default');
        self::assertNull($metadata?->missingTransformerError);

        $before = file_get_contents($this->path);
        $response = $this->dispatch();
        self::assertSame(400, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testEveryMissingArgumentIsGeneratedAndDomainLogicRemainsATodo(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:transformerTodos:Default');
        self::assertNotNull($metadata?->missingTransformers);
        self::assertSame(['payload' => 'stdClass', 'domain' => 'ArrayObject'], $metadata->missingTransformers->arguments);
        $this->path = $metadata->missingTransformers->absolutePath;
        self::assertFileDoesNotExist($this->path);
        $this->login();
        $response = $this->dispatch(identifier: 'site:transformerTodos:Default');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['hasTodos']);
        $transformers = require $this->path;
        self::assertInstanceOf(ArgumentTransformers::class, $transformers);
        self::assertSame(['payload', 'domain'], array_keys($transformers->arguments));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TODO: implement transformer for argument "domain"');
        ($transformers->arguments['domain'])();
    }

    public function testConcurrentRequestsCreateExactlyOneCompleteFile(): void
    {
        $workers = [];
        for ($index = 0; $index < 2; ++$index) {
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/../Fixtures/ConcurrentTransformerCreation.php',
                new Testbase()->getPackagesPath() . '/autoload.php',
                $this->instancePath,
            ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [$process, $pipes]) {
            self::assertSame("ready\n", fgets($pipes[1]));
        }

        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }

        sort($results);
        self::assertSame(['created', 'rejected'], $results);
        $transformers = require $this->path;
        self::assertInstanceOf(ArgumentTransformers::class, $transformers);
        self::assertInstanceOf(stdClass::class, ($transformers->arguments['payload'])());
    }

    public function testNullableTypedArrayTemplateCanBeReloaded(): void
    {
        $provider = $this->get(ComponentMetadataProvider::class);
        $identifier = 'site:nullableTransformer:Default';
        $metadata = $provider->getComponentMetadataForVariantIdentifier($identifier);
        self::assertNotNull($metadata?->missingTransformers);
        self::assertSame(['items' => '?ArrayObject[]'], $metadata->missingTransformers->arguments);
        $this->path = $metadata->missingTransformers->absolutePath;
        self::assertFileDoesNotExist($this->path);

        $this->login();
        $response = $this->dispatch(identifier: $identifier);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['hasTodos']);
        $transformers = require $this->path;
        self::assertInstanceOf(ArgumentTransformers::class, $transformers);
        self::assertSame('?array', new ReflectionFunction($transformers->arguments['items'])->getReturnType()?->__toString());

        $reloaded = $provider->getComponentMetadataForVariantIdentifier($identifier);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->missingTransformerError);
        self::assertNull($reloaded->missingTransformers);
        self::assertSame([], $reloaded->errors);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TODO: implement transformer for argument "items"');
        ($transformers->arguments['items'])();
    }

    public function testExistingIncompleteFileIsNeverExtended(): void
    {
        file_put_contents($this->path, '<?php return new \\Andersundsehr\\FrontendStudio\\Transformer\\ArgumentTransformers();');
        $before = file_get_contents($this->path);
        $this->login();
        $response = $this->dispatch();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->path));
        self::assertFalse($this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:missingTransformer:Default')?->missingTransformers?->canGenerate);
    }

    #[DataProvider('productionContexts')]
    public function testProductionRejectsRouteAndDirectServiceWithoutCreatingFiles(string $context): void
    {
        $this->login();
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        $property->setValue(null, new ApplicationContext($context));
        try {
            self::assertFalse($this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:missingTransformer:Default')?->missingTransformers?->canGenerate);
            self::assertSame(403, $this->dispatch()->getStatusCode());
            $this->expectException(ComponentWriteDeniedException::class);
            $this->get(ComponentTransformerGenerator::class)->create('site:missingTransformer:Default');
        } finally {
            self::assertFileDoesNotExist($this->path);
            $property->setValue(null, $original);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function productionContexts(): iterable
    {
        yield 'Production' => ['Production'];
        yield 'Production/Staging' => ['Production/Staging'];
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectedRouteRequestsLeaveFilesUntouched(string $method, string $identifier, int $status): void
    {
        $this->login();
        self::assertSame($status, $this->dispatch(method: $method, identifier: $identifier)->getStatusCode());
        self::assertFileDoesNotExist($this->path);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function rejectedRequests(): iterable
    {
        yield 'GET' => ['GET', 'site:missingTransformer:Default', 405];
        yield 'unregistered component' => ['POST', 'site:unknown:Default', 400];
        yield 'arbitrary path' => ['POST', '/tmp/injected.transformer.php', 400];
    }

    public function testControllerRequiresBackendAuthentication(): void
    {
        $this->get(Context::class)->setAspect('backend.user', new UserAspect());
        $response = $this->get(ComponentTransformerController::class)->createAction(new ServerRequest('https://example.test/', 'POST')->withParsedBody(['identifier' => 'site:missingTransformer:Default']));
        self::assertSame(403, $response->getStatusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testMissingTokenIsRejectedByTypo3BeforeGeneration(): void
    {
        $this->login();
        $this->expectException(MissingRequestTokenException::class);
        try {
            $this->dispatch(token: '');
        } finally {
            self::assertFileDoesNotExist($this->path);
        }
    }

    public function testInvalidTokenIsRejectedByTypo3BeforeGeneration(): void
    {
        $this->login();
        $this->expectException(InvalidRequestTokenException::class);
        try {
            $this->dispatch(token: 'invalid');
        } finally {
            self::assertFileDoesNotExist($this->path);
        }
    }

    public function testUnwritableDirectoryDoesNotCreateAFile(): void
    {
        $directory = dirname($this->path);
        $permissions = fileperms($directory) & 0777;
        chmod($directory, 0555);
        try {
            clearstatcache(true, $directory);
            self::assertFalse(is_writable($directory), 'Run the filesystem permission test as an unprivileged user.');
            $this->expectException(RuntimeException::class);
            $this->get(ComponentTransformerGenerator::class)->create('site:missingTransformer:Default');
        } finally {
            chmod($directory, $permissions);
            self::assertFileDoesNotExist($this->path);
        }
    }

    private function login(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->setUpBackendUser(1);
    }

    private function dispatch(?string $token = null, string $method = 'POST', string $identifier = 'site:missingTransformer:Default'): ResponseInterface
    {
        $configuration = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
        $routeName = 'ajax_frontend_studio_component_create_transformer';
        $route = new Route($configuration['frontend_studio_component_create_transformer']['path'], [
            ...$configuration['frontend_studio_component_create_transformer'],
            '_identifier' => $routeName,
        ]);
        $request = new ServerRequest('https://example.test/typo3/ajax/frontend-studio/component/create-transformer', $method)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
        $token ??= $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('route', $routeName);
        return $this->get(RouteDispatcher::class)->dispatch($request->withParsedBody(['identifier' => $identifier, 'token' => $token]));
    }
}
