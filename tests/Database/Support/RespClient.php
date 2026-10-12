<?php

namespace Tests\Database\Support;

use App\Modules\Results\Infrastructure\ValkeyCommands;
use RuntimeException;

/**
 * A minimal RESP client for the throwaway Valkey of `compose.test.yaml` (Story 4.2). The tools image has no Redis extension, and the Block Result
 * store's Lua script has to run on a real server to mean anything, so this speaks the protocol directly.
 */
final class RespClient implements ValkeyCommands
{
    /** @var resource */
    private $socket;

    public function __construct(?int $port = null)
    {
        $port ??= (int) (getenv('DASHFLOW_TEST_VALKEY_PORT') ?: 56379);
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 3);

        if ($socket === false) {
            throw new RuntimeException("The test Valkey is not reachable on port {$port}: {$error}. Start it with bin/test-db up.");
        }

        $this->socket = $socket;
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->command('EVAL', $script, (string) count($keys), ...$keys, ...$args);
    }

    public function hgetall(string $key): array
    {
        $flat = $this->command('HGETALL', $key);
        $out = [];

        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $out[(string) $flat[$i]] = (string) $flat[$i + 1];
        }

        return $out;
    }

    public function command(string ...$parts): mixed
    {
        $line = '*'.count($parts)."\r\n";

        foreach ($parts as $part) {
            $line .= '$'.strlen($part)."\r\n".$part."\r\n";
        }

        fwrite($this->socket, $line);

        return $this->read();
    }

    private function read(): mixed
    {
        $line = rtrim((string) fgets($this->socket), "\r\n");
        $type = $line[0] ?? '';
        $rest = substr($line, 1);

        return match ($type) {
            '+' => $rest,
            '-' => throw new RuntimeException('Valkey: '.$rest),
            ':' => (int) $rest,
            '$' => $this->bulk((int) $rest),
            '*' => $this->array((int) $rest),
            default => throw new RuntimeException('Unexpected reply: '.$line),
        };
    }

    private function bulk(int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $data = '';

        while (strlen($data) < $length + 2) {
            $data .= (string) fread($this->socket, $length + 2 - strlen($data));
        }

        return substr($data, 0, $length);
    }

    /** @return list<mixed>|null */
    private function array(int $count): ?array
    {
        if ($count < 0) {
            return null;
        }

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->read();
        }

        return $items;
    }
}
