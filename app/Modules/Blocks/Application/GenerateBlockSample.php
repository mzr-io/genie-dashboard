<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockSamples;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use App\Platform\Json\SamplePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * {@see BlockSamples}. A document is built with one value at every path the frozen Query Plan extracts (three records where the path goes through
 * `[*]`), typed by the field snapshot (number, text, boolean, date), and the real Query Plan engine and Shaper run over it with the version's own
 * presentation, so the preview is drawn exactly as the Block will be. A value is a pure function of the version id, the field alias and the record
 * index: no clock, no randomness, no read of any source.
 */
final class GenerateBlockSample implements BlockSamples
{
    private const RECORDS = 3;

    public function __construct(private readonly BlockTypeRegistry $types, private readonly QueryPlans $plans) {}

    public function forBlock(string $workspaceId, string $blockId): ?array
    {
        if (! Str::isUuid($blockId)) {
            return null;
        }

        $row = DB::selectOne(
            'select b.type_key, b.contract_version, v.id as version_id, v.version, v.config::text as config from blocks b '
            ."join block_versions v on v.workspace_id = b.workspace_id and v.id = b.current_version_id and v.state = 'published' "
            .'where b.workspace_id = ? and b.id = ?',
            [$workspaceId, strtolower($blockId)],
        );

        if ($row === null) {
            return null;
        }

        $config = DraftConfig::decode((string) $row->config);
        $plan = is_array($config['query_plan'] ?? null) ? $config['query_plan'] : [];
        $fields = is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [];
        $snapshot = [];

        foreach (is_array($config['fields']['items'] ?? null) ? $config['fields']['items'] : [] as $item) {
            if (is_array($item) && is_string($item['alias'] ?? null)) {
                $snapshot[$item['alias']] = is_string($item['type'] ?? null) ? $item['type'] : 'text';
            }
        }

        try {
            $type = $this->types->resolve((string) $row->type_key, is_int($config['contract_version'] ?? null) ? $config['contract_version'] : (int) $row->contract_version);
            $tree = [];

            foreach ($fields as $alias => $path) {
                $this->put($tree, SamplePath::parse((string) $path), strtolower((string) $row->version_id), (string) $alias, $snapshot[(string) $alias] ?? 'text');
            }

            $result = $this->plans->run(
                $type,
                $plan,
                self::tree($tree),
                $this->plans->guards(),
                is_array($config['presentation'] ?? null) ? $config['presentation'] : [],
            );
        } catch (UnknownBlockType|InvalidPlan|GuardExceeded|SettingNotConfigured|InvalidArgumentException) {
            return null;
        }

        return ['type' => (string) $row->type_key, 'version' => (string) $row->version, 'render_payload' => $result->payload];
    }

    /**
     * @param  array<string|int, mixed>  $node
     * @param  list<string|null>  $segments
     */
    private function put(array &$node, array $segments, string $version, string $alias, string $type, int $index = 0): void
    {
        if ($segments === []) {
            return;
        }

        $segment = array_shift($segments);

        if ($segment === null) {
            // A `[*]` step: records, each built the same way with its own index.
            for ($i = 0; $i < self::RECORDS; $i++) {
                $node[$i] ??= [];
                $this->put($node[$i], $segments, $version, $alias, $type, $i);
            }

            return;
        }

        if ($segments === []) {
            $node[$segment] = self::value($version, $alias, $type, $index);

            return;
        }

        $node[$segment] ??= [];
        $this->put($node[$segment], $segments, $version, $alias, $type, $index);
    }

    /** The decoded-tree form: an associative array is an object, a list is a list, a number is its lexeme. */
    private static function tree(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map(self::tree(...), $node);
        }

        return new JsonObject(array_map(self::tree(...), $node));
    }

    private static function value(string $version, string $alias, string $type, int $index): mixed
    {
        $seed = (int) hexdec(substr(hash('sha256', $version.'|'.$alias.'|'.$index), 0, 8));

        return match ($type) {
            'number' => new DecimalLiteral(sprintf('%d.%02d', 100 + intdiv($seed, 100) % 9900, $seed % 100)),
            'boolean' => $seed % 2 === 0,
            'date' => sprintf('2026-%02d-%02d', 1 + $seed % 12, 1 + $seed % 28),
            default => 'Sample '.str_replace('_', ' ', $alias),
        };
    }
}
