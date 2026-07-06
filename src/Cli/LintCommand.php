<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Cli;

use MahoModuleGenerator\Linter;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'lint', description: 'Lint an existing module directory against Maho best-practice rules')]
final class LintCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Module directory to lint');
        $this->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'critical|warning|nit', 'nit');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text|json', 'text');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rank = ['critical' => 3, 'warning' => 2, 'nit' => 1];
        $min = $rank[(string) $input->getOption('min-severity')] ?? 1;

        try {
            $findings = (new Linter())->lint((string) $input->getArgument('path'));
        } catch (SpecException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $findings = array_values(array_filter($findings, static fn(array $f): bool => ($rank[$f['severity']] ?? 1) >= $min));
        usort($findings, static fn(array $a, array $b): int =>
            [$rank[$b['severity']], $a['file'], $a['line']] <=> [$rank[$a['severity']], $b['file'], $b['line']]);

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($findings === []) {
                $output->writeln('<info>clean - no findings</info>');
                return Command::SUCCESS;
            }
            foreach ($findings as $f) {
                $tag = match ($f['severity']) {
                    'critical' => '<error> CRIT </error>',
                    'warning'  => '<comment> WARN </comment>',
                    default    => '<info> nit  </info>',
                };
                $output->writeln(sprintf('%s %s %s:%d', $tag, $f['rule'], $f['file'], $f['line']));
                $output->writeln('        ' . $f['message']);
                $output->writeln('        fix: ' . $f['fix']);
            }
            $output->writeln('');
            $output->writeln(sprintf('<comment>%d finding(s)</comment>', count($findings)));
        }

        // (array_any() is PHP 8.4+; stay 8.3-compatible)
        $hasCritical = array_filter($findings, static fn(array $f): bool => $f['severity'] === 'critical') !== [];
        return $hasCritical ? Command::FAILURE : Command::SUCCESS;
    }
}
