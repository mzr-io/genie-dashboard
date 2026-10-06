<?php

namespace App\Providers;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Infrastructure\SecurityDefinerMembershipLookup;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\WorkspaceContext;
use App\Support\Observability\OtelBootstrap;
use App\Support\Observability\QueueContext;
use App\Support\Observability\RequestContext;
use App\Support\Queue\JobSignatureGuard;
use App\Support\Queue\JobSigner;
use App\Support\Queue\SignedRedisConnector;
use App\Support\Queue\TtlEnforcingStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RequestContext::class);
        $this->app->singleton(WorkspaceContext::class);
        $this->app->bind(MembershipLookup::class, SecurityDefinerMembershipLookup::class);
        $this->app->bind(TenantCache::class, fn ($app) => new TenantCache($app['cache']->store()));
        $this->app->singleton(JobSigner::class, fn ($app) => new JobSigner((string) $app['config']->get('app.key')));

        // Registered after Horizon's own, so this connector wins: every Redis queue signs its payloads.
        $this->callAfterResolving(QueueManager::class, function (QueueManager $manager): void {
            $manager->addConnector('redis', fn () => new SignedRedisConnector(
                $this->app->make('redis'),
                $this->app->make(JobSigner::class),
            ));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        QueueContext::register($this->app->make(RequestContext::class), $this->app->make('events'));
        JobSignatureGuard::register($this->app, $this->app->make('events'));
        $this->registerValkeyCacheDriver();
        OtelBootstrap::warnIfUnconfigured();
    }

    /**
     * The `valkey-cache` driver: a Redis store that refuses writes without a TTL.
     */
    protected function registerValkeyCacheDriver(): void
    {
        Cache::extend('valkey-cache', function ($app, array $config) {
            $store = new RedisStore(
                $app['redis'],
                (string) $app['config']->get('cache.prefix'),
                $config['connection'] ?? 'cache',
            );
            $store->setLockConnection($config['lock_connection'] ?? $config['connection'] ?? 'cache');

            /** @var CacheManager $manager */
            $manager = $app['cache'];

            return $manager->repository(new TtlEnforcingStore($store), $config);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
