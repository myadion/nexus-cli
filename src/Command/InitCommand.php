<?php

declare(strict_types=1);

namespace Adion\NexusCli\Command;

use Adion\NexusCli\Config\ConfigStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    protected static $defaultName = 'init';

    protected function configure(): void
    {
        $this
            ->setDescription('Initialize Nexus CLI configuration')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'Remote API host, ex: adion-api.eu')
            ->addOption('ak', null, InputOption::VALUE_OPTIONAL, 'Application key (X-Api-Ak)')
            ->addOption('ck', null, InputOption::VALUE_OPTIONAL, 'Consumer key (X-Api-Ck)')
            ->addOption('sk', null, InputOption::VALUE_OPTIONAL, 'Secret key (X-Api-Sk)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $remote = trim((string) $input->getOption('remote'));
        if ($remote === '') {
            $output->writeln('<error>--remote is required.</error>');
            return Command::INVALID;
        }
        $config = new ConfigStore();
        $configPath = $config->path();

        if (file_exists($configPath) && !$input->getOption('force')) {
            $output->writeln('<comment>Config already exists. Use --force to overwrite.</comment>');
            return Command::FAILURE;
        }

        $payload = [
            'remote' => ConfigStore::normalizeRemote($remote),
        ];

        $ak = trim((string) $input->getOption('ak'));
        $ck = trim((string) $input->getOption('ck'));
        $sk = trim((string) $input->getOption('sk'));

        if ($ak !== '') {
            $payload['ak'] = $ak;
        }
        if ($ck !== '') {
            $payload['ck'] = $ck;
        }
        if ($sk !== '') {
            $payload['sk'] = $sk;
        }

        $config->merge($payload);

        if (!$config->save()) {
            $output->writeln('<error>Failed to write config: ' . $configPath . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Config written: ' . $configPath . '</info>');
        return Command::SUCCESS;
    }
}
