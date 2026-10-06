<?php

use App\Support\Observability\ApiErrorRenderer;
use App\Support\Observability\JsonLogFormatter;
use App\Support\Observability\MetricName;
use App\Support\Observability\ObservabilityLogTap;
use App\Support\Observability\OtelBootstrap;
use App\Support\Observability\QueueContext;
use App\Support\Observability\RequestContext;
use App\Support\Observability\Scrubber;
use App\Support\Observability\ScrubbingSpanProcessor;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

const CANARY = 'CANARY-7f3a9c';

class ObservabilityProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('probe.job.ran');
    }
}

beforeEach(function () {
    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config([
        'logging.default' => 'stdout',
        'logging.channels.stdout.handler_with.stream' => $this->logFile,
    ]);
    Log::forgetChannel('stdout');
});

afterEach(function () {
    @unlink($this->logFile);
});

/** @return list<array<string, mixed>> */
function logLines(string $file): array
{
    $lines = array_filter(explode("\n", (string) file_get_contents($file)));

    return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values($lines));
}

it('sets a generated request id on the response and every log line', function () {
    $response = $this->get('/up');
    $id = $response->headers->get('X-Request-Id');

    expect($id)->toHaveLength(26);

    $lines = logLines($this->logFile);
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect($line['request_id'])->toBe($id);
    }
    expect($lines[0])->toHaveKeys(['timestamp', 'level', 'message']);
});

it('keeps a valid incoming request id', function () {
    $this->withHeader('X-Request-Id', 'client-id-12345')->get('/up')
        ->assertHeader('X-Request-Id', 'client-id-12345');

    expect(logLines($this->logFile)[0]['request_id'])->toBe('client-id-12345');
});

it('replaces a bad request id and never logs the bad value', function () {
    foreach (['has spaces here', str_repeat('a', 65), "ctl\x07chars-12345", 'short'] as $bad) {
        $response = $this->withHeader('X-Request-Id', $bad)->get('/up');
        expect($response->headers->get('X-Request-Id'))->toHaveLength(26);
    }

    $log = (string) file_get_contents($this->logFile);
    expect($log)->not->toContain('has spaces here')->not->toContain(str_repeat('a', 65))->not->toContain('short');
});

it('carries the originating request id into the job', function () {
    config(['queue.default' => 'database']);
    $context = app(RequestContext::class);

    $context->begin('origin-request-1');
    dispatch(new ObservabilityProbeJob);
    $context->clear();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    $job = collect(logLines($this->logFile))->firstWhere('message', 'probe.job.ran');
    expect($job['request_id'])->toBe('origin-request-1')
        ->and($context->requestId())->toBeNull();
});

it('gives a job without the field a fresh request id', function () {
    config(['queue.default' => 'database']);
    dispatch(new ObservabilityProbeJob);
    DB::table('jobs')->update(['payload' => DB::raw("json_remove(payload, '$.request_id')")]);

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    $job = collect(logLines($this->logFile))->firstWhere('message', 'probe.job.ran');
    expect($job['request_id'])->toHaveLength(26);
});

it('restores the request context after a sync job', function () {
    config(['queue.default' => 'sync']);
    $context = app(RequestContext::class);
    $context->begin('outer-request-1');

    dispatch(new ObservabilityProbeJob);

    expect($context->requestId())->toBe('outer-request-1');
});

it('keeps canary secrets out of logs, spans and metric labels', function () {
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new ScrubbingSpanProcessor(new SimpleSpanProcessor($exporter)));
    $scope = Configurator::create()->withTracerProvider($provider)->storeInContext()->activate();

    try {
        Log::info('seen '.url('/x').'?t='.CANARY, [
            'url' => 'https://h.test/p?k='.CANARY.'#'.CANARY,
            'headers' => ['Authorization' => 'Bearer '.CANARY, 'X-Custom' => CANARY],
        ]);

        $span = $provider->getTracer('test')->spanBuilder('GET /p?q='.CANARY)->startSpan();
        $span->setAttributes([
            'url.full' => 'https://h.test/p?k='.CANARY.'#'.CANARY,
            'url.query' => 'k='.CANARY,
            'http.request.header.authorization' => CANARY,
            'http.request.header.x-custom' => CANARY,
            'db.query.text' => 'select '.CANARY,
        ]);
        $span->recordException(new RuntimeException('failed https://h.test/z?s='.CANARY));
        $span->end();

        $this->withHeaders(['Authorization' => 'Bearer '.CANARY, 'X-Custom' => CANARY])
            ->get('/up?token='.CANARY);
        $provider->forceFlush();
    } finally {
        $scope->detach();
    }

    $spans = $exporter->getSpans();
    expect($spans)->not->toBeEmpty();
    $dump = json_encode(array_map(fn ($s) => [
        $s->getName(), $s->getAttributes()->toArray(),
        array_map(fn ($e) => [$e->getName(), $e->getAttributes()->toArray()], $s->getEvents()),
        $s->getStatus()->getDescription(), $s->getResource()->getAttributes()->toArray(),
    ], $spans));

    expect($dump)->not->toContain(CANARY)
        ->and((string) file_get_contents($this->logFile))->not->toContain(CANARY)
        ->and(json_encode(MetricName::labels(['route' => '/p?x='.CANARY])))->not->toContain(CANARY);
});

it('tags the request span with the request id', function () {
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new ScrubbingSpanProcessor(new SimpleSpanProcessor($exporter)));
    $scope = Configurator::create()->withTracerProvider($provider)->storeInContext()->activate();

    try {
        $tracer = $provider->getTracer('test');
        $root = $tracer->spanBuilder('request')->startSpan();
        $rootScope = $root->activate();
        $id = $this->get('/up')->headers->get('X-Request-Id');
        $rootScope->detach();
        $root->end();
    } finally {
        $scope->detach();
    }

    expect($exporter->getSpans()[0]->getAttributes()->get('dashflow.request_id'))->toBe($id);
});

it('logs one warning per process when no collector is configured', function () {
    OtelBootstrap::reset();
    foreach (['OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] as $name) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }

    OtelBootstrap::warnIfUnconfigured();
    OtelBootstrap::warnIfUnconfigured();
    $this->get('/up')->assertOk();

    $warnings = array_filter(logLines($this->logFile), fn ($l) => $l['level'] === 'WARNING');
    expect($warnings)->toHaveCount(1);
});

it('wraps api errors in the envelope without details', function () {
    $response = $this->getJson('/api/v1/ping');

    $response->assertUnauthorized()->assertExactJson(['error' => [
        'code' => 'platform.unauthenticated',
        'message' => 'Unauthorized',
        'request_id' => $response->headers->get('X-Request-Id'),
    ]]);
});

it('strips details outside the admin area and emits them inside it', function () {
    Route::get('api/v1/_probe/plain', fn () => throw new RuntimeException('secret detail'));
    Route::get('api/v1/_probe/admin', function (Request $request) {
        $request->attributes->set('area', 'admin');
        abort(403, 'visible to admins');
    });

    $plain = $this->getJson('/api/v1/_probe/plain');
    $plain->assertStatus(500)->assertJsonMissingPath('error.details');
    expect($plain->getContent())->not->toContain('secret detail');

    $this->getJson('/api/v1/_probe/admin')
        ->assertForbidden()
        ->assertJsonPath('error.details', 'visible to admins');
});

it('applies the scrubbing tap to every writing log channel', function () {
    foreach (config('logging.channels') as $name => $channel) {
        if (($channel['driver'] ?? null) === 'stack') {
            continue;
        }
        expect($channel['tap'] ?? [])->toContain(ObservabilityLogTap::class);
    }
});

it('scrubs the canary through single, daily and stderr channels', function () {
    $dir = sys_get_temp_dir().'/dashflow-chan-'.uniqid();
    mkdir($dir);
    $stderr = $dir.'/stderr.log';
    config([
        'logging.channels.single.path' => $dir.'/single.log',
        'logging.channels.daily.path' => $dir.'/daily.log',
        'logging.channels.stderr.handler_with.stream' => $stderr,
        'logging.channels.stderr.formatter' => JsonLogFormatter::class,
    ]);
    app(RequestContext::class)->begin('chan-request-1');

    foreach (['single', 'daily', 'stderr'] as $channel) {
        Log::forgetChannel($channel);
        Log::channel($channel)->info('seen https://h.test/p?t='.CANARY, ['url' => 'https://h.test/q?k='.CANARY]);
    }

    $files = [$dir.'/single.log', glob($dir.'/daily*.log')[0], $stderr];
    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        expect($content)->not->toContain(CANARY)->toContain('chan-request-1');
    }
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('logs the matched route template, not the raw path', function () {
    Route::get('probe-reset/{token}', fn () => 'ok');

    $this->get('/probe-reset/'.CANARY)->assertOk();

    $log = (string) file_get_contents($this->logFile);
    $line = collect(logLines($this->logFile))->firstWhere('message', 'http.request');
    expect($log)->not->toContain(CANARY)
        ->and($line['context']['url'] ?? $line['url'])->toBe('/probe-reset/{token}');
});

it('remembers the no-collector warning across requests in the same process', function () {
    OtelBootstrap::reset();
    foreach (['OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] as $name) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }

    OtelBootstrap::warnOnce('first');
    $marker = sys_get_temp_dir().'/dashflow-otel-warned-'.getmypid();
    expect(is_file($marker))->toBeTrue();

    // Simulate the next request: the static is gone, the marker stays.
    $static = new ReflectionProperty(OtelBootstrap::class, 'warned');
    $static->setValue(null, false);
    OtelBootstrap::warnOnce('second');

    $warnings = array_filter(logLines($this->logFile), fn ($l) => $l['level'] === 'WARNING');
    expect($warnings)->toHaveCount(1);

    OtelBootstrap::reset();
    expect(is_file($marker))->toBeFalse();
});

it('lets an HttpResponseException on api routes keep its own response', function () {
    Route::get('api/v1/_probe/response', fn () => throw new HttpResponseException(response()->json(['custom' => true], 202)));

    $this->getJson('/api/v1/_probe/response')->assertStatus(202)->assertExactJson(['custom' => true]);
    expect(ApiErrorRenderer::render(
        new HttpResponseException(response('x')),
        Request::create('/api/v1/x'),
    ))->toBeNull();
});

it('keeps the request id after a job exception and replaces it on the next job', function () {
    $context = new RequestContext;
    QueueContext::register($context, $events = new Dispatcher);
    $job = fn (string $id) => tap(Mockery::mock(JobContract::class), fn ($m) => $m->shouldReceive('payload')->andReturn(['request_id' => $id]));

    $first = $job('job-request-0001');
    $events->dispatch(new JobProcessing('sync', $first));
    $events->dispatch(new JobExceptionOccurred('sync', $first, new RuntimeException('boom')));
    expect($context->requestId())->toBe('job-request-0001');

    $events->dispatch(new JobProcessing('sync', $job('job-request-0002')));
    expect($context->requestId())->toBe('job-request-0002');
});

it('redacts sensitive dashflow span attribute keys', function () {
    $attributes = Scrubber::spanAttributes(['dashflow.api_token' => CANARY, 'dashflow.request_id' => 'abc']);

    expect($attributes)->toBe(['dashflow.request_id' => 'abc']);
});

it('scrubs spans from the production tracer provider wiring', function () {
    $exporter = new InMemoryExporter;
    $provider = OtelBootstrap::tracerProvider($exporter, false);

    $span = $provider->getTracer('test')->spanBuilder('GET /p?q='.CANARY)->startSpan();
    $span->setAttributes([
        'url.full' => 'https://h.test/p?k='.CANARY.'#'.CANARY,
        'http.request.header.authorization' => CANARY,
        'dashflow.api_token' => CANARY,
        'db.query.text' => 'select '.CANARY,
    ]);
    $span->recordException(new RuntimeException('failed https://h.test/z?s='.CANARY));
    $span->end();
    $provider->forceFlush();

    $spans = $exporter->getSpans();
    expect($spans)->not->toBeEmpty();
    $dump = json_encode(array_map(fn ($s) => [
        $s->getName(), $s->getAttributes()->toArray(),
        array_map(fn ($e) => [$e->getName(), $e->getAttributes()->toArray()], $s->getEvents()),
        $s->getStatus()->getDescription(),
    ], $spans));
    expect($dump)->not->toContain(CANARY);
});
