<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\ComponentWritePolicy;
use RuntimeException;

final readonly class BaselineStorage
{
    public function __construct(private ComponentWritePolicy $writePolicy)
    {
    }

    public function path(string $template, string $identifier, string $site, string $language): string
    {
        $template = realpath($template);
        if ($template === false || !is_file($template)) {
            throw new RuntimeException('The component template does not exist.', 6175149859);
        }

        $directory = dirname($template) . '/_html-snapshots';
        if (is_link($directory)) {
            throw new RuntimeException('Snapshot directories must not be symbolic links.', 2269502052);
        }

        $key = json_encode([basename($template), $identifier, $site, $language], JSON_THROW_ON_ERROR);
        return $directory . '/' . hash('sha256', $key) . '.html';
    }

    /** @phpstan-impure */
    public function read(string $path): ?string
    {
        if (is_link($path) || is_link(dirname($path))) {
            throw new RuntimeException('Snapshot paths must not be symbolic links.', 4614566558);
        }

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read baseline: ' . $path, 5480752789);
        }

        return $content;
    }

    public function create(string $path, string $content): void
    {
        $this->writePolicy->assertWritable();
        $directory = dirname($path);
        if (is_link($directory) || is_link($path)) {
            throw new RuntimeException('Snapshot paths must not be symbolic links.', 7414505151);
        }

        if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create snapshot directory.', 4575478422);
        }

        $temporary = tempnam($directory, '.snapshot-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary baseline.', 5224368611);
        }

        try {
            if (file_put_contents($temporary, $content) !== strlen($content)) {
                throw new RuntimeException('Cannot write complete baseline.', 8079099817);
            }

            if (!chmod($temporary, 0666 & ~umask())) {
                throw new RuntimeException('Cannot set baseline permissions.', 1791202501);
            }

            // Publish a complete file atomically, without replacing a concurrent baseline.
            if (!@link($temporary, $path)) {
                throw new RuntimeException('Baseline already exists or could not be created: ' . $path, 8806510737);
            }
        } finally {
            unlink($temporary);
        }
    }
}
