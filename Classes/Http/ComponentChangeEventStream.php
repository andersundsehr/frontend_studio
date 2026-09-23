<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Http;

use Andersundsehr\FrontendStudio\Service\ComponentTemplateRootWatcher;
use RuntimeException;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

final readonly class ComponentChangeEventStream implements SelfEmittableStreamInterface
{
    private const int POLL_INTERVAL_MICROSECONDS = 1_000_000;

    private const int MAX_RUNTIME_SECONDS = 300;

    public function __construct(
        private ComponentTemplateRootWatcher $componentTemplateRootWatcher,
    ) {
    }

    public function emit(): void
    {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        ignore_user_abort(false);

        $snapshot = $this->componentTemplateRootWatcher->createSnapshot();
        $this->sendEvent('ready');

        $startedAt = time();
        while (connection_aborted() === 0 && time() - $startedAt < self::MAX_RUNTIME_SECONDS) {
            usleep(self::POLL_INTERVAL_MICROSECONDS);

            $currentSnapshot = $this->componentTemplateRootWatcher->createSnapshot();
            if ($currentSnapshot === $snapshot) {
                $this->sendComment('keep-alive');
                continue;
            }

            usleep(250_000);
            $debouncedSnapshot = $this->componentTemplateRootWatcher->createSnapshot();
            $componentIdentifiers = $this->componentTemplateRootWatcher->getChangedComponentIdentifiers($snapshot, $debouncedSnapshot);
            $snapshot = $debouncedSnapshot;

            $this->sendEvent('component-files-changed', [
                'componentIdentifiers' => $componentIdentifiers,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEvent(string $event, array $payload = []): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode((object)$payload, JSON_THROW_ON_ERROR) . "\n\n";
        $this->flush();
    }

    private function sendComment(string $comment): void
    {
        echo ': ' . $comment . "\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('The component change event stream is not seekable.', 8294674828);
    }

    public function rewind(): void
    {
        throw new RuntimeException('The component change event stream is not seekable.', 6013508026);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The component change event stream is not writable.', 6915233706);
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
