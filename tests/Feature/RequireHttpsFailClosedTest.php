<?php

use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Story 2.3: a failed read of `workspace_settings.require_https` fails closed (refuse plain http) and is logged.
it('returns true and logs workspace_settings.read_failed when the read throws', function () {
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('down'));
    Log::shouldReceive('warning')->once()->with('workspace_settings.read_failed', ['exception' => RuntimeException::class]);

    expect((new WorkspaceSettings)->requireHttps())->toBeTrue();
});
