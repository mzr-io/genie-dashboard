<?php

namespace App\Providers;

use App\Modules\Access\Application\AttributesOnMembershipRemoved;
use App\Modules\Access\Application\ChangeMemberAccess;
use App\Modules\Access\Application\ChangeMemberStatus;
use App\Modules\Access\Application\InviteMembers;
use App\Modules\Access\Application\ManageAttributeKeys;
use App\Modules\Access\Application\ManageGroups;
use App\Modules\Access\Application\ManageMemberAttributes;
use App\Modules\Access\Application\RegisterAccessSubjectRow;
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
use App\Modules\Access\Contracts\RegisterAccessSubject;
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
use App\Modules\Blocks\Application\FetchDraftSample;
use App\Modules\Blocks\Application\ManageBlockCategories;
use App\Modules\Blocks\Application\ManageDrafts;
use App\Modules\Blocks\Application\ManageDraftSamples;
use App\Modules\Blocks\Application\ManageDraftSlots;
use App\Modules\Blocks\Application\ManagePublishedBlocks;
use App\Modules\Blocks\Application\ManageUserSamples;
use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Blocks\Application\PublishDraft;
use App\Modules\Blocks\Application\ReadDraftFieldRoles;
use App\Modules\Blocks\Application\ReadPublishedConfigs;
use App\Modules\Blocks\Application\RunDraftValidation;
use App\Modules\Blocks\Application\StartDraftSampleFetch;
use App\Modules\Blocks\Application\StartDraftValidation;
use App\Modules\Blocks\Contracts\BlockCategories;
use App\Modules\Blocks\Contracts\BlockPublishing;
use App\Modules\Blocks\Contracts\DraftFieldRoles;
use App\Modules\Blocks\Contracts\DraftPreviews;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSampleFetches;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\DraftUserSamples;
use App\Modules\Blocks\Contracts\DraftValidations;
use App\Modules\Blocks\Contracts\PublishedBlocks;
use App\Modules\Blocks\Contracts\PublishedConfigs;
use App\Modules\Blocks\Contracts\SampleVault;
use App\Modules\Blocks\Infrastructure\BlocksAuditSerializer;
use App\Modules\Blocks\Infrastructure\DraftLockEpochs;
use App\Modules\Blocks\Infrastructure\SodiumSampleVault;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Connector\Application\FetchEndpoint;
use App\Modules\Connector\Application\GuardEgressUrl;
use App\Modules\Connector\Application\ManageDataSources;
use App\Modules\Connector\Application\ManageEgressGrants;
use App\Modules\Connector\Application\ManageEndpoints;
use App\Modules\Connector\Application\ManageHostAllowlist;
use App\Modules\Connector\Application\ProbeDataSource;
use App\Modules\Connector\Application\QuerySyncRunHistory;
use App\Modules\Connector\Application\ReadSample;
use App\Modules\Connector\Application\RecordEgressBlock;
use App\Modules\Connector\Application\RecordSyncRun;
use App\Modules\Connector\Application\RunConnectionTest;
use App\Modules\Connector\Application\RunDraftSampleFetch;
use App\Modules\Connector\Application\RunEndpointValidation;
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
use App\Modules\Connector\Contracts\EndpointValidations;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\HostAllowlistDependents;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\Jitter;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleRuns;
use App\Modules\Connector\Contracts\Samples;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Contracts\SourceProbe;
use App\Modules\Connector\Contracts\SyncRunHistory;
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
use App\Modules\Datasets\Application\ManageDatasets;
use App\Modules\Datasets\Contracts\DatasetSuggestions;
use App\Modules\Identity\Application\IssueInvitation;
use App\Modules\Identity\Application\QueuedInvitationCourier;
use App\Modules\Identity\Contracts\InvitationCourier;
use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\SessionRevocation;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Modules\Identity\Infrastructure\DatabaseSessionRevocation;
use App\Modules\Identity\Infrastructure\IdentityAuditSerializer;
use App\Modules\Ingestion\Application\ReadComputeSources;
use App\Modules\Ingestion\Application\ReadSourceHealths;
use App\Modules\Ingestion\Application\ReadSyncGenerations;
use App\Modules\Ingestion\Application\ReadSyncStatuses;
use App\Modules\Ingestion\Application\RegisterSourceHealth;
use App\Modules\Ingestion\Application\RegisterSubscription;
use App\Modules\Ingestion\Application\RegisterSyncTargets;
use App\Modules\Ingestion\Application\ResolveFetchKey;
use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Ingestion\Contracts\ContextDigest;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\RefreshIntervals;
use App\Modules\Ingestion\Contracts\SourceHealths;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SyncGenerations;
use App\Modules\Ingestion\Contracts\SyncStatuses;
use App\Modules\Ingestion\Infrastructure\IngestionAuditSerializer;
use App\Modules\Ingestion\Infrastructure\KeyFileContextDigest;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Modules\Mapping\Application\AutoMapper;
use App\Modules\Mapping\Application\FieldRoleInference;
use App\Modules\Mapping\Application\PreviewRateLimit;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\FieldRoles;
use App\Modules\Mapping\Contracts\PreviewThrottle;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\RawStore\Contracts\RawStore;
use App\Modules\RawStore\Contracts\RawTierSweep;
use App\Modules\RawStore\Infrastructure\PostgresRawStore;
use App\Modules\RawStore\Infrastructure\PostgresRawTierSweep;
use App\Modules\Results\Application\ComputeOnPayloadChanged;
use App\Modules\Results\Contracts\ResultStore;
use App\Modules\Results\Infrastructure\LaravelValkeyCommands;
use App\Modules\Results\Infrastructure\ValkeyCommands;
use App\Modules\Results\Infrastructure\ValkeyResultStore;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\AuditSerializers;
use App\Platform\Audit\PlatformAuditSerializer;
use App\Platform\EditLock\EditLockResources;
use App\Platform\Json\LimitSetting;
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
        // One registry per process, reading `block-types/` once.
        $this->app->singleton(BlockTypeRegistry::class, fn ($app) => new LoadBlockTypes($app->basePath('block-types')));
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
        $this->app->bind(Drafts::class, ManageDrafts::class);
        $this->app->bind(DraftSamples::class, ManageDraftSamples::class);
        $this->app->singleton(DraftPreviews::class, PreviewDraft::class);
        $this->app->bind(DraftUserSamples::class, ManageUserSamples::class);
        $this->app->bind(BlockTypeShapers::class, PackageShapers::class);
        $this->app->bind(QueryExecutor::class, InProcessExecutor::class);
        $this->app->bind(QueryPlans::class, QueryPlanEngine::class);
        $this->app->bind(FieldRoles::class, FieldRoleInference::class);
        $this->app->bind(DraftFieldRoles::class, ReadDraftFieldRoles::class);
        $this->app->bind(AutoMapping::class, AutoMapper::class);
        $this->app->bind(DraftSlots::class, ManageDraftSlots::class);
        $this->app->bind(DatasetSuggestions::class, ManageDatasets::class);
        $this->app->bind(PreviewThrottle::class, PreviewRateLimit::class);
        $this->app->bind(DraftSampleFetches::class, StartDraftSampleFetch::class);
        $this->app->bind(DraftValidations::class, StartDraftValidation::class);
        // Publish v1.0 (Story 3.19): the freeze and activation, the Published list, and Access's subject registry the activation writes through.
        $this->app->bind(BlockPublishing::class, PublishDraft::class);
        $this->app->bind(PublishedBlocks::class, ManagePublishedBlocks::class);
        $this->app->bind(RegisterAccessSubject::class, RegisterAccessSubjectRow::class);
        $this->app->bind(SampleVault::class, SodiumSampleVault::class);
        $this->app->bind(BlockCategories::class, ManageBlockCategories::class);
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
        // Blocks' Fetch from API (Story 3.9) runs the Endpoint fetch through this contract only.
        $this->app->bind(SampleRuns::class, RunDraftSampleFetch::class);
        // Blocks' validation against the real API (Story 3.18) fetches through this contract only.
        $this->app->bind(EndpointValidations::class, RunEndpointValidation::class);
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
        $this->app->bind(SyncGenerations::class, ReadSyncGenerations::class);
        $this->app->bind(Subscribe::class, RegisterSubscription::class);
        $this->app->bind(ComputeSources::class, ReadComputeSources::class);
        // Blocks and Results (Story 4.2): a version's frozen configuration for compute, and the Valkey `cache` store of Block Results.
        $this->app->bind(PublishedConfigs::class, ReadPublishedConfigs::class);
        $this->app->bind(ValkeyCommands::class, fn ($app) => new LaravelValkeyCommands($app['redis']->connection('cache')));
        $this->app->bind(ResultStore::class, fn ($app) => new ValkeyResultStore(
            $app->make(ValkeyCommands::class),
            fn (): ?int => LimitSetting::positive('results.ttl', $app['config']->get('dashflow.results.ttl.value')),
        ));
        $this->app->bind(SourceHealths::class, ReadSourceHealths::class);
        $this->app->bind(RefreshIntervals::class, SyncSettings::class);
        $this->app->bind(SourceProbe::class, ProbeDataSource::class);
        $this->app->bind(SyncRunHistory::class, QuerySyncRunHistory::class);
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

        // Validates every Block Type package at boot: one invalid package fails here, readably, and registers none.
        // The generator command skips it, so it can still regenerate (and report) around a broken package; it validates lazily itself.
        if (! in_array('dashflow:block-types', $_SERVER['argv'] ?? [], true)) {
            $this->app->make(BlockTypeRegistry::class)->all();
        }

        // The Data source soft lock (Story 2.8) has its own bucket, per person: several tabs polling and heartbeating once a
        // second must not exhaust it, and exhausting it must not throttle anything else.
        RateLimiter::for('data-source-lock', fn (Request $request): Limit => Limit::perMinute(600)->by('data-source-lock|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('draft-lock', fn (Request $request): Limit => Limit::perMinute(600)->by('draft-lock|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Each module registers its audit allowlist with the kernel (the kernel calls no module).
        $serializers = $this->app->make(AuditSerializers::class);
        $serializers->register(new AccessAuditSerializer);
        $serializers->register(new BlocksAuditSerializer);
        $serializers->register(new ConnectorAuditSerializer);
        $serializers->register(new IdentityAuditSerializer);
        $serializers->register(new IngestionAuditSerializer);
        $serializers->register(new PlatformAuditSerializer);

        // Each module registers its outbox consumers with the kernel (the kernel calls no module).
        $this->app->make(OutboxConsumers::class)->register(new AttributesOnMembershipRemoved);
        $this->app->make(OutboxConsumers::class)->register(new RegisterSyncTargets);
        $this->app->make(OutboxConsumers::class)->register(new RegisterSourceHealth);
        $this->app->make(OutboxConsumers::class)->register(new ComputeOnPayloadChanged);

        // Each module registers the lockable resources it owns with the kernel's edit lock (the kernel calls no module).
        $this->app->make(EditLockResources::class)->register(DataSourceLockEpochs::TYPE, new DataSourceLockEpochs);
        // A Draft block's epoch (Story 3.3); its lock endpoints are `DraftLockController` (Story 3.6).
        $this->app->make(EditLockResources::class)->register(DraftLockEpochs::TYPE, new DraftLockEpochs);

        // Each module registers its Operation kinds with the kernel (the kernel calls no module). A connection test runs on
        // `fetch-interactive`, which only `worker-connector` consumes.
        $this->app->make(OperationKinds::class)->register(new OperationKind(ConnectionTests::KIND, ConnectionTests::QUEUE, 600, RunConnectionTest::class));
        // The `sample_fetch` kind (Story 2.10): registered by the Connector until an Ingestion module exists. Its lifetime is also the TTL of the sealed Sample Response.
        $this->app->make(OperationKinds::class)->register(new OperationKind(SampleFetches::KIND, SampleFetches::QUEUE, 600, RunSampleFetch::class));
        // The `fetch_as_user` kind (Story 2.13): the same queue and lifetime as `sample_fetch`, and the same sealed blob (its TTL is this lifetime).
        $this->app->make(OperationKinds::class)->register(new OperationKind(FetchesAsUser::KIND, FetchesAsUser::QUEUE, 600, RunFetchAsUser::class));

        // The `draft_sample_fetch` kind (Story 3.9): registered by Blocks, on the queue only `worker-connector` consumes. Its lifetime is set per Operation from the platform timeout ceiling ({@see Operations::enqueue()}), and the sealed blob's TTL is that Operation's remaining lifetime; 600 is only the default when none is given.
        $this->app->make(OperationKinds::class)->register(new OperationKind(DraftSampleFetches::KIND, DraftSampleFetches::QUEUE, 600, FetchDraftSample::class));
        // The `publish_validation` kind (Story 3.18): registered by Blocks on the same queue; its lifetime is also the platform timeout ceiling, set per Operation.
        $this->app->make(OperationKinds::class)->register(new OperationKind(DraftValidations::KIND, DraftValidations::QUEUE, 600, RunDraftValidation::class));

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
