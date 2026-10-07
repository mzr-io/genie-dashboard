<?php

namespace App\Console\Commands;

use App\Support\ErrorCodes\ErrorCodeGenerator;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('dashflow:error-codes {--check : Fail instead of writing when the generated file is stale}')]
class ErrorCodesCommand extends Command
{
    protected $description = 'Generate resources/js/types/error-codes.ts from every module\'s Contracts/ErrorCode.php';

    public function handle(ErrorCodeGenerator $generator): int
    {
        try {
            $content = $generator->render(ErrorCodeGenerator::discover(app_path()));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = base_path(ErrorCodeGenerator::OUTPUT);

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $content) {
                $this->error(ErrorCodeGenerator::OUTPUT.' is stale: run php artisan dashflow:error-codes.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        file_put_contents($path, $content);
        $this->info('Wrote '.ErrorCodeGenerator::OUTPUT);

        return self::SUCCESS;
    }
}
