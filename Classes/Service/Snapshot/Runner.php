<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use DateTimeImmutable;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentWritePolicy;
use RuntimeException;
use Throwable;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final readonly class Runner
{
    public function __construct(
        private ComponentTreeDataProvider $tree,
        private ComponentMetadataProvider $metadata,
        private FrontendRenderer $renderer,
        private HtmlFormatter $formatter,
        private Comparison $comparison,
        private BaselineStorage $storage,
        private ComponentWritePolicy $writePolicy,
    ) {
    }

    /** @return list<string> */
    public function discover(string $scope = ''): array
    {
        $nodes = $this->tree->getTreeNodes(true);
        $selected = $scope === '';
        $depth = -1;
        $variants = [];
        $found = $selected;
        foreach ($nodes as $node) {
            if ($node['identifier'] === $scope) {
                $selected = true;
                $found = true;
                $depth = (int)$node['depth'];
            } elseif ($scope !== '' && $selected && (int)$node['depth'] <= $depth) {
                break;
            }

            if ($selected && $node['nodeType'] === 'variant') {
                $variants[] = (string)$node['identifier'];
            }
        }

        if (!$found || $variants === []) {
            throw new RuntimeException('Snapshot scope is unknown or contains no fixture variants.', 3349110734);
        }

        return $variants;
    }

    /** @return array{identifier: string, status: string, message: string, path: string, expected: string, actual: string, exception: Throwable|null} */
    public function run(string $identifier, string $site, string $language, bool $update = false): array
    {
        $path = '';
        $expected = '';
        $actual = '';
        $exception = null;
        try {
            if ($update) {
                $this->writePolicy->assertWritable();
            }

            $metadata = $this->metadata->getComponentMetadataForVariantIdentifier($identifier);
            if ($metadata === null || $metadata->fixture?->selectedVariant === null || $metadata->errors !== [] || $metadata->template->absolutePath === null) {
                throw new RuntimeException('Invalid variant or fixture: ' . implode('; ', $metadata->errors ?? []), 4582022199);
            }

            $path = $this->storage->path($metadata->template->absolutePath, $metadata->fixture->selectedVariant->name, $site, $language);

            $baseline = $this->storage->read($path);
            $expected = $baseline ?? '';
            $date = new DateTimeImmutable();
            $actual = $this->formatter->format($this->renderer->render($identifier, $site, $language, $date));
            $secondDate = SamplingClock::advance($date);
            $second = $this->formatter->format($this->renderer->render($identifier, $site, $language, $secondDate));
            if ($update) {
                $expected = $this->comparison->create($actual, $second);
                if ($baseline !== $expected) {
                    $this->storage->update($path, $expected);
                    $status = 'updated';
                    $message = 'Updated snapshot. Review and commit the changes.';
                } else {
                    $status = 'passed';
                    $message = 'Snapshot is up to date.';
                }
            } elseif ($baseline === null) {
                $expected = $this->comparison->create($actual, $second);
                $message = 'Missing baseline; creation blocked in Production.';
                if (!$this->writePolicy->isReadOnly()) {
                    $this->storage->create($path, $expected);
                    $message = 'Created baseline. Review and commit it before rerunning.';
                }

                $status = 'missing';
            } elseif ($this->comparison->matches($baseline, $actual) && $this->comparison->matches($baseline, $second)) {
                $status = 'passed';
                $message = 'Both samples match the saved baseline.';
            } else {
                $status = 'failed';
                $message = 'Rendered HTML differs from the saved baseline.';
                if ($this->comparison->matches($baseline, $actual)) {
                    $actual = $this->comparison->maskForDiff($baseline, $second, $actual);
                } else {
                    $actual = $this->comparison->maskForDiff($baseline, $actual, $second);
                }
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
            $status = 'error';
            $message = $throwable->getMessage();
        }

        return ['identifier' => $identifier, 'status' => $status, 'message' => $message, 'path' => $path, 'expected' => $expected, 'actual' => $actual, 'exception' => $exception];
    }

    /** @param list<array{status: string}> $results */
    public static function exitCode(array $results): int
    {
        $code = $results === [] ? 1 : 0;
        foreach ($results as $result) {
            $code |= match ($result['status']) {
                'passed' => 0,
                'missing', 'updated' => 2,
                default => 1,
            };
        }

        return $code;
    }
}
