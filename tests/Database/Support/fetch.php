<?php

// Child process of the fetch race test (Story 2.14): runs one dispatch of a sync target, with a fake fetcher that answers after a pause,
// so several of them commit in an order the test does not control.
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Ingestion\Application\FetchJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $workspace, $target, $seq, $pause] = $argv;

$app->instance(EndpointFetcher::class, new class((int) $seq, (int) $pause) implements EndpointFetcher
{
    public function __construct(private readonly int $seq, private readonly int $pause) {}

    public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
    {
        usleep($this->pause * 1000);
        $body = '{"k":'.$this->seq.'}';

        return new EndpointFetchResult(true, $body, 200, 1, strlen($body), null, null, 'https://api.example.com/x', [], dataSourceId: $spec->dataSourceId);
    }
});

dispatch(new FetchJob($workspace, $target, (int) $seq));
