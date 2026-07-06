<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Cli;

use MahoModuleGenerator\Differ;
use MahoModuleGenerator\Generator;
use MahoModuleGenerator\Spec;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'audit', description: 'Golden-diff a spec-generated module: regenerate from the spec and report missing / identical / drifted files')]
final class AuditCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Path to the YAML spec file');
        $this->addArgument('module', InputArgument::REQUIRED, 'Module directory to audit against the spec');
        $this->addOption('fail-on-drift', null, InputOption::VALUE_NONE,
            'Exit non-zero when files have drifted from the generated form (default: drift is informational - hand edits are often intentional)');
        $this->addOption('no-diff', null, InputOption::VALUE_NONE, 'Report statuses only, without inline diffs');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $spec = Spec::fromYamlFile((string) $input->getArgument('spec'));
            $files = (new Generator())->generate($spec);
        } catch (SpecException $e) {
            $output->writeln('<error>spec error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $moduleDir = rtrim((string) $input->getArgument('module'), '/');
        if (!is_dir($moduleDir)) {
            $output->writeln("<error>not a directory: $moduleDir</error>");
            return Command::FAILURE;
        }

        $differ = new Differ();
        $showDiff = !$input->getOption('no-diff');
        $missing = 0;
        $identical = 0;
        $drifted = 0;

        foreach ($files as $rel => $expected) {
            $target = "$moduleDir/$rel";
            if (!is_file($target)) {
                $output->writeln("  <error>missing</error>    $rel");
                $missing++;
                continue;
            }
            $actual = (string) file_get_contents($target);
            if ($actual === $expected) {
                $identical++;
                continue;
            }
            $drifted++;
            $output->writeln("  <comment>drifted</comment>    $rel");
            if ($showDiff) {
                foreach (explode("\n", rtrim($differ->diff($expected, $actual))) as $line) {
                    $colour = match ($line[0] ?? ' ') {
                        '-' => '<fg=red>',
                        '+' => '<fg=green>',
                        default => '<fg=gray>',
                    };
                    $output->writeln('    ' . $colour . str_replace(['<error>', '<info>'], '', $line) . '</>');
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%s: %d identical, %d drifted, %d missing (of %d generated)</info>',
            $spec->moduleName(),
            $identical,
            $drifted,
            $missing,
            count($files),
        ));
        if ($drifted > 0 && !$input->getOption('fail-on-drift')) {
            $output->writeln('<comment>drift is informational - hand edits to generated files are often intentional. Pass --fail-on-drift to treat drift as failure.</comment>');
        }

        if ($missing > 0) {
            return Command::FAILURE;
        }
        if ($drifted > 0 && $input->getOption('fail-on-drift')) {
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
