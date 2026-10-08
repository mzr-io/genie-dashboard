<?php

use App\Modules\Ingestion\Infrastructure\SyncSettings;
use Illuminate\Config\Repository;

// Story 2.16: the two timings the retention sweep reads. Whole seconds; unset or malformed is null, which leaves that rule inert.
function syncSettingsWith(array $sync): SyncSettings
{
    return new SyncSettings(new Repository(['dashflow' => ['tunables' => ['sync' => $sync]]]));
}

it('reads the superseded-payload grace and the cold-purge time as positive whole seconds', function () {
    $settings = syncSettingsWith(['superseded_payload_grace' => ['value' => '3600'], 'cold_purge_after' => ['value' => 86400]]);

    expect([$settings->supersededGraceSeconds(), $settings->coldPurgeAfterSeconds()])->toBe([3600, 86400]);
});

it('treats an unset or malformed value as null (the rule stays inert)', function (mixed $value) {
    $settings = syncSettingsWith(['superseded_payload_grace' => ['value' => $value], 'cold_purge_after' => ['value' => $value]]);

    expect($settings->supersededGraceSeconds())->toBeNull()->and($settings->coldPurgeAfterSeconds())->toBeNull();
})->with([null, '', ' ', '0', '-1', '1.5', '1h', 'soon', true, [[5]], '12345678901']);
