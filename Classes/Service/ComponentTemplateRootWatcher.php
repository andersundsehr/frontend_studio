<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

final readonly class ComponentTemplateRootWatcher
{
    private const array IGNORED_DIRECTORY_NAMES = [
        '.cache' => true,
        '.git' => true,
        'node_modules' => true,
        'var' => true,
        'vendor' => true,
    ];

    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function createSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->getTemplateRootPaths() as $rootPath) {
            foreach ($this->collectFileSignatures($rootPath) as $path => $fileSignature) {
                $snapshot[$path] = $fileSignature;
            }
        }

        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /**
     * @param array<string, string> $previousSnapshot
     * @param array<string, string> $currentSnapshot
     * @return list<string>
     */
    public function getChangedComponentIdentifiers(array $previousSnapshot, array $currentSnapshot): array
    {
        $changedPaths = [];
        foreach ($currentSnapshot as $path => $signature) {
            if (!array_key_exists($path, $previousSnapshot) || $previousSnapshot[$path] !== $signature) {
                $changedPaths[$path] = true;
            }
        }

        foreach ($previousSnapshot as $path => $signature) {
            if (!array_key_exists($path, $currentSnapshot)) {
                $changedPaths[$path] = true;
            }
        }

        if ($changedPaths === []) {
            return [];
        }

        $componentIdentifiersByPath = $this->getComponentIdentifiersByWatchedFilePath();
        $changedComponentIdentifiers = [];
        foreach (array_keys($changedPaths) as $path) {
            foreach ($componentIdentifiersByPath[$path] ?? [] as $componentIdentifier) {
                $changedComponentIdentifiers[$componentIdentifier] = true;
            }
        }

        $componentIdentifiers = array_keys($changedComponentIdentifiers);
        sort($componentIdentifiers, SORT_STRING);

        return $componentIdentifiers;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getComponentIdentifiersByWatchedFilePath(): array
    {
        $componentIdentifiersByPath = [];
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentTemplateResolverInterface) {
                continue;
            }

            $namespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            $components = $this->componentDiscoveryProvider->getAvailableComponents($resolverDelegate);

            foreach ($components as $componentName) {
                $componentIdentifier = $namespace . ':' . $componentName;
                foreach ($this->resolveWatchedComponentFiles($resolverDelegate, $componentName) as $path) {
                    $componentIdentifiersByPath[$path][] = $componentIdentifier;
                }
            }
        }

        foreach ($componentIdentifiersByPath as &$componentIdentifiers) {
            $componentIdentifiers = array_values(array_unique($componentIdentifiers));
            sort($componentIdentifiers, SORT_STRING);
        }

        unset($componentIdentifiers);

        return $componentIdentifiersByPath;
    }

    /**
     * @return list<string>
     */
    private function resolveWatchedComponentFiles(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        try {
            $templateName = $resolverDelegate->resolveTemplateName($componentName);
            $templatePath = $resolverDelegate->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
                'Default',
                $templateName,
                null,
                true,
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_string($templatePath) || $templatePath === '') {
            return [];
        }

        $paths = [$this->normalizeFilePath($templatePath)];
        $fixturePath = $this->getFixturePath($templatePath);
        if ($fixturePath !== null) {
            $paths[] = $this->normalizeFilePath($fixturePath);
        }

        return array_values(array_unique($paths));
    }

    private function getFixturePath(string $templatePath): ?string
    {
        if (str_ends_with($templatePath, '.fluid.html')) {
            return substr($templatePath, 0, -strlen('.fluid.html')) . '.fixture.yaml';
        }

        if (str_ends_with($templatePath, '.html')) {
            return substr($templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        }

        return null;
    }

    private function normalizeFilePath(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }

        return rtrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function getTemplateRootPaths(): array
    {
        $templateRootPaths = [];
        foreach ($this->componentResolverDelegateProvider->getAll() as $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentTemplateResolverInterface) {
                continue;
            }

            foreach ($resolverDelegate->getTemplatePaths()->getTemplateRootPaths() as $templateRootPath) {
                $absolutePath = $this->resolveAbsolutePath((string)$templateRootPath);
                if ($absolutePath === null) {
                    continue;
                }

                $templateRootPaths[$absolutePath] = true;
            }
        }

        $paths = array_keys($templateRootPaths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function resolveAbsolutePath(string $path): ?string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        $absolutePath = GeneralUtility::getFileAbsFileName($path);
        if ($absolutePath === '' || !is_dir($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $realPath = realpath($absolutePath);
        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            return null;
        }

        return rtrim($realPath, '/');
    }

    /**
     * @return array<string, string>
     */
    private function collectFileSignatures(string $rootPath): array
    {
        try {
            $directoryIterator = new RecursiveDirectoryIterator(
                $rootPath,
                FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS,
            );
            $filteredIterator = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (SplFileInfo $fileInfo): bool {
                    if (!$fileInfo->isReadable()) {
                        return false;
                    }

                    if ($fileInfo->isDir()) {
                        return !isset(self::IGNORED_DIRECTORY_NAMES[$fileInfo->getFilename()]);
                    }

                    return $fileInfo->isFile();
                },
            );
            $iterator = new RecursiveIteratorIterator($filteredIterator);
        } catch (Throwable) {
            return [];
        }

        $fileSignatures = [];
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || !$fileInfo->isReadable()) {
                continue;
            }

            try {
                $path = $fileInfo->getRealPath();
                if ($path === false) {
                    continue;
                }

                $fileSignatures[$path] = $fileInfo->getMTime() . '|' . $fileInfo->getSize();
            } catch (Throwable) {
                continue;
            }
        }

        return $fileSignatures;
    }

    /**
     * @return array<string, string>
     */
    private function getFluidNamespaceAliasesByClassNamespace(): array
    {
        $aliasesByClassNamespace = [];

        foreach ($this->viewHelperResolverFactory->create()->getNamespaces() as $alias => $classNamespaces) {
            if ($classNamespaces === null) {
                continue;
            }

            foreach ($classNamespaces as $classNamespace) {
                if (!is_string($classNamespace)) {
                    continue;
                }

                $aliasesByClassNamespace[$classNamespace] ??= $alias;
            }
        }

        return $aliasesByClassNamespace;
    }
}
