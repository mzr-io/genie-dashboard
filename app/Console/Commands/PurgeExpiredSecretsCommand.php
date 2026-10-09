<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes the transient secrets that have expired (Story 2.5): the sealed values a connection test carries are deleted when
 * the Operation ends, and this command is the safety net for one whose worker died first. An expired row is already
 * ignored on read; this only frees the space. Safe to run at any time and as often as wanted.
 */
#[Signature('dashflow:secrets:purge-expired')]
class PurgeExpiredSecretsCommand extends Command
{
    protected $description = 'Delete expired transient secrets of connection tests';

    public function handle(): int
    {
        try {
            $row = DB::selectOne('select connector_purge_expired_secrets() as removed');
        } catch (Throwable $e) {
            $this->components->error('The expired secrets could not be purged: '.$e::class.'.');

            return self::FAILURE;
        }

        $this->components->info('Removed '.(int) ($row->removed ?? 0).' expired transient secret(s).');

        return self::SUCCESS;
    }
}
