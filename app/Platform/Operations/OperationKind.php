<?php

namespace App\Platform\Operations;

use InvalidArgumentException;

/**
 * A kind of Operation a module registers: its name, the queue it runs on, how long it stays readable and the handler
 * class (resolved from the container when the job runs).
 */
final readonly class OperationKind
{
    public const NAME = '/\A[a-z][a-z0-9_]{0,47}\z/D';

    /**
     * @param  class-string<OperationHandler>  $handler
     */
    public function __construct(
        public string $name,
        public string $queue,
        public int $ttlSeconds,
        public string $handler,
    ) {
        if (preg_match(self::NAME, $name) !== 1 || preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D', $queue) !== 1 || $ttlSeconds < 1) {
            throw new InvalidArgumentException('An Operation kind needs a snake_case name, a queue name and a positive lifetime.');
        }
    }
}
