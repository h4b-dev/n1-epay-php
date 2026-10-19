<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Runs FakeApi under PHP's built-in server (`php -S`) for the contract tests. Started once by
 * tests/bootstrap.php and stopped when PHPUnit exits.
 */
final class FakeApiServer
{
    private static ?self $instance = null;

    /** @var resource|null */
    private $process;

    private function __construct(public readonly string $baseUrl, public readonly string $stateDir, mixed $process)
    {
        $this->process = \is_resource($process) ? $process : null;
    }

    public static function instance(): self
    {
        return self::$instance ??= self::start();
    }

    public static function start(): self
    {
        $stateDir = sys_get_temp_dir() . '/n1co-epay-fake-' . bin2hex(random_bytes(6));
        if (!mkdir($stateDir, 0o700) && !is_dir($stateDir)) {
            throw new \RuntimeException('Cannot create the fake API state directory.');
        }
        file_put_contents($stateDir . '/spec.json', json_encode(Yaml::parseFile(Spec::PATH), JSON_THROW_ON_ERROR));
        // The port is free when chosen but may be taken before php -S binds it: try again.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $server = self::launch($stateDir, self::freePort());
            if ($server !== null) {
                register_shutdown_function([$server, 'stop']);
                self::$instance = $server;

                return $server;
            }
        }

        throw new \RuntimeException('The fake API did not start; see ' . $stateDir . '/server.log.');
    }

    private static function launch(string $stateDir, int $port): ?self
    {
        $env = getenv();
        $env['N1CO_FAKE_STATE'] = $stateDir;
        // One process: PHP_CLI_SERVER_WORKERS forks workers that outlive proc_terminate() on some
        // PHP versions and keep inherited descriptors open.
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Contract/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stateDir . '/server.log', 'a'], 2 => ['file', $stateDir . '/server.log', 'a']],
            $pipes,
            null,
            $env,
        );
        if ($process === false) {
            return null;
        }
        $server = new self('http://127.0.0.1:' . $port, $stateDir, $process);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($process)['running']) {
                break; // for example: the address is already in use
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }
        $server->terminate();

        return null;
    }

    private function terminate(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function stop(): void
    {
        $this->terminate();
        $files = glob($this->stateDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    public function reset(): void
    {
        foreach (['requests.jsonl', 'violations.jsonl'] as $name) {
            file_put_contents($this->stateDir . '/' . $name, '');
        }
        file_put_contents($this->stateDir . '/forced.json', '[]');
    }

    /**
     * Answers the next request with this reply instead of the contract's.
     *
     * @param array<string, string> $headers
     */
    public function force(int $status, mixed $body, array $headers = [], float $delay = 0.0): void
    {
        $file = $this->stateDir . '/forced.json';
        $queue = json_decode((string) @file_get_contents($file), true);
        $queue = \is_array($queue) ? $queue : [];
        $queue[] = ['status' => $status, 'body' => $body, 'headers' => $headers, 'delay' => $delay];
        file_put_contents($file, json_encode($queue, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * @return list<array{operationId: string|null, method: string, path: string, search: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $out = [];
        foreach ($this->lines('requests.jsonl') as $record) {
            $headers = [];
            foreach (Spec::map($record['headers'] ?? null) as $name => $value) {
                $headers[$name] = \is_string($value) ? $value : '';
            }
            $out[] = [
                'operationId' => Spec::string($record['operationId'] ?? null),
                'method' => (string) Spec::string($record['method'] ?? null),
                'path' => (string) Spec::string($record['path'] ?? null),
                'search' => (string) Spec::string($record['search'] ?? null),
                'headers' => $headers,
                'body' => (string) Spec::string($record['body'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return array_map(static fn(array $record): string => (string) Spec::string($record['violation'] ?? null), $this->lines('violations.jsonl'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $name): array
    {
        $out = [];
        $lines = file($this->stateDir . '/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            $out[] = Spec::map(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }

        return $out;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('No free port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
