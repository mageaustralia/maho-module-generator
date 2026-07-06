<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Cli;

use MahoModuleGenerator\Extractor\M1SpecExtractor;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extract', description: 'Clean-room a Magento 1 module: derive a spec (structure only, never code) for human review')]
final class ExtractCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('m1-module', InputArgument::REQUIRED, 'Path to the Magento 1 module directory');
        $this->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Write the spec to this file (default: stdout)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $yaml = (new M1SpecExtractor())->extractToYaml((string) $input->getArgument('m1-module'));
        } catch (SpecException $e) {
            $output->writeln('<error>extract error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $out = $input->getOption('out');
        if ($out !== null && $out !== '') {
            file_put_contents((string) $out, $yaml);
            $output->writeln("<info>spec written to $out</info>");
            $output->writeln('<comment>Review every line (see the TODO header), then: maho-module-gen generate ' . $out . '</comment>');
        } else {
            $output->write($yaml);
        }
        return Command::SUCCESS;
    }
}
