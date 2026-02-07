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

class ApiCommand extends Command
{
    protected static $defaultName = 'api';

    protected function configure(): void
    {
        $this
            ->setDescription('Manage API registry (remote)')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (add)')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method (get, post, put, delete)')
            ->addOption('uri', null, InputOption::VALUE_REQUIRED, 'Route uri, ex: /me/history')
            ->addOption('group', null, InputOption::VALUE_OPTIONAL, 'API group')
            ->addOption('description', null, InputOption::VALUE_OPTIONAL, 'API description')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Mark API as public')
            ->addOption('beta', null, InputOption::VALUE_NONE, 'Mark API as beta')
            ->addOption('deprecated', null, InputOption::VALUE_NONE, 'Mark API as deprecated')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Mark API as inactive')
            ->addOption('no-permission', null, InputOption::VALUE_NONE, 'Skip permission creation')
            ->addOption('permission-desc', null, InputOption::VALUE_OPTIONAL, 'Permission description')
            ->addOption('permission-function', null, InputOption::VALUE_OPTIONAL, 'Permission function', '*')
            ->addOption('param', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Parameter: name:in:type:required:description')
            ->addOption('response', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Response: code:description:json or @file')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update existing API if found')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show changes without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        if ($action !== 'add') {
            $output->writeln('<error>Unknown action. Supported: add</error>');
            return Command::INVALID;
        }

        return $this->handleAdd($input, $output);
    }

    private function handleAdd(InputInterface $input, OutputInterface $output): int
    {
        $method = strtolower(trim((string) $input->getOption('method')));
        $uri = trim((string) $input->getOption('uri'));

        if ($method === '' || $uri === '') {
            $output->writeln('<error>Both --method and --uri are required.</error>');
            return Command::INVALID;
        }

        $allowedMethods = ['get', 'post', 'put', 'delete'];
        if (!in_array($method, $allowedMethods, true)) {
            $output->writeln('<error>Invalid method. Allowed: get, post, put, delete.</error>');
            return Command::INVALID;
        }

        $config = new ConfigStore();
        $remote = $config->get('remote') ?: getenv('NEXUS_REMOTE');
        if (!$remote) {
            $output->writeln('<error>Missing remote. Run: nexus init --remote="adion-api.eu"</error>');
            return Command::FAILURE;
        }
        $remote = ConfigStore::normalizeRemote($remote);

        $ak = $config->get('ak') ?: getenv('NEXUS_AK');
        $ck = $config->get('ck') ?: getenv('NEXUS_CK');
        $sk = $config->get('sk') ?: getenv('NEXUS_SK');

        if (!$ak) {
            $output->writeln('<error>Missing ak. Set with: nexus init --ak="..."</error>');
            return Command::FAILURE;
        }

        $client = new ApiClient($remote, $ak, $ck, $sk);

        $route = $this->normalizeRoute($uri);
        $group = (string) $input->getOption('group');
        if ($group === '') {
            $group = $this->inferGroup($route);
        }

        $description = (string) $input->getOption('description');
        if ($description === '') {
            $description = strtoupper($method) . ' ' . $route;
        }

        $payload = [
            'group' => $group,
            'route' => $route,
            'method' => $method,
            'description' => $description,
            'is_beta' => (bool) $input->getOption('beta'),
            'is_public' => (bool) $input->getOption('public'),
            'is_deprecated' => (bool) $input->getOption('deprecated'),
            'is_active' => !$input->getOption('inactive'),
        ];

        $dryRun = (bool) $input->getOption('dry-run');

        $existing = $this->findExistingApi($client, $output, $route, $method);
        if ($existing && !$input->getOption('update')) {
            $output->writeln('<error>API already exists. Use --update to modify it.</error>');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<info>Dry-run: API would be ' . ($existing ? 'updated' : 'created') . '.</info>');
            return Command::SUCCESS;
        }

        if ($existing) {
            $result = $this->request($client, $output, 'PUT', '/admin/api/' . $existing['id'], $payload);
        } else {
            $result = $this->request($client, $output, 'POST', '/admin/api', $payload);
        }

        if (!$result || empty($result['id'])) {
            $output->writeln('<error>API creation/update failed.</error>');
            return Command::FAILURE;
        }

        $apiId = $result['id'];
        $output->writeln('<info>API ' . ($existing ? 'updated' : 'created') . '.</info>');

        if (!$input->getOption('no-permission')) {
            $this->createPermission($client, $output, $apiId, $description, (string) $input->getOption('permission-function'), (string) $input->getOption('permission-desc'));
        }

        $this->createParameters($client, $output, $apiId, $input->getOption('param'));
        $this->createResponses($client, $output, $apiId, $input->getOption('response'));

        return Command::SUCCESS;
    }

    private function request(ApiClient $client, OutputInterface $output, string $method, string $path, $body = null): ?array
    {
        $response = $client->request($method, $path, $body);
        if ($response['status'] === 0) {
            $output->writeln('<error>HTTP error: ' . $response['error'] . '</error>');
            return null;
        }

        if ($response['status'] >= 400) {
            $message = $response['data']['message'] ?? $response['raw'];
            $output->writeln('<error>HTTP ' . $response['status'] . ': ' . $message . '</error>');
            return null;
        }

        return $response['data'] ?? [];
    }

    private function findExistingApi(ApiClient $client, OutputInterface $output, string $route, string $method): ?array
    {
        $list = $this->request($client, $output, 'GET', '/admin/api');
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $api) {
            if (!is_array($api)) {
                continue;
            }
            if (($api['route'] ?? null) === $route && strtolower((string) ($api['method'] ?? '')) === $method) {
                return $api;
            }
        }

        return null;
    }

    private function createPermission(ApiClient $client, OutputInterface $output, string $apiId, string $apiDescription, string $function, string $description): void
    {
        $payload = [];
        $payload['function'] = $function !== '' ? $function : '*';
        $payload['description'] = $description !== '' ? $description : $apiDescription;

        $result = $this->request($client, $output, 'POST', '/admin/api/' . $apiId . '/permission', $payload);
        if ($result) {
            $output->writeln('<info>Permission created.</info>');
        }
    }

    private function createParameters(ApiClient $client, OutputInterface $output, string $apiId, array $params): void
    {
        if (empty($params)) {
            return;
        }

        foreach ($params as $raw) {
            $parts = explode(':', $raw, 5);
            if (count($parts) < 4) {
                $output->writeln('<comment>Invalid param format, expected name:in:type:required:description</comment>');
                continue;
            }

            $name = trim($parts[0]);
            $in = trim($parts[1]);
            $type = trim($parts[2]);
            $requiredRaw = trim($parts[3]);
            $description = isset($parts[4]) ? trim($parts[4]) : '';

            if ($name === '' || $in === '' || $type === '') {
                $output->writeln('<comment>Invalid param format, missing name/in/type</comment>');
                continue;
            }

            $required = filter_var($requiredRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($required === null) {
                $required = $requiredRaw === '1';
            }

            $payload = [
                'name' => $name,
                'description' => $description,
                'in' => $in,
                'type' => $type,
                'required' => (bool) $required,
            ];

            $result = $this->request($client, $output, 'POST', '/admin/api/' . $apiId . '/parameter', $payload);
            if ($result) {
                $output->writeln('<info>Parameter created: ' . $name . '</info>');
            }
        }
    }

    private function createResponses(ApiClient $client, OutputInterface $output, string $apiId, array $responses): void
    {
        if (empty($responses)) {
            return;
        }

        foreach ($responses as $raw) {
            $parts = explode(':', $raw, 3);
            if (count($parts) < 3) {
                $output->writeln('<comment>Invalid response format, expected code:description:json</comment>');
                continue;
            }

            $code = (int) trim($parts[0]);
            $description = trim($parts[1]);
            $content = trim($parts[2]);

            if ($content !== '' && $content[0] === '@') {
                $file = substr($content, 1);
                if (!is_file($file)) {
                    $output->writeln('<comment>Response file not found: ' . $file . '</comment>');
                    continue;
                }
                $content = (string) file_get_contents($file);
            }

            if (!$this->isValidJson($content)) {
                $output->writeln('<comment>Invalid JSON for response code ' . $code . '</comment>');
                continue;
            }

            $payload = [
                'code' => $code,
                'description' => $description,
                'content' => $content,
            ];

            $result = $this->request($client, $output, 'POST', '/admin/api/' . $apiId . '/response', $payload);
            if ($result) {
                $output->writeln('<info>Response created: ' . $code . '</info>');
            }
        }
    }

    private function isValidJson(string $json): bool
    {
        if ($json === '') {
            return false;
        }
        json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function normalizeRoute(string $uri): string
    {
        $route = trim($uri);
        if (strpos($route, 'http') === 0) {
            $parsed = parse_url($route);
            if (!empty($parsed['path'])) {
                $route = $parsed['path'];
            }
        }

        if (strpos($route, '/api/') === 0) {
            $route = substr($route, 4);
        } elseif (strpos($route, 'api/') === 0) {
            $route = substr($route, 3);
        }

        if ($route === '') {
            return '/';
        }

        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        if (strlen($route) > 1) {
            $route = rtrim($route, '/');
        }

        return $route;
    }

    private function inferGroup(string $route): string
    {
        $trimmed = ltrim($route, '/');
        if ($trimmed === '') {
            return 'default';
        }

        $parts = explode('/', $trimmed);
        return $parts[0] ?: 'default';
    }
}
