<?php

namespace App\Providers;

use App\Modules\Access\Application\ChangeMemberAccess;
use App\Modules\Access\Application\InviteMembers;
use App\Modules\Access\Contracts\MemberAccess;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberInvitations;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Infrastructure\AccessAuditSerializer;
use App\Modules\Access\Infrastructure\AdminMembershipGranter;
use App\Modules\Access\Infrastructure\EloquentMembershipPermissions;
use App\Modules\Access\Infrastructure\SecurityDefinerMembershipLookup;
use App\Modules\Access\Infrastructure\SignInMembershipsAdapter;
use App\Modules\Access\Infrastructure\SqlMemberDirectory;
use App\Modules\Identity\Application\IssueInvitation;
use App\Modules\Identity\Application\QueuedInvitationCourier;
use App\Modules\Identity\Contracts\InvitationCourier;
use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Modules\Identity\Infrastructure\IdentityAuditSerializer;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\AuditSerializers;
use App\Platform\Audit\PlatformAuditSerializer;
use App\Platform\Outbox\OutboxConsumers;
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
        $this->app->singleton(AuditHasher::class, fn ($app) => new AuditHasher((string) $app['config']->get('app.key')));
        $this->app->singleton(AuditSerializers::class);
        $this->app->singleton(OutboxConsumers::class);
        $this->app->bind(MembershipLookup::class, SecurityDefinerMembershipLookup::class);
        $this->app->bind(MembershipPermissions::class, EloquentMembershipPermissions::class);
        $this->app->bind(MemberDirectory::class, SqlMemberDirectory::class);
        // Identity declares these ports (it cannot call Access); the modules that implement them are bound here.
        $this->app->bind(InvitedMembershipGranter::class, AdminMembershipGranter::class);
        $this->app->bind(InvitationIssuer::class, IssueInvitation::class);
        // One courier per request: it queues the invitation emails that go out after the transaction commits.
        $this->app->singleton(QueuedInvitationCourier::class);
        $this->app->bind(InvitationCourier::class, QueuedInvitationCourier::class);
        $this->app->bind(MemberInvitations::class, InviteMembers::class);
        $this->app->bind(MemberAccess::class, ChangeMemberAccess::class);
        $this->app->bind(SignInMemberships::class, SignInMembershipsAdapter::class);
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

        // Each module registers its audit allowlist with the kernel (the kernel calls no module).
        $serializers = $this->app->make(AuditSerializers::class);
        $serializers->register(new AccessAuditSerializer);
        $serializers->register(new IdentityAuditSerializer);
        $serializers->register(new PlatformAuditSerializer);

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
