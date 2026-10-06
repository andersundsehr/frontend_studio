<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use InvalidArgumentException;
use RuntimeException;

final readonly class ComponentDocumentation
{
    public function __construct(private ComponentMetadataProvider $metadataProvider, private ComponentWritePolicy $writePolicy)
    {
    }

    /** @return array{markdown: string, revision: string, readOnly: bool} */
    public function read(string $identifier): array
    {
        $path = $this->resolvePath($identifier);
        $markdown = $this->readPath($path);
        return ['markdown' => $markdown ?? '', 'revision' => $this->revision($markdown), 'readOnly' => $this->writePolicy->isReadOnly()];
    }

    /** @return array{markdown: string, revision: string, readOnly: bool} */
    public function save(string $identifier, string $markdown, string $revision): array
    {
        $this->writePolicy->assertWritable();
        if (strlen($markdown) > 1048576 || preg_match('//u', $markdown) !== 1) {
            throw new InvalidArgumentException('Documentation must be UTF-8 text of at most 1 MiB.', 1303826285);
        }

        $path = $this->resolvePath($identifier);
        // Serialize our writers using the stable template inode; replace Markdown atomically.
        $template = $this->metadataProvider->getComponentTemplateForIdentifier($identifier);
        assert($template !== null);
        $lock = @fopen($template->absolutePath ?? '', 'r');
        if ($lock === false) {
            throw new RuntimeException('Could not lock the component documentation.', 4695255003);
        }

        $temporary = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not lock the component documentation.', 6372695018);
            }

            $path = $this->resolvePath($identifier);
            $this->assertRevision($path, $revision);

            if (!is_writable(dirname($path)) || (is_file($path) && !is_writable($path))) {
                throw new RuntimeException('Documentation is not writable. Check file and directory permissions.', 8858025004);
            }

            if ($markdown === '') {
                $this->resolvePath($identifier);
                $this->assertRevision($path, $revision);
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Could not remove the documentation file.', 6512741533);
                }

                return ['markdown' => '', 'revision' => $this->revision(null), 'readOnly' => false];
            }

            $temporary = tempnam(dirname($path), '.frontend-studio-doc-');
            if ($temporary === false || file_put_contents($temporary, $markdown) !== strlen($markdown)) {
                throw new RuntimeException('Could not write the complete documentation.', 3102501412);
            }

            $permissions = is_file($path) ? fileperms($path) : 0666 & ~umask();
            if ($permissions === false || !chmod($temporary, $permissions & 0777)) {
                throw new RuntimeException('Could not preserve documentation permissions.', 6626777224);
            }

            $this->resolvePath($identifier);
            $this->assertRevision($path, $revision);

            if (!rename($temporary, $path)) {
                throw new RuntimeException('Could not replace the documentation file.', 6399418644);
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['markdown' => $markdown, 'revision' => $this->revision($markdown), 'readOnly' => false];
    }

    private function resolvePath(string $identifier): string
    {
        $template = $this->metadataProvider->getComponentTemplateForIdentifier($identifier);
        $templatePath = $template?->absolutePath;
        if ($templatePath === null || !is_file($templatePath)) {
            throw new InvalidArgumentException('Select a registered component with a template file.', 7406174149);
        }

        $path = preg_replace('/(?:\\.fluid)?\\.html$/i', '.md', $templatePath);
        if ($path === null || $path === $templatePath || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new InvalidArgumentException('The component documentation path is not a regular Markdown file.', 1438364690);
        }

        return $path;
    }

    /** @phpstan-impure */
    private function assertRevision(string $path, string $revision): void
    {
        if (!hash_equals($this->revision($this->readPath($path)), $revision)) {
            throw new RuntimeException('Documentation changed on disk. Copy your edits, then reload before saving.', 1791201001);
        }
    }

    /** @phpstan-impure */
    private function readPath(string $path): ?string
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return null;
        }

        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            throw new RuntimeException('Could not read the component documentation.', 9574624726);
        }

        return $markdown;
    }

    private function revision(?string $markdown): string
    {
        return hash('sha256', $markdown === null ? 'missing' : 'content:' . $markdown);
    }
}
