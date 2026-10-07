<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use LogicException;

/** A curl handler that never touches the network: it records the options of every transfer and answers from a queue. */
final class FakeCurl implements CurlClient
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /** @param  list<CurlResult|\Throwable>  $queue  a Throwable in the queue is thrown, as a failed transfer would be */
    public function __construct(public array $queue = []) {}

    public function execute(array $options): CurlResult
    {
        $this->calls[] = $options;

        $next = array_shift($this->queue) ?? throw new LogicException('No scripted answer left.');

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @param  array<string, list<string>>  $headers */
    public static function answer(int $status = 200, string $body = '{}', array $headers = []): CurlResult
    {
        return new CurlResult($status, $headers, $body);
    }

    public static function redirect(string $location, int $status = 302): CurlResult
    {
        return new CurlResult($status, ['location' => [$location]], '');
    }
}
