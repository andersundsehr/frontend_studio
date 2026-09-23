<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use RuntimeException;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use ZipArchive;

final readonly class ComponentFolderArchiveProvider
{
    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
    ) {
    }

    /**
     * @return array{path: string, filename: string, filesAdded: int}
     */
    public function createArchive(string $componentIdentifier): array
    {
        [$namespace, $componentName] = $this->parseComponentIdentifier($componentIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new RuntimeException('The selected component could not be resolved.', 404);
        }

        $templatePath = $this->resolveTemplatePath($resolverDelegate, $componentName);
        $componentFolder = realpath(dirname($templatePath));
        if ($componentFolder === false || !is_dir($componentFolder)) {
            throw new RuntimeException('The component folder could not be resolved.', 5914815181);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'frontend-studio-component-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Could not create temporary archive file.', 9460114746);
        }

        $zip = new ZipArchive();
        if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporaryFile);
            throw new RuntimeException('Could not open temporary archive file.', 6495303359);
        }

        $filesAdded = $this->addFolderToArchive($zip, $componentFolder);
        $zip->close();

        if ($filesAdded === 0) {
            @unlink($temporaryFile);
            throw new RuntimeException('The component folder does not contain readable files.', 9912232629);
        }

        return [
            'path' => $temporaryFile,
            'filename' => $this->createArchiveFilename($namespace, $componentName),
            'filesAdded' => $filesAdded,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseComponentIdentifier(string $componentIdentifier): array
    {
        $identifierParts = explode(':', trim($componentIdentifier), 2);
        if (count($identifierParts) !== 2) {
            throw new InvalidArgumentException('The component identifier is invalid.', 6523036825);
        }

        [$namespace, $componentName] = $identifierParts;
        if ($namespace === '' || $componentName === '') {
            throw new InvalidArgumentException('The component identifier is invalid.', 2056744118);
        }

        return [$namespace, $componentName];
    }

    private function resolveComponentTemplateResolver(string $namespace, string $componentName): ?ComponentTemplateResolverInterface
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentTemplateResolverInterface) {
                continue;
            }

            $displayNamespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            if ($displayNamespace !== $namespace) {
                continue;
            }

            if (!$this->componentDiscoveryProvider->hasComponent($resolverDelegate, $componentName)) {
                continue;
            }

            return $resolverDelegate;
        }

        return null;
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

    private function resolveTemplatePath(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): string
    {
        $templateName = $resolverDelegate->resolveTemplateName($componentName);
        $templatePath = $resolverDelegate->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
            'Default',
            $templateName,
            null,
            true,
        );

        if (!is_string($templatePath) || $templatePath === '' || !is_file($templatePath)) {
            throw new RuntimeException('The component template file could not be resolved.', 9196219404);
        }

        return $templatePath;
    }

    private function addFolderToArchive(ZipArchive $zip, string $componentFolder): int
    {
        $filesAdded = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($componentFolder, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink() || !$file->isReadable()) {
                continue;
            }

            $absolutePath = $file->getRealPath();
            if ($absolutePath === false || !$this->isPathInsideFolder($absolutePath, $componentFolder)) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($componentFolder) + 1));
            if ($relativePath === '') {
                continue;
            }

            if ($zip->addFile($absolutePath, $relativePath)) {
                $filesAdded++;
            }
        }

        return $filesAdded;
    }

    private function isPathInsideFolder(string $path, string $folder): bool
    {
        return str_starts_with($path, rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    private function createArchiveFilename(string $namespace, string $componentName): string
    {
        $filename = strtolower($namespace . '-' . $componentName);
        $filename = preg_replace('/[^a-z0-9_.-]+/', '-', $filename) ?? 'component';
        $filename = trim($filename, '-.');

        return ($filename !== '' ? $filename : 'component') . '.zip';
    }
}
