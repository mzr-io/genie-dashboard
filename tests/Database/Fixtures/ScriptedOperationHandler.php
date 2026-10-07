<?php

namespace Tests\Database\Fixtures;

use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;
use RuntimeException;

/** An Operation handler a test scripts: what it does, what input it saw and whether it was asked to clean up. */
final class ScriptedOperationHandler implements OperationHandler
{
    /** 'succeed', 'fail', 'throw' or 'sqlerror' */
    public static string $mode = 'succeed';

    /** @var list<array<string, mixed>> */
    public static array $inputs = [];

    /** @var list<string> */
    public static array $cleaned = [];

    public static bool $cleanupThrows = false;

    public static function reset(): void
    {
        self::$mode = 'succeed';
        self::$inputs = [];
        self::$cleaned = [];
        self::$cleanupThrows = false;
    }

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        self::$inputs[] = $input;

        return match (self::$mode) {
            'sqlerror' => Illuminate\Support\Facades\DB::select('select * from a_table_that_does_not_exist'),
            'throw' => throw new RuntimeException('boom with a secret: s3cr3t-canary'),
            'fail' => new OperationOutcome(false, ['ok' => false, 'code' => 'fetch-failed']),
            default => new OperationOutcome(true, ['ok' => true, 'status' => 200]),
        };
    }

    public function cleanup(Operation $operation): void
    {
        self::$cleaned[] = $operation->id;

        if (self::$cleanupThrows) {
            throw new RuntimeException('cleanup failed');
        }
    }
}
