<?php

namespace App\Support\Observability;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/** One JSON object per line, correlation ids at the top level. */
final class JsonLogFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_JSON, true);
    }

    public function format(LogRecord $record): string
    {
        $extra = $record->extra;
        $line = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'message' => $record->message,
        ];
        foreach (['request_id', 'trace_id', 'workspace_id'] as $key) {
            if (isset($extra[$key])) {
                $line[$key] = $extra[$key];
            }
            unset($extra[$key]);
        }
        if ($record->context !== []) {
            $line['context'] = $record->context;
        }
        if ($extra !== []) {
            $line['extra'] = $extra;
        }

        return $this->toJson($this->normalize($line), true)."\n";
    }
}
