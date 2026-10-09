<?php

namespace App\Providers;

use App\Modules\Access\Application\AttributesOnMembershipRemoved;
use App\Modules\Access\Application\ChangeMemberAccess;
use App\Modules\Access\Application\ChangeMemberStatus;
use App\Modules\Access\Application\InviteMembers;
use App\Modules\Access\Application\ManageAttributeKeys;
use App\Modules\Access\Application\ManageGroups;
use App\Modules\Access\Application\ManageMemberAttributes;
use App\Modules\Access\Application\ResolveUserContext;
use App\Modules\Access\Contracts\AttributeKeys;
use App\Modules\Access\Contracts\AttributeVault;
use App\Modules\Access\Contracts\GroupDirectory;
use App\Modules\Access\Contracts\GroupManager;
use App\Modules\Access\Contracts\MemberAccess;
use App\Modules\Access\Contracts\MemberActivation;
use App\Modules\Access\Contracts\MemberAttributes;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberInvitations;
use App\Modules\Access\Contracts\MemberNames;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\UserContext;
use App\Modules\Access\Infrastructure\AccessAuditSerializer;
use App\Modules\Access\Infrastructure\AdminMembershipGranter;
use App\Modules\Access\Infrastructure\EloquentMembershipPermissions;
use App\Modules\Access\Infrastructure\SecurityDefinerMembershipLookup;
use App\Modules\Access\Infrastructure\SignInMembershipsAdapter;
use App\Modules\Access\Infrastructure\SodiumAttributeVault;
use App\Modules\Access\Infrastructure\SqlGroupDirectory;
use App\Modules\Access\Infrastructure\SqlMemberDirectory;
use App\Modules\Access\Infrastructure\SqlMemberNames;
use App\Modules\Connector\Application\FetchEndpoint;
use App\Modules\Connector\Application\GuardEgressUrl;
use App\Modules\Connector\Application\ManageDataSources;
use App\Modules\Connector\Application\ManageEgressGrants;
use App\Modules\Connector\Application\ManageEndpoints;
use App\Modules\Connector\Application\ManageHostAllowlist;
use App\Modules\Connector\Application\ReadSample;
use App\Modules\Connector\Application\RecordEgressBlock;
use App\Modules\Connector\Application\RecordSyncRun;
use App\Modules\Connector\Application\RunConnectionTest;
use App\Modules\Connector\Application\RunFetchAsUser;
use App\Modules\Connector\Application\RunSampleFetch;
use App\Modules\Connector\Application\StartConnectionTest;
use App\Modules\Connector\Application\StartFetchAsUser;
use App\Modules\Connector\Application\StartSampleFetch;
use App\Modules\Connector\Contracts\ConnectionTests;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressGrants;
use App\Modules\Connector\Contracts\EgressGuard;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\HostAllowlistDependents;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\Jitter;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\Samples;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Contracts\SyncRunLog;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Modules\Connector\Infrastructure\ConnectorAuditSerializer;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlEgressTransport;
use App\Modules\Connector\Infrastructure\DataSourceLockEpochs;
use App\Modules\Connector\Infrastructure\DirectFetchTransport;
use App\Modules\Connector\Infrastructure\DnsHostResolver;
use App\Modules\Connector\Infrastructure\LocalSecretVault;
use App\Modules\Connector\Infrastructure\NativeCurlClient;
use App\Modules\Connector\Infrastructure\OAuthTokenCache;
use App\Modules\Connector\Infrastructure\RandomJitter;
use App\Modules\Connector\Infrastructure\SqlHostAllowlistDependents;
use App\Modules\Connector\Infrastructure\SyncRunTokenLog;
use App\Modules\Connector\Infrastructure\ValkeySourceGovernor;
use App\Modules\Identity\Application\IssueInvitation;
use App\Modules\Identity\Application\QueuedInvitationCourier;
use App\Modules\Identity\Contracts\InvitationCourier;
use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\SessionRevocation;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Modules\Identity\Infrastructure\DatabaseSessionRevocation;
use App\Modules\Identity\Infrastructure\IdentityAuditSerializer;
use App\Modules\Ingestion\Application\ReadSyncStatuses;
use App\Modules\Ingestion\Application\RegisterSyncTargets;
use App\Modules\Ingestion\Application\ResolveFetchKey;
use App\Modules\Ingestion\Contracts\ContextDigest;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\SyncStatuses;
use App\Modules\Ingestion\Infrastructure\KeyFileContextDigest;
use App\Modules\RawStore\Contracts\RawStore;
use App\Modules\RawStore\Contracts\RawTierSweep;
use App\Modules\RawStore\Infrastructure\PostgresRawStore;
use App\Modules\RawStore\Infrastructure\PostgresRawTierSweep;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\AuditSerializers;
use App\Platform\Audit\PlatformAuditSerializer;
use App\Platform\EditLock\EditLockResources;
use App\Platform\Operations\OperationKind;
use App\Platform\Operations\OperationKinds;
use App\Platform\Outbox\OutboxConsumers;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\WorkspaceContext;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\OtelBootstrap;
use App\Support\Observability\OtelMetricEmitter;
use App\Support\Observability\QueueContext;
use App\Support\Observability\RequestContext;
use App\Support\Queue\JobSignatureGuard;
use App\Support\Queue\JobSigner;
use App\Support\Queue\SignedRedisConnector;
use App\Support\Queue\TtlEnforcingStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(EditLockResources::class);
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
        $this->app->bind(MemberActivation::class, ChangeMemberStatus::class);
        $this->app->bind(SessionRevocation::class, DatabaseSessionRevocation::class);
        $this->app->bind(GroupDirectory::class, SqlGroupDirectory::class);
        $this->app->bind(GroupManager::class, ManageGroups::class);
        $this->app->bind(MemberNames::class, SqlMemberNames::class);
        $this->app->bind(AttributeKeys::class, ManageAttributeKeys::class);
        $this->app->bind(MemberAttributes::class, ManageMemberAttributes::class);
        $this->app->bind(AttributeVault::class, SodiumAttributeVault::class);
        $this->app->bind(UserContext::class, ResolveUserContext::class);
        $this->app->bind(HostAllowlist::class, ManageHostAllowlist::class);
        $this->app->bind(HostAllowlistDependents::class, SqlHostAllowlistDependents::class);
        $this->app->bind(DataSources::class, ManageDataSources::class);
        $this->app->bind(Endpoints::class, ManageEndpoints::class);
        $this->app->bind(SecretVault::class, LocalSecretVault::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(EgressGrants::class, ManageEgressGrants::class);
        $this->app->bind(EgressBlockLog::class, RecordEgressBlock::class);
        $this->app->bind(EgressGuard::class, GuardEgressUrl::class);
        $this->app->bind(CurlClient::class, NativeCurlClient::class);
        $this->app->bind(EgressTransport::class, CurlEgressTransport::class);
        // The `direct` driver: the worker calls the source itself, through the guard (the `agent` driver comes later).
        $this->app->bind(FetchTransport::class, DirectFetchTransport::class);
        $this->app->bind(TokenRequestLog::class, SyncRunTokenLog::class);
        // One per process, so a missing token key is warned about once.
        $this->app->singleton(OAuthTokenCache::class);
        $this->app->bind(ConnectionTests::class, StartConnectionTest::class);
        $this->app->bind(SampleFetches::class, StartSampleFetch::class);
        $this->app->bind(Samples::class, ReadSample::class);
        $this->app->bind(FetchesAsUser::class, StartFetchAsUser::class);
        // Ingestion's scheduled fetch (Story 2.14) reaches the Connector through these two contracts only.
        $this->app->bind(EndpointFetcher::class, FetchEndpoint::class);
        $this->app->bind(SyncRunLog::class, RecordSyncRun::class);
        $this->app->bind(SourceGovernor::class, ValkeySourceGovernor::class);
        $this->app->bind(Jitter::class, RandomJitter::class);
        // Ingestion and RawStore (Story 2.14): the fetch key, its context digest key, the raw tier and the Endpoint status read model.
        $this->app->bind(ContextDigest::class, KeyFileContextDigest::class);
        $this->app->bind(FetchKeyResolver::class, ResolveFetchKey::class);
        $this->app->bind(RawStore::class, PostgresRawStore::class);
        $this->app->bind(RawTierSweep::class, PostgresRawTierSweep::class);
        $this->app->bind(SyncStatuses::class, ReadSyncStatuses::class);
        $this->app->singleton(OperationKinds::class);
        $this->app->singleton(MetricEmitter::class, OtelMetricEmitter::class);
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

        // The Data source soft lock (Story 2.8) has its own bucket, per person: several tabs polling and heartbeating once a
        // second must not exhaust it, and exhausting it must not throttle anything else.
        RateLimiter::for('data-source-lock', fn (Request $request): Limit => Limit::perMinute(600)->by('data-source-lock|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Each module registers its audit allowlist with the kernel (the kernel calls no module).
        $serializers = $this->app->make(AuditSerializers::class);
        $serializers->register(new AccessAuditSerializer);
        $serializers->register(new ConnectorAuditSerializer);
        $serializers->register(new IdentityAuditSerializer);
        $serializers->register(new PlatformAuditSerializer);

        // Each module registers its outbox consumers with the kernel (the kernel calls no module).
        $this->app->make(OutboxConsumers::class)->register(new AttributesOnMembershipRemoved);
        $this->app->make(OutboxConsumers::class)->register(new RegisterSyncTargets);

        // Each module registers the lockable resources it owns with the kernel's edit lock (the kernel calls no module).
        $this->app->make(EditLockResources::class)->register(DataSourceLockEpochs::TYPE, new DataSourceLockEpochs);

        // Each module registers its Operation kinds with the kernel (the kernel calls no module). A connection test runs on
        // `fetch-interactive`, which only `worker-connector` consumes.
        $this->app->make(OperationKinds::class)->register(new OperationKind(ConnectionTests::KIND, ConnectionTests::QUEUE, 600, RunConnectionTest::class));
        // The `sample_fetch` kind (Story 2.10): registered by the Connector until an Ingestion module exists. Its lifetime is also the TTL of the sealed Sample Response.
        $this->app->make(OperationKinds::class)->register(new OperationKind(SampleFetches::KIND, SampleFetches::QUEUE, 600, RunSampleFetch::class));
        // The `fetch_as_user` kind (Story 2.13): the same queue and lifetime as `sample_fetch`, and the same sealed blob (its TTL is this lifetime).
        $this->app->make(OperationKinds::class)->register(new OperationKind(FetchesAsUser::KIND, FetchesAsUser::QUEUE, 600, RunFetchAsUser::class));

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
