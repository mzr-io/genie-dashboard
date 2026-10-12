<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\InvalidSampleShape;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Platform\Tenancy\TenantKey;

/**
 * The structure of a Draft's sample for the mapping reads and writes (Story 3.13c). A cheap revision read decides whether the structure this process
 * holds is still the sample's: the decoded shape is kept per `(workspace, draft, sample_revision)` in this process only (never in a shared cache, which
 * would hold sample text), the {@see self::KEPT} most recently used, and a replaced or ended sample drops its entry.
 */
final class SampleShapes
{
    /** An implementation bound on memory, not a product setting. */
    private const KEPT = 8;

    /** @var array<string, array<string, mixed>> "workspace:draft:revision" => shape, least recently used first */
    private static array $shapes = [];

    public function __construct(private readonly DraftSamples $samples) {}

    /** Forgets every shape held by this process. */
    public static function forget(): void
    {
        self::$shapes = [];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}|null the sample's shape and revision; null when the Draft has no live sample
     *
     * @throws InvalidSampleShape the stored sample no longer passes the limits
     * @throws DraftNotFound
     * @throws SampleUnavailable
     */
    public function of(string $workspaceId, string $draftId): ?array
    {
        $revision = $this->samples->revision($workspaceId, $draftId);

        if ($revision === null) {
            $this->drop($workspaceId, $draftId);

            return null;
        }

        $key = self::key($workspaceId, $draftId, $revision);

        if (array_key_exists($key, self::$shapes)) {
            $shape = self::$shapes[$key];
            unset(self::$shapes[$key]);
            self::$shapes[$key] = $shape;

            return [$shape, $revision];
        }

        $this->drop($workspaceId, $draftId);
        $sample = $this->samples->find($workspaceId, $draftId);

        if ($sample === null) {
            return null;
        }

        if (! $sample->check->valid || $sample->check->shape === null) {
            throw new InvalidSampleShape;
        }

        self::$shapes[self::key($workspaceId, $draftId, $sample->sampleRevision)] = $sample->check->shape;

        while (count(self::$shapes) > self::KEPT) {
            unset(self::$shapes[array_key_first(self::$shapes)]);
        }

        return [$sample->check->shape, $sample->sampleRevision];
    }

    private function drop(string $workspaceId, string $draftId): void
    {
        $prefix = substr(self::key($workspaceId, $draftId, 0), 0, -1);

        foreach (array_keys(self::$shapes) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$shapes[$key]);
            }
        }
    }

    private static function key(string $workspaceId, string $draftId, int $revision): string
    {
        return TenantKey::workspace($workspaceId).':'.strtolower($draftId).':'.$revision;
    }
}
