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

#[AsCommand(name: 'frontend-studio:test', description: 'Compare saved fixture HTML in a site/language against reviewed baselines')]
final class SnapshotCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site identifier')
            ->addArgument('language', InputArgument::REQUIRED, 'Site language hreflang')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Variant, component, folder or namespace identifier', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $identifiers = $this->runner->discover((string)$input->getOption('scope'));
        } catch (Throwable $throwable) {
            $output->writeln("<fg=red;options=bold>ERROR</> during snapshot discovery:\n" . OutputFormatter::escape($this->formatException($throwable, $output)));
            return 1;
        }

        $results = [];
        foreach ($identifiers as $identifier) {
            $result = $this->runner->run($identifier, (string)$input->getArgument('site'), (string)$input->getArgument('language'));
            $results[] = $result;
            [$label, $color] = match ($result['status']) {
                'missing' => ['WARNING (MISSING)', 'yellow'],
                'failed' => ['WARNING (MISMATCH)', 'yellow'],
                'passed' => ['PASSED', 'green'],
                default => ['ERROR', 'red'],
            };
            $parts = explode(':', $identifier, 3);
            $component = $parts[0] . ':' . $parts[1];
            $variant = $parts[2];
            $message = $this->relativeText($result['message']);
            if ($result['status'] !== 'error' && str_contains($result['expected'], Comparison::MARKER)) {
                $message .= ' Dynamic markers used.';
            }

            $output->writeln(
                '<fg=' . $color . ';options=bold>' . $label . '</> '
                . '<fg=cyan>' . OutputFormatter::escape($component) . '</>:'
                . '<fg=magenta>' . OutputFormatter::escape($variant) . '</>: '
                . OutputFormatter::escape($message),
            );
            if ($output->isVerbose() && $result['path'] !== '') {
                $output->writeln('  <fg=gray>' . OutputFormatter::escape(Path::makeRelative($result['path'], Environment::getProjectPath())) . '</>');
            }

            if ($result['exception'] !== null) {
                $output->writeln('<fg=red>' . OutputFormatter::escape($this->formatException($result['exception'], $output)) . '</>');
            }

            if ($result['status'] === 'failed') {
                $output->writeln(OutputFormatter::escape("EXPECTED:\n" . $result['expected'] . "ACTUAL:\n" . $result['actual']));
            }
        }

        $passed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'passed'));
        $color = $passed === count($results) ? 'green' : (array_any($results, static fn(array $result): bool => $result['status'] === 'error') ? 'red' : 'yellow');
        $output->writeln('<fg=' . $color . ';options=bold>' . $passed . '/' . count($results) . ' passed.</>');
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
