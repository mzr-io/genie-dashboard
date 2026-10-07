<?php

namespace App\Support\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds correlation ids and scrubs message, context and extra. Always on. */
final class ContextLogProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = Scrubber::value($record->extra);
        $extra = is_array($extra) ? $extra : [];

        foreach ([
            'request_id' => $this->context->requestId(),
            'trace_id' => $this->context->traceId(),
            'workspace_id' => $this->context->workspaceId(),
        ] as $key => $value) {
            if ($value !== null) {
                $extra[$key] = $value;
            }
        }

        $context = Scrubber::value($record->context);

        return $record->with(
            message: Scrubber::text($record->message),
            context: is_array($context) ? $context : [],
            extra: $extra,
        );
    }
}
