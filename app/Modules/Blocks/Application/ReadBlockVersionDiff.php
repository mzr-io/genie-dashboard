<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockVersionDiff;
use App\Modules\Blocks\Contracts\Drafts;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;

/** {@see BlockVersionDiff}: the Draft's config against the published version's content made a Draft config again. */
final class ReadBlockVersionDiff implements BlockVersionDiff
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Drafts $drafts,
    ) {}

    public function forDraft(string $workspaceId, string $draftId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $draftId): array {
            $draft = $this->drafts->find($workspaceId, $draftId);

            /** @var object{name: string, version: string, config: string}|null $current */
            $current = DB::selectOne(
                "select v.name, v.version, v.config::text as config from blocks b join block_versions v on v.id = b.current_version_id and v.state = 'published' where b.workspace_id = ? and b.id = ?",
                [$workspaceId, $draft->blockId],
            );

            if ($current === null) {
                return ['first_publish' => true, 'current_version' => null, 'draft_version' => $draft->version, 'sections' => array_fill_keys(VersionDiff::SECTIONS, []), 'summary' => []];
            }

            $before = FrozenConfig::draftConfig(DraftConfig::decode((string) $current->config));
            $sections = VersionDiff::between($before, $draft->config, (string) $current->name, $draft->name);

            return [
                'first_publish' => false,
                'current_version' => (string) $current->version,
                'draft_version' => $draft->version,
                'sections' => $sections,
                'summary' => VersionDiff::summary($sections),
            ];
        });
    }
}
