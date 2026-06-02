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
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

final readonly class ComponentDiscoveryProvider
{
    private const IGNORED_DIRECTORY_NAMES = [
        '.cache' => true,
        '.git' => true,
        'node_modules' => true,
        'var' => true,
        'vendor' => true,
    ];

    /**
     * @return list<string>
     */
    public function getAvailableComponents(object $resolverDelegate): array
    {
        if ($resolverDelegate instanceof ComponentListProviderInterface) {
            return $this->normalizeComponentNames($resolverDelegate->getAvailableComponents());
        }

        if (!$resolverDelegate instanceof AbstractComponentCollection) {
            return [];
        }

        return $this->getAvailableComponentsFromTemplatePaths($resolverDelegate);
    }

    public function hasComponent(object $resolverDelegate, string $componentName): bool
    {
        return in_array($componentName, $this->getAvailableComponents($resolverDelegate), true);
    }

    /**
     * @return list<string>
     */
    private function getAvailableComponentsFromTemplatePaths(AbstractComponentCollection&ComponentTemplateResolverInterface $resolverDelegate): array
    {
        $components = [];

        foreach ($resolverDelegate->getTemplatePaths()->getTemplateRootPaths() as $templateRootPath) {
            $rootPath = $this->resolveAbsolutePath((string)$templateRootPath);
            if ($rootPath === null) {
                continue;
            }

            foreach ($this->collectTemplateFiles($rootPath) as $templatePath) {
                $componentName = $this->inferComponentName($rootPath, $templatePath);
                if ($componentName === null || !$this->isResolvableComponent($resolverDelegate, $componentName)) {
                    continue;
                }

                $components[] = $componentName;
            }
        }

        return $this->normalizeComponentNames($components);
    }

    private function inferComponentName(string $rootPath, string $templatePath): ?string
    {
        $relativePath = ltrim(substr($templatePath, strlen($rootPath)), '/');
        $templateName = $this->stripTemplateSuffix($relativePath);
        if ($templateName === null) {
            return null;
        }

        $pathFragments = explode('/', $templateName);
        if (count($pathFragments) < 2) {
            return null;
        }

        $fileName = array_pop($pathFragments);
        $componentName = end($pathFragments);
        if ($componentName === false || $fileName !== $componentName) {
            return null;
        }

        return implode('.', array_map(lcfirst(...), $pathFragments));
    }

    private function stripTemplateSuffix(string $relativePath): ?string
    {
        if (str_ends_with($relativePath, '.fluid.html')) {
            return substr($relativePath, 0, -strlen('.fluid.html'));
        }

        if (str_ends_with($relativePath, '.html')) {
            return substr($relativePath, 0, -strlen('.html'));
        }

        return null;
    }

    private function isResolvableComponent(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): bool
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
            return false;
        }

        return is_string($templatePath) && $templatePath !== '' && is_file($templatePath);
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
     * @return list<string>
     */
    private function collectTemplateFiles(string $rootPath): array
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

        $templateFiles = [];
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || !$fileInfo->isReadable()) {
                continue;
            }

            $path = $fileInfo->getRealPath();
            if ($path === false || (!str_ends_with($path, '.html') && !str_ends_with($path, '.fluid.html'))) {
                continue;
            }

            $templateFiles[] = $path;
        }

        sort($templateFiles, SORT_STRING);

        return $templateFiles;
    }

    /**
     * @param list<string>|array<string> $componentNames
     * @return list<string>
     */
    private function normalizeComponentNames(array $componentNames): array
    {
        $normalized = [];
        foreach ($componentNames as $componentName) {
            if (!is_string($componentName) || $componentName === '') {
                continue;
            }

            $normalized[$componentName] = true;
        }

        $componentNames = array_keys($normalized);
        sort($componentNames, SORT_NATURAL | SORT_FLAG_CASE);

        return $componentNames;
    }
}
