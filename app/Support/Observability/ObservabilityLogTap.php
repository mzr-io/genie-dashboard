<?php

namespace App\Support\Observability;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/** Applied to every log channel that writes anywhere, so scrubbing cannot be skipped by configuration. */
final class ObservabilityLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new ContextLogProcessor(app(RequestContext::class)));
        }
    }
}
