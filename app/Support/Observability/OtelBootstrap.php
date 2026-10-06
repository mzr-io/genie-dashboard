<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

/**
 * OTLP export is driven only by the standard OTEL_* environment variables.
 * Without OTEL_EXPORTER_OTLP_ENDPOINT nothing is exported and one warning is logged per process.
 */
final class OtelBootstrap
{
    private static bool $registered = false;

    private static ?ScopeInterface $scope = null;

    private static bool $warned = false;

    public static function endpoint(): ?string
    {
        foreach (['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'OTEL_EXPORTER_OTLP_ENDPOINT'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Tracer provider whose every span passes through the scrubber before reaching the exporter. */
    public static function tracerProvider(SpanExporterInterface $exporter, bool $batch = true): TracerProviderInterface
    {
        $processor = $batch
            ? new BatchSpanProcessor($exporter, Clock::getDefault())
            : new SimpleSpanProcessor($exporter);

        return new TracerProvider(new ScrubbingSpanProcessor($processor));
    }

    /** Idempotent. Safe to call before and after the environment file is loaded. Never throws. */
    public static function boot(): void
    {
        if (self::$registered) {
            return;
        }

        if (self::endpoint() === null) {
            return;
        }

        try {
            $provider = self::tracerProvider((new SpanExporterFactory)->create());
            self::$scope = Configurator::create()
                ->withTracerProvider($provider)
                ->withPropagator((new PropagatorFactory)->create())
                ->storeInContext()
                ->activate();
            ShutdownHandler::register($provider->shutdown(...));
            self::$registered = true;
        } catch (Throwable $e) {
            self::warnOnce('OpenTelemetry export could not start: '.$e::class);
        }
    }

    /** Logs the single "no collector" warning for this process. */
    public static function warnIfUnconfigured(): void
    {
        if (self::$registered) {
            return;
        }
        if (self::endpoint() === null) {
            self::warnOnce('OTEL_EXPORTER_OTLP_ENDPOINT is not set; traces are not exported.');
        } else {
            self::boot();
        }
    }

    public static function warnOnce(string $message): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        try {
            Log::warning($message);
        } catch (Throwable) {
            // Observability must never break the app.
        }
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$scope?->detach();
        self::$scope = null;
        self::$registered = false;
        self::$warned = false;
    }
}
