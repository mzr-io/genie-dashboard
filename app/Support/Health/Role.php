<?php

namespace App\Support\Health;

/**
 * The five process roles of the single Dashflow image.
 */
enum Role: string
{
    case Web = 'web';
    case Realtime = 'realtime';
    case Scheduler = 'scheduler';
    case WorkerConnector = 'worker-connector';
    case WorkerCompute = 'worker-compute';
}
