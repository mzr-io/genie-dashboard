<?php

namespace App\Console\Commands;

use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\InvalidBlockTypePackage;
use App\Support\BlockTypes\BlockTypeGenerator;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('dashflow:block-types {--check : Fail instead of writing when a generated file is stale}')]
class BlockTypesCommand extends Command
{
    protected $description = 'Generate the TypeScript type and the PHP value objects from every block-types/*/v*/schema.json';

    public function handle(BlockTypeGenerator $generator, BlockTypeRegistry $registry): int
    {
        try {
            $registry->all();
            $files = $generator->render(BlockTypeGenerator::discover(base_path('block-types')));
        } catch (InvalidBlockTypePackage|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stale = [];

        foreach ($files as $relative => $content) {
            $path = base_path($relative);

            if ($this->option('check')) {
                if (! is_file($path) || file_get_contents($path) !== $content) {
                    $stale[] = $relative;
                }

                continue;
            }

            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
                $this->error("Cannot create the directory for {$relative}.");

                return self::FAILURE;
            }

            if (file_put_contents($path, $content) === false) {
                $this->error("Cannot write {$relative}.");

                return self::FAILURE;
            }

            $this->info("Wrote {$relative}");
        }

        foreach ($stale as $relative) {
            $this->error("{$relative} is stale: run php artisan dashflow:block-types.");
        }

        return $stale === [] ? self::SUCCESS : self::FAILURE;
    }
}
