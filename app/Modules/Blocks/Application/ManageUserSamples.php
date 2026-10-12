<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftUserSamples;
use App\Modules\Blocks\Contracts\UserSampleRef;
use App\Modules\Blocks\Contracts\UserSampleView;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Modules\Connector\Contracts\Samples;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;
use App\Platform\Operations\Operation;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fetch as user for a Draft (Story 3.17, {@see DraftUserSamples}). The Connector's `fetch_as_user` Operation does the work; this class adds the
 * Draft (it must exist and not be published) and reads the finished response back, decoded only by the lossless decoder and only for the
 * requester ({@see Samples::read()}). The decoded tree is never cached and the response is never stored: each read opens the Operation's blob.
 */
final class ManageUserSamples implements DraftUserSamples
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Drafts $drafts,
        private readonly FetchesAsUser $fetches,
        private readonly Samples $samples,
    ) {}

    public function start(DraftActor $actor, string $draftId, string $dataSourceId, string $endpointId, string $membershipId, array $bindings, ?array $range, ?array $period): Operation
    {
        $this->ensureDraft($actor->workspaceId, $draftId);

        return $this->fetches->startFromChoices(new DataSourceActor($actor->membershipId, $actor->workspaceId), $dataSourceId, $endpointId, $membershipId, $bindings, $range, $period);
    }

    public function view(DraftActor $actor, string $draftId, UserSampleRef $ref): ?UserSampleView
    {
        $this->ensureDraft($actor->workspaceId, $draftId);
        $sample = $this->samples->read($actor->workspaceId, $actor->membershipId, $ref->dataSourceId, $ref->endpointId, $ref->operationId, $ref->mayPreviewAsUser, true);
        $document = $sample === null ? null : $this->decode($sample->body);

        if ($sample === null || $document === null) {
            return null;
        }

        $shape = SampleShape::of($document['tree']);

        // Structure only: a path and its types, never an example. The tables hold rows of values and are not sent at all.
        return new UserSampleView([
            'records' => $shape['records'],
            'scalars' => $shape['scalars'],
            'arrays' => $shape['arrays'],
            'objects' => $shape['objects'],
            'nodes' => array_map(fn (array $node): array => ['example' => null] + $node, $shape['nodes']),
        ], $sample->status, $sample->latencyMs, $sample->expiresAt);
    }

    /**
     * The decoded response of a Fetch as user for the preview: null when it is gone or no longer decodes under the platform limits.
     *
     * @return array{tree: mixed, expires_at: string}|null
     *
     * @throws SamplePreviewDenied
     */
    public function open(DraftActor $actor, UserSampleRef $ref): ?array
    {
        $sample = $this->samples->read($actor->workspaceId, $actor->membershipId, $ref->dataSourceId, $ref->endpointId, $ref->operationId, $ref->mayPreviewAsUser, true);

        if ($sample === null) {
            return null;
        }

        $decoded = $this->decode($sample->body);

        return $decoded === null ? null : ['tree' => $decoded['tree'], 'expires_at' => $sample->expiresAt];
    }

    /** @return array{tree: mixed}|null */
    private function decode(#[\SensitiveParameter] string $body): ?array
    {
        try {
            return ['tree' => LosslessJson::decode($body, LosslessJson::configuredDepthLimit())];
        } catch (JsonDepthExceeded|JsonParseFailed|InvalidLimitSetting) {
            return null;
        }
    }

    private function ensureDraft(string $workspaceId, string $draftId): void
    {
        try {
            $this->drafts->find($workspaceId, $draftId);
        } catch (DraftNotFound $e) {
            $published = $this->transactions->run($workspaceId, function () use ($workspaceId, $draftId): bool {
                $row = Str::isUuid($draftId) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($draftId)]) : null;

                return $row !== null && $row->state !== 'discarded';
            });

            throw $published ? new DraftPublished : $e;
        }
    }
}
