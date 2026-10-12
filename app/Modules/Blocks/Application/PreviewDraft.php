<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPreview;
use App\Modules\Blocks\Contracts\DraftPreviews;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\UserSampleRef;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\Presentation;
use App\Modules\Mapping\Contracts\PreviewThrottle;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\MetricName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live preview (Story 3.10). Order of refusals: the Draft (404, or 409 once published), then nothing to run (no plan, no sample), then the
 * `pending_input` settings (422 naming the variable), then the per-membership limit (429), and only then the engine. A refusal never carries
 * anything of the sample. It runs with the Draft's own `config.query_plan` and its sealed sample, and writes nothing.
 *
 * The decoded sample is cached in this process (never in the shared cache, which would hold plaintext) per `(workspace, draft, sample_revision)`:
 * a cheap revision read decides whether the cached tree is still the sample, so a second preview of the same sample does not open or parse it
 * again in this worker. Only the {@see self::KEPT} most recently used trees are kept, and a replaced or ended sample drops its tree.
 * `dashflow.mapping.preview_duration` (milliseconds) is emitted for every run that reaches the engine, a guard abort included.
 */
final class PreviewDraft implements DraftPreviews
{
    /** How many decoded samples one process keeps: an implementation bound on memory, not a product setting. */
    private const KEPT = 8;

    /** @var array<string, mixed> "workspace:draft:revision" => decoded tree, least recently used first */
    private static array $decoded = [];

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Drafts $drafts,
        private readonly DraftSamples $samples,
        private readonly BlockTypeRegistry $types,
        private readonly QueryPlans $plans,
        private readonly PreviewThrottle $throttle,
        private readonly MetricEmitter $metrics,
        private readonly ManageUserSamples $userSamples,
    ) {}

    /** Forgets every decoded sample held by this process. */
    public static function forget(): void
    {
        self::$decoded = [];
        SampleShapes::forget();
    }

    public function preview(DraftActor $actor, string $draftId, ?UserSampleRef $asUser = null): DraftPreview
    {
        try {
            $draft = $this->drafts->find($actor->workspaceId, $draftId);
        } catch (DraftNotFound $e) {
            throw $this->isPublished($actor->workspaceId, $draftId) ? new DraftPublished : $e;
        }

        $plan = $draft->config['query_plan'] ?? null;

        if (! is_array($plan) || $plan === []) {
            throw new PreviewRefused(PreviewRefused::NO_PLAN);
        }

        $revision = $this->samples->revision($actor->workspaceId, $draft->id);

        if ($revision === null) {
            $this->drop($actor->workspaceId, $draft->id);

            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        // A preview as a user runs over the Admin's own ephemeral Fetch as user result: gone is a refusal that spends nothing.
        $source = is_array($draft->config['source'] ?? null) ? $draft->config['source'] : [];

        if ($asUser !== null && (strtolower((string) ($source['data_source_id'] ?? '')) !== strtolower($asUser->dataSourceId) || strtolower((string) ($source['endpoint_id'] ?? '')) !== strtolower($asUser->endpointId))) {
            throw new PreviewRefused(PreviewRefused::AS_USER_MISMATCH);
        }

        $user = $asUser === null ? null : $this->userSamples->open($actor, $asUser) ?? throw new PreviewRefused(PreviewRefused::AS_USER_ENDED);

        // Settings before the limit, so a refusal for an unset setting spends nothing.
        $guards = $this->plans->guards();
        $this->throttle->hit($actor->workspaceId, $actor->membershipId);

        $started = hrtime(true);
        $outcome = 'ok';

        try {
            $type = $this->types->resolve($draft->type, $this->contractVersion($actor->workspaceId, $draft->blockId));
            [$document, $revision] = $user === null
                ? $this->document($actor->workspaceId, $draft->id, $revision)
                : [$user['tree'], $revision];
            $result = $this->plans->run($type, $plan, $document, $guards, is_array($draft->config[Presentation::KEY] ?? null) ? $draft->config[Presentation::KEY] : []);
        } catch (GuardExceeded $e) {
            $outcome = 'guard_exceeded';

            throw $e;
        } catch (InvalidPlan $e) {
            $outcome = 'invalid_plan';

            throw $e;
        } catch (\Throwable $e) {
            $outcome = 'error';

            throw $e;
        } finally {
            $this->metrics->record(
                MetricName::make('mapping', 'preview_duration'),
                (hrtime(true) - $started) / 1_000_000,
                ['workspace_id' => $actor->workspaceId, 'outcome' => $outcome],
            );
        }

        return new DraftPreview($result->payload, $result->unavailable, $revision, $draft->revision, $this->slotValues($plan, $result->slots, $result->unavailable), ManageDraftSlots::planStale($draft->config, $revision), $user['expires_at'] ?? null);
    }

    /**
     * The value each mapped slot rendered (Story 3.13c): `{value, path, unavailable?}`. A number is its decimal string as received; text is bounded like a
     * field's example. It is sample-derived, so it only goes to the Admin who is previewing.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, string|bool|null>  $values
     * @param  array<string, string>  $unavailable
     * @return array<string, array<string, mixed>>
     */
    private function slotValues(array $plan, array $values, array $unavailable): array
    {
        $fields = is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [];
        $out = [];

        foreach (is_array($plan['shape'] ?? null) ? $plan['shape'] : [] as $slot => $alias) {
            $value = $values[$slot] ?? null;

            if (is_string($value) && mb_strlen($value) > SampleShape::EXAMPLE_CHARS) {
                $value = mb_substr($value, 0, SampleShape::EXAMPLE_CHARS).'…';
            }

            $out[(string) $slot] = ['value' => $value, 'path' => is_string($fields[$alias] ?? null) ? $fields[$alias] : null]
                + (isset($unavailable[$slot]) ? ['unavailable' => $unavailable[$slot]] : []);
        }

        return $out;
    }

    /**
     * The decoded sample of the live revision: this process's tree when it holds that revision, or the sealed sample opened and decoded once.
     * The revision it is of comes back with it (a replacement between the two reads is the newer one).
     *
     * @return array{0: mixed, 1: int}
     */
    private function document(string $workspaceId, string $draftId, int $revision): array
    {
        $key = self::key($workspaceId, $draftId, $revision);

        if (array_key_exists($key, self::$decoded)) {
            $tree = self::$decoded[$key];
            unset(self::$decoded[$key]);
            self::$decoded[$key] = $tree;

            return [$tree, $revision];
        }

        $this->drop($workspaceId, $draftId);
        $sample = $this->samples->find($workspaceId, $draftId);

        if ($sample === null || ! $sample->check->valid) {
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        try {
            $tree = LosslessJson::decode($sample->text, LosslessJson::configuredDepthLimit());
        } catch (JsonDepthExceeded|JsonParseFailed|InvalidLimitSetting) {
            // Valid when stored, and no longer under the platform limits: the same as no sample, and nothing of it is said.
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        self::$decoded[self::key($workspaceId, $draftId, $sample->sampleRevision)] = $tree;

        while (count(self::$decoded) > self::KEPT) {
            unset(self::$decoded[array_key_first(self::$decoded)]);
        }

        return [$tree, $sample->sampleRevision];
    }

    /** Drops every tree of the Draft: the sample is gone, or is about to be replaced by a newer revision. */
    private function drop(string $workspaceId, string $draftId): void
    {
        $prefix = self::key($workspaceId, $draftId, 0);
        $prefix = substr($prefix, 0, strrpos($prefix, ':') + 1);

        foreach (array_keys(self::$decoded) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$decoded[$key]);
            }
        }
    }

    private static function key(string $workspaceId, string $draftId, int $revision): string
    {
        return TenantKey::workspace($workspaceId).':'.strtolower($draftId).':'.$revision;
    }

    /** The contract version the Block pinned when it was started. */
    private function contractVersion(string $workspaceId, string $blockId): int
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $blockId): int {
            $row = DB::selectOne('select contract_version from blocks where workspace_id = ? and id = ?', [$workspaceId, $blockId]);

            return $row === null ? throw new DraftNotFound : (int) $row->contract_version;
        });
    }

    /** Why no Draft was found: the version exists and is not discarded, so it was published. */
    private function isPublished(string $workspaceId, string $id): bool
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $id): bool {
            $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

            return $row !== null && $row->state !== 'discarded';
        });
    }
}
