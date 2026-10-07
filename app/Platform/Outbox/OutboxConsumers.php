<?php

namespace App\Platform\Outbox;

use InvalidArgumentException;

/** The registry of outbox consumers. Modules register theirs at boot. */
final class OutboxConsumers
{
    /** @var array<string, OutboxConsumer> */
    private array $consumers = [];

    public function register(OutboxConsumer $consumer): void
    {
        $name = $consumer->name();

        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('A consumer needs a name of 1 to 100 characters.');
        }

        if (isset($this->consumers[$name])) {
            throw new InvalidArgumentException("Outbox consumer {$name} is already registered.");
        }

        $this->consumers[$name] = $consumer;
    }

    /**
     * @return list<OutboxConsumer>
     */
    public function all(): array
    {
        return array_values($this->consumers);
    }
}
