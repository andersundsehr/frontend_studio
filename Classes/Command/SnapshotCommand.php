<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Command;

use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
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
            $output->writeln(OutputFormatter::escape("ERROR during snapshot discovery:\n" . $throwable));
            return 1;
        }

        $results = [];
        foreach ($identifiers as $identifier) {
            $result = $this->runner->run($identifier, (string)$input->getArgument('site'), (string)$input->getArgument('language'));
            $results[] = $result;
            $label = match ($result['status']) {
                'missing' => 'WARNING (MISSING)',
                'failed' => 'WARNING (MISMATCH)',
                default => strtoupper($result['status']),
            };
            $output->writeln(OutputFormatter::escape($label . ' ' . $identifier . ': ' . $result['message'] . ' ' . $result['path']));
            if ($result['exception'] !== null) {
                $output->writeln(OutputFormatter::escape((string)$result['exception']));
            }

            if ($result['status'] === 'failed') {
                $output->writeln(OutputFormatter::escape("EXPECTED:\n" . $result['expected'] . "ACTUAL:\n" . $result['actual']));
            }
        }

        $passed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'passed'));
        $output->writeln($passed . '/' . count($results) . ' passed.');
        return Runner::exitCode($results);
    }
}
