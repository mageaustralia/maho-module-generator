<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Cli;

use MahoModuleGenerator\Generator;
use MahoModuleGenerator\Spec;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'generate', description: 'Generate a Maho module from a YAML spec')]
final class GenerateCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Path to the YAML spec file');
        $this->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output directory (module root)', '.');
        $this->addOption('into', 'i', InputOption::VALUE_REQUIRED,
            'Delta mode: add spec artifacts to an EXISTING module dir. Never overwrites; '
            . 'refuses non-module targets unless --force-new.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List files without writing');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files (not allowed with --into)');
        $this->addOption('force-new', null, InputOption::VALUE_NONE,
            'With --into: allow a target directory that does not look like a module yet');
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

        $into = $input->getOption('into');
        $deltaMode = $into !== null && $into !== '';
        $outDir = rtrim((string) ($deltaMode ? $into : $input->getOption('out')), '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if ($deltaMode) {
            if ($force) {
                $output->writeln('<error>--force cannot be combined with --into (delta mode never overwrites)</error>');
                return Command::FAILURE;
            }
            // Guard: --into targets an existing module. A bare/foreign directory
            // is almost always a typo - demand --force-new to proceed.
            $looksLikeModule = is_file("$outDir/composer.json") || is_dir("$outDir/app");
            if (!$looksLikeModule && !$input->getOption('force-new')) {
                $output->writeln(
                    "<error>$outDir does not look like a module (no composer.json or app/). "
                    . 'Use --out for a fresh module, or pass --force-new to proceed anyway.</error>',
                );
                return Command::FAILURE;
            }
        }

        $written = 0;
        $skipped = 0;
        foreach ($files as $rel => $contents) {
            $target = "$outDir/$rel";
            if ($dryRun) {
                $output->writeln(sprintf('  would write  %-70s %6d bytes', $rel, strlen($contents)));
                continue;
            }
            if (is_file($target) && !$force) {
                $output->writeln("  <comment>skip (exists)</comment>  $rel");
                $skipped++;
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->writeln("<error>cannot create $dir</error>");
                return Command::FAILURE;
            }
            file_put_contents($target, $contents);
            $output->writeln("  <info>wrote</info>  $rel");
            $written++;
        }

        if (!$dryRun) {
            $output->writeln('');
            if ($deltaMode) {
                $output->writeln(sprintf(
                    '<info>%s (delta): %d file(s) added, %d already present (untouched)</info>',
                    $spec->moduleName(),
                    $written,
                    $skipped,
                ));
            } else {
                $output->writeln(sprintf(
                    '<info>%s: %d file(s) written%s</info>',
                    $spec->moduleName(),
                    $written,
                    $skipped ? ", $skipped skipped (use --force to overwrite)" : '',
                ));
            }
            $output->writeln('Next: composer dump-autoload && ./maho migrate && ./maho cache:flush');
        }
        return Command::SUCCESS;
    }
}
