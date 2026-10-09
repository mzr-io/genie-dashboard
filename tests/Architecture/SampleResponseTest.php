<?php

// Story 2.10: a Sample Response is kept only as an encrypted, short-lived cache entry. The code that handles the body never
// touches PostgreSQL, and the one place a response body leaves the handler is the sealed store.

function sampleSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Connector/'.$path);
}

it('keeps the sample store, the reader and the sample itself away from the database and the logs', function (string $path) {
    $source = sampleSource($path);

    expect($source)->not->toMatch('/\bDB::|Illuminate\\\\Support\\\\Facades\\\\DB|Eloquent|Log::|LoggerInterface|->log\(/');
})->with(['Infrastructure/SampleBlobStore.php', 'Application/ReadSample.php', 'Contracts/Sample.php']);

it('lets the Sample handler pass the response body to the sealed store and nowhere else', function () {
    $source = sampleSource('Application/RunSampleFetch.php');

    // The body is read once, as the argument of the sealed store; everything else about the response is a number or a code.
    expect(substr_count($source, '$response->body'))->toBe(1)
        ->and($source)->toMatch('/blobs->put\([^;]*\$response->body/s')
        ->and($source)->not->toMatch('/\$response->(?:headers|json\(\))/')
        ->and($source)->not->toMatch('/Log::\w+\([^;]*(?:body|values|\$request)/s');
});

it('seals with the data key and fails closed, never caching a sample in the clear', function () {
    $source = sampleSource('Infrastructure/SampleBlobStore.php');

    expect($source)->toContain('sodium_crypto_secretbox(')
        ->and($source)->toContain('dataKeyPath()')
        ->and($source)->toContain("SampleBlobUnavailable('key_unavailable')")
        ->and($source)->not->toContain('tokenKeyPath');
});

// Story 2.13: the code that handles a member's resolved values never logs, audits or stores them, and the worker never needs the digest key.
it('keeps the user-context resolver and the Fetch as user handler away from the logs, the audit and the database', function (string $path) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/'.$path);

    expect($source)->not->toMatch('/Log::|LoggerInterface|->log\(|\baudit->|Outbox|AuditAction|\bDB::insert|\bDB::update/');
})->with(['Connector/Application/RunFetchAsUser.php', 'Access/Contracts/UserContextValues.php']);

it('resolves user context without the digest key', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Access/Application/ResolveUserContext.php');

    expect($source)->toContain('assertReadable()')->and($source)->not->toContain('assertAvailable')->and($source)->not->toContain('blindIndex');
});

// Story 2.14: a pasted, tested or fetched-as-user sample has no sync target and can never enter the raw tier. Only RawStore writes
// `raw_bodies` and `raw_observations`, and only Ingestion's scheduled run calls it.
function withoutComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
    }

    return $out;
}

it('keeps every sample path away from the raw tier', function () {
    $app = dirname(__DIR__, 2).'/app';
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $code = withoutComments((string) file_get_contents($path));
        $inRawStore = str_contains($path, '/Modules/RawStore/');
        $inIngestion = str_contains($path, '/Modules/Ingestion/');
        $inProvider = str_ends_with($path, 'Providers/AppServiceProvider.php');

        // The tables are named only inside RawStore (and by the command that prepares the monthly partitions).
        if (! $inRawStore && ! str_ends_with($path, 'Console/Commands/EnsurePartitionsCommand.php') && preg_match('/\braw_(?:bodies|observations)\b/', $code) === 1) {
            $violations[] = "{$path} names a raw table outside RawStore";
        }

        // The contract is called only by Ingestion (and bound in the provider).
        if (! $inRawStore && ! $inIngestion && ! $inProvider && str_contains($code, 'App\Modules\RawStore')) {
            $violations[] = "{$path} uses RawStore";
        }
    }

    expect($violations)->toBe([]);

    // The sample code of Connector says nothing of the raw tier at all.
    foreach (['Application/RunSampleFetch.php', 'Application/RunFetchAsUser.php', 'Application/StartSampleFetch.php', 'Application/ReadSample.php', 'Infrastructure/SampleBlobStore.php'] as $path) {
        expect(withoutComments(sampleSource($path)))->not->toMatch('/RawStore|raw_bodies|raw_observations|sync_targets/');
    }
});

it('hands the scheduled body to the raw tier and nowhere else, with logs of codes and counts only', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Ingestion/Application/FetchSyncTarget.php');

    // The body goes to the raw tier, and (Story 2.15) to the hash that tells whether it changed: nowhere else.
    expect(substr_count($source, '$result->body'))->toBe(2)
        ->and($source)->toMatch('/raw->put\([^;]*\$result->body/s')
        ->and($source)->toMatch('/CanonicalBodyHash::of\(\(string\) \$result->body\)/')
        ->and($source)->not->toMatch('/Log::\w+\([^;]*(?:body|values|params|\$result|\$target|url)/s')
        ->and($source)->not->toContain('json_decode');

    $fetcher = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Connector/Application/FetchEndpoint.php');
    expect(substr_count($fetcher, '$response->body'))->toBe(1)
        ->and($fetcher)->not->toMatch('/Log::\w+\([^;]*(?:body|values|\$request|url)/s')
        // Story 2.15: a header is read only as a validator, by name, in `validator()`. Story 2.17: and as the one `Retry-After` of a 429 or 503,
        // handed to `RetryAfter::seconds()`, which keeps a number and nothing else.
        ->and($fetcher)->not->toMatch('/\$response->(?:headers(?!\[\$name\]|, new \\\\DateTimeImmutable)|json\(\))/')
        ->and(substr_count($fetcher, '$response->headers'))->toBe(2);

    // RawStore keeps bytes: hex in, hex out, never decoded and never jsonb.
    $store = withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/RawStore/Infrastructure/PostgresRawStore.php'));
    expect($store)->not->toMatch('/jsonb|json_decode|json_encode|Log::/')->and($store)->toContain("decode(?, 'hex')");
});

it('runs the dispatcher on the system connection with a fixed batch and SKIP LOCKED, and the fetch job on the connector queue', function () {
    $dispatcher = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Ingestion/Application/DispatchDueSyncs.php');
    $job = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Ingestion/Application/FetchJob.php');
    $tick = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Ingestion/Application/DispatchDueSyncsJob.php');

    expect($dispatcher)->toContain("CONNECTION = 'system'")->toContain('for update skip locked')->toContain('const BATCH')->not->toContain('workspace_isolation')
        ->and($job)->toContain("QUEUE = 'fetch-scheduled'")->toContain('implements ShouldQueue, WorkspaceScopedJob')->toContain('RunsInWorkspace')
        ->and($job)->toContain("'sync_targets' => [\$this->syncGroupId]")
        ->and($tick)->toContain("onQueue('maintenance')");
});

// Story 2.16: the raw tier is deleted by RawStore's RawTierSweep and nowhere else, always on the `maintenance` connection.
it('lets only the RawTierSweep delete from the raw tier, run by the sweep on the maintenance connection', function () {
    $app = dirname(__DIR__, 2).'/app';
    $deleters = [];
    $maintenance = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($app) + 1);

        if (preg_match('/delete\s+from\s+raw_(?:bodies|observations)\b|table\(\s*[\'"]raw_(?:bodies|observations)[\'"]\s*\)\s*->\s*(?:where\w*\([^;]*)?delete\(/i', $source) === 1) {
            $deleters[] = $relative;
        }

        if (str_contains($source, "'maintenance'") || str_contains($source, 'CONNECTION = \'maintenance\'')) {
            $maintenance[] = $relative;
        }
    }

    expect($deleters)->toBe(['Modules/RawStore/Infrastructure/PostgresRawTierSweep.php'])
        ->and($maintenance)->toContain('Modules/Ingestion/Application/SweepRawHistory.php');

    $sweep = (string) file_get_contents($app.'/Modules/RawStore/Infrastructure/PostgresRawTierSweep.php');
    expect($sweep)->not->toMatch('/json_decode|\bupdate\s+raw_|insert\s+into/i');
});
