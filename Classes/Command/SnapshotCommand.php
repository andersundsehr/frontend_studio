<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Command;

use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Symfony\Component\Filesystem\Path;
use TYPO3\CMS\Core\Core\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Andersundsehr\FrontendStudio\Service\Snapshot\InlineDiff;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use RuntimeException;

#[AsCommand(name: 'frontend-studio:test', description: 'Component Snapshots: compare rendered fixture HTML against reviewed snapshots')]
final class SnapshotCommand extends Command
{
    public function __construct(private readonly Runner $runner, private readonly PreviewContextResolver $previewContextResolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (defaults to the backend module selection)')
            ->addArgument('language', InputArgument::OPTIONAL, "Site language hreflang (defaults to the selected site's first enabled language)")
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Variant, component, folder or namespace identifier', '')
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Regenerate snapshots in the selected scope outside Production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $site = $input->getArgument('site');
            $language = $input->getArgument('language');
            if ($site === null || $language === null) {
                $defaults = $this->previewContextResolver->resolve(new ServerRequest('/'), $site, $language);
                if ($defaults === null) {
                    throw new RuntimeException('No TYPO3 sites are configured for Component Snapshots.', 1773112751);
                }

                $site ??= $defaults[0];
                $language ??= $defaults[1];
                if ($language === '') {
                    throw new RuntimeException('Snapshot site "' . $site . '" has no enabled languages.', 1773112752);
                }
            }

            $identifiers = $this->runner->discover((string)$input->getOption('scope'));
        } catch (Throwable $throwable) {
            $output->writeln("<fg=red;options=bold>ERROR</> during snapshot discovery:\n" . OutputFormatter::escape($this->formatException($throwable, $output)));
            return 1;
        }

        $update = (bool)$input->getOption('update');
        $results = [];
        foreach ($identifiers as $identifier) {
            $result = $this->runner->run($identifier, (string)$site, (string)$language, $update);
            $results[] = $result;
            [$label, $color] = match ($result['status']) {
                'missing' => ['WARNING (MISSING)', 'yellow'],
                'failed' => ['WARNING (MISMATCH)', 'yellow'],
                'passed' => ['PASSED', 'green'],
                'updated' => ['UPDATED', 'yellow'],
                default => ['ERROR', 'red'],
            };
            $parts = explode(':', $identifier, 3);
            $component = $parts[0] . ':' . $parts[1];
            $variant = $parts[2];
            $message = $this->relativeText($result['message']);
            if ($result['status'] !== 'error' && (str_contains($result['expected'], Comparison::MARKER) || str_contains($result['actual'], Comparison::MARKER))) {
                $message .= ' Dynamic markers used.';
            }

            $output->writeln(
                '<fg=' . $color . ';options=bold>' . $label . '</> '
                . '<fg=cyan>' . OutputFormatter::escape($component) . '</>:'
                . '<fg=magenta>' . OutputFormatter::escape($variant) . '</>: '
                . OutputFormatter::escape($message),
            );
            if (($output->isVerbose() || in_array($result['status'], ['missing', 'failed'], true)) && $result['path'] !== '') {
                $output->writeln('  <fg=gray>' . OutputFormatter::escape(Path::makeRelative($result['path'], Environment::getProjectPath())) . '</>');
            }

            if ($result['exception'] !== null) {
                $output->writeln('<fg=red>' . OutputFormatter::escape($this->formatException($result['exception'], $output)) . '</>');
            }

            if ($result['status'] === 'failed') {
                $output->writeln(new InlineDiff()->render($result['expected'], $result['actual']));
            }
        }

        $passed = count(array_filter($results, static fn(array $result): bool => in_array($result['status'], ['passed', 'updated'], true)));
        $updated = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'updated'));
        $color = $passed === count($results) ? 'green' : (array_any($results, static fn(array $result): bool => $result['status'] === 'error') ? 'red' : 'yellow');
        $summary = $update ? ' snapshots ready (' . $updated . ' updated).' : ' passed.';
        $output->writeln('<fg=' . $color . ';options=bold>' . $passed . '/' . count($results) . $summary . '</>');
        if (array_any($results, static fn(array $result): bool => $result['status'] === 'failed')) {
            $output->writeln('<fg=yellow>To accept these changes, rerun this command with --update outside Production.</>');
            $output->writeln('<fg=yellow>Review and commit the updated snapshot files, then rerun without --update to verify.</>');
        }

        return Runner::exitCode($results);
    }

    private function formatException(Throwable $exception, OutputInterface $output): string
    {
        return $this->relativeText($output->isVerbose() ? (string)$exception : $exception::class . ': ' . $exception->getMessage());
    }

    private function relativeText(string $text): string
    {
        return str_replace(Environment::getProjectPath() . '/', '', $text);
    }
}
