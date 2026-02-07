<?php

declare(strict_types=1);

namespace Adion\NexusCli\Command;

use Adion\NexusCli\Config\ConfigStore;
use Adion\NexusCli\Http\ApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuthCommand extends Command
{
    protected static $defaultName = 'auth';

    protected function configure(): void
    {
        $this
            ->setDescription('Authentication commands')
            ->addArgument('action', InputArgument::REQUIRED, 'Action (login, verify, status, logout)')
            ->addOption('remote', null, InputOption::VALUE_OPTIONAL, 'Override remote host')
            ->addOption('ak', null, InputOption::VALUE_OPTIONAL, 'Application key (X-Api-Ak)')
            ->addOption('ck', null, InputOption::VALUE_OPTIONAL, 'Consumer key (X-Api-Ck)')
            ->addOption('sk', null, InputOption::VALUE_OPTIONAL, 'Secret key (X-Api-Sk)')
            ->addOption('username', null, InputOption::VALUE_OPTIONAL, 'Username (email or phone)')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password')
            ->addOption('realm', null, InputOption::VALUE_OPTIONAL, 'Realm (telecom mode)')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'Email (telecom mode)')
            ->addOption('expiration', null, InputOption::VALUE_OPTIONAL, 'Expiration (telecom mode)')
            ->addOption('code', null, InputOption::VALUE_OPTIONAL, 'Verification code')
            ->addOption('payload', null, InputOption::VALUE_OPTIONAL, 'Raw JSON payload for login');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $config = new ConfigStore();

        $remote = (string) ($input->getOption('remote') ?: $config->get('remote') ?: getenv('NEXUS_REMOTE'));
        if ($remote === '') {
            $output->writeln('<error>Missing remote. Run: nexus init --remote="adion-api.eu"</error>');
            return Command::FAILURE;
        }
        $remote = ConfigStore::normalizeRemote($remote);

        $ak = (string) ($input->getOption('ak') ?: $config->get('ak') ?: getenv('NEXUS_AK'));
        $ck = (string) ($input->getOption('ck') ?: $config->get('ck') ?: getenv('NEXUS_CK'));
        $sk = (string) ($input->getOption('sk') ?: $config->get('sk') ?: getenv('NEXUS_SK'));

        if ($ak === '') {
            $output->writeln('<error>Missing ak. Set with: nexus init --ak="..."</error>');
            return Command::FAILURE;
        }

        $client = new ApiClient($remote, $ak, $ck, $sk);

        switch ($action) {
            case 'login':
                return $this->login($client, $config, $input, $output);
            case 'verify':
                return $this->verify($client, $config, $input, $output);
            case 'status':
                return $this->status($client, $output);
            case 'logout':
                return $this->logout($client, $config, $output);
            default:
                $output->writeln('<error>Unknown action. Supported: login, verify, status, logout</error>');
                return Command::INVALID;
        }
    }

    private function login(ApiClient $client, ConfigStore $config, InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->payloadFromOptions($input, $output);
        if ($payload === null) {
            return Command::INVALID;
        }

        $res = $client->request('POST', '/auth/login', $payload);
        if ($res['status'] >= 400 || $res['status'] === 0) {
            $message = $res['data']['message'] ?? $res['raw'] ?? $res['error'] ?? 'Login failed';
            $output->writeln('<error>Login failed: ' . $message . '</error>');
            return Command::FAILURE;
        }

        $data = $res['data'] ?? [];
        if (!empty($data['key'])) {
            $config->set('ck', $data['key']);
        }
        if (!empty($data['secret'])) {
            $config->set('sk', $data['secret']);
        }
        $config->save();

        $output->writeln('<info>Login started. Check your device for verification code.</info>');
        if (!empty($data['message'])) {
            $output->writeln('<comment>' . $data['message'] . '</comment>');
        }

        return Command::SUCCESS;
    }

    private function verify(ApiClient $client, ConfigStore $config, InputInterface $input, OutputInterface $output): int
    {
        $code = trim((string) $input->getOption('code'));
        if ($code === '') {
            $output->writeln('<error>--code is required.</error>');
            return Command::INVALID;
        }

        $ck = (string) ($input->getOption('ck') ?: $config->get('ck') ?: getenv('NEXUS_CK'));
        if ($ck === '') {
            $output->writeln('<error>Missing consumer key (ck). Login first or set --ck.</error>');
            return Command::FAILURE;
        }

        $payload = ['code' => $ck . '-' . $code];
        $res = $client->request('POST', '/auth/verify', $payload);
        if ($res['status'] >= 400 || $res['status'] === 0) {
            $message = $res['data']['message'] ?? $res['raw'] ?? $res['error'] ?? 'Verification failed';
            $output->writeln('<error>Verification failed: ' . $message . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Verification successful.</info>');
        return Command::SUCCESS;
    }

    private function status(ApiClient $client, OutputInterface $output): int
    {
        $res = $client->request('GET', '/auth/login');
        if ($res['status'] >= 400 || $res['status'] === 0) {
            $message = $res['data']['message'] ?? $res['raw'] ?? $res['error'] ?? 'Status failed';
            $output->writeln('<error>Status failed: ' . $message . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Auth status: ' . ($res['raw'] ?: 'ok') . '</info>');
        return Command::SUCCESS;
    }

    private function logout(ApiClient $client, ConfigStore $config, OutputInterface $output): int
    {
        $res = $client->request('DELETE', '/auth/login');
        if ($res['status'] >= 400 || $res['status'] === 0) {
            $message = $res['data']['message'] ?? $res['raw'] ?? $res['error'] ?? 'Logout failed';
            $output->writeln('<error>Logout failed: ' . $message . '</error>');
            return Command::FAILURE;
        }

        $config->set('ck', '');
        $config->set('sk', '');
        $config->save();

        $output->writeln('<info>Logged out.</info>');
        return Command::SUCCESS;
    }

    private function payloadFromOptions(InputInterface $input, OutputInterface $output): ?array
    {
        $raw = trim((string) $input->getOption('payload'));
        if ($raw !== '') {
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $output->writeln('<error>Invalid JSON payload.</error>');
                return null;
            }
            return $data;
        }

        $realm = trim((string) $input->getOption('realm'));
        $email = trim((string) $input->getOption('email'));
        $password = trim((string) $input->getOption('password'));
        $username = trim((string) $input->getOption('username'));
        $expiration = trim((string) $input->getOption('expiration'));

        if ($realm !== '' || $email !== '') {
            if ($email === '' || $password === '') {
                $output->writeln('<error>Telecom login requires --email and --password.</error>');
                return null;
            }
            $payload = [
                'realm' => $realm,
                'email' => $email,
                'password' => $password,
            ];
            if ($expiration !== '') {
                $payload['expiration'] = $expiration;
            }
            return $payload;
        }

        if ($username === '') {
            $output->writeln('<error>--username is required.</error>');
            return null;
        }

        if ($password !== '') {
            return [
                'username' => $username,
                'password' => $password,
            ];
        }

        return [
            'username' => $username,
        ];
    }
}
