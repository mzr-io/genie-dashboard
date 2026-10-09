<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Ingestion\Contracts\RefreshIntervals;
use Illuminate\Support\Str;

/**
 * The `source` key of a Draft's lenient `config` (Story 3.7): which Data Source and Endpoint the block reads, the values of the Endpoint's
 * Fixed parameters, the refresh interval, the default date range and whether a sample is out of date. Nothing is required (a Draft saves as it
 * is; the publish checks are Story 3.18); what is present is type-checked.
 *
 * The server pins the revisions itself: `endpoint_revision_id` and `data_source_revision` are read from Connector's Contracts when the
 * selection changes (or nothing is pinned yet) and are never taken from the client. An Endpoint that does not belong to the Data Source in
 * this Workspace is refused, and nothing is stored. Only Fixed parameters hold a per-block value; a bound row (date range, period, user
 * context) is resolved by the Endpoint and never stored here. The refresh interval is one of the System-settings intervals (whole seconds)
 * or `live` for a Live-capable Data Source: an unset list offers none, and the value already stored is always allowed to stay.
 */
final class DraftSource
{
    public const PRESETS = ['today', 'this_week', 'this_month', 'current_year', 'custom'];

    public const LIVE = 'live';

    public const VALUE_MAX = 2048;

    private const MAX_BINDINGS = 100;

    public function __construct(
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly RefreshIntervals $intervals,
    ) {}

    /**
     * @param  mixed  $input  the client's `source`
     * @param  array<string, mixed>  $stored  the Draft's stored `source` (its pins and refresh interval survive an unchanged selection)
     * @return array<string, mixed>
     *
     * @throws InvalidDraft
     */
    public function normalise(string $workspaceId, mixed $input, array $stored): array
    {
        if ($input === null || $input === []) {
            $input = [];
        }

        if (! is_array($input)) {
            throw new InvalidDraft(['config.source' => ['The data source settings must be an object.']], 'blocks.invalid_config');
        }

        $errors = [];
        $out = [];

        $sourceId = $this->id($input, 'data_source_id', $errors);
        $endpointId = $this->id($input, 'endpoint_id', $errors);

        $live = false;
        $endpoint = null;
        $storedSource = $stored['data_source_id'] ?? null;
        $storedEndpoint = $stored['endpoint_id'] ?? null;
        // An unchanged selection is what the Draft already holds: a Data Source or Endpoint gone or changed since must not stop it saving.
        $same = $sourceId !== null && $sourceId === $storedSource && $endpointId === $storedEndpoint;

        if ($sourceId !== null) {
            try {
                $source = $this->sources->find($workspaceId, $sourceId);
                $live = $source->liveCapable;
                $out['data_source_id'] = $sourceId;
                $pinnedSource = $source->revision;
            } catch (DataSourceNotFound) {
                if ($same) {
                    $out['data_source_id'] = $sourceId;
                } else {
                    $errors['config.source.data_source_id'] = ['Choose a data source that exists.'];
                }
            }
        }

        if ($endpointId !== null && ! isset($errors['config.source.endpoint_id'])) {
            if (! isset($out['data_source_id'])) {
                $errors['config.source.endpoint_id'] ??= [$sourceId === null ? 'Choose a data source first.' : 'Choose an endpoint of the data source.'];
            } else {
                try {
                    $endpoint = $this->endpoints->find($workspaceId, $out['data_source_id'], $endpointId);
                    $out['endpoint_id'] = $endpointId;
                } catch (DataSourceNotFound|EndpointNotFound) {
                    if ($same) {
                        $out['endpoint_id'] = $endpointId;
                    } else {
                        $errors['config.source.endpoint_id'] = ['Choose an endpoint of the data source.'];
                    }
                }
            }
        }

        // The pins: of what is chosen, when it is chosen. Each keeps what it was pinned to while its own choice is unchanged.
        if (isset($out['data_source_id'])) {
            $keep = $storedSource === $out['data_source_id'] && is_int($stored['data_source_revision'] ?? null);

            if ($keep) {
                $out['data_source_revision'] = $stored['data_source_revision'];
            } elseif (isset($pinnedSource)) {
                $out['data_source_revision'] = $pinnedSource;
            }
        }

        if (isset($out['endpoint_id'])) {
            $keep = $storedEndpoint === $out['endpoint_id'] && is_string($stored['endpoint_revision_id'] ?? null);

            if ($keep) {
                $out['endpoint_revision_id'] = $stored['endpoint_revision_id'];
            } elseif ($endpoint !== null) {
                $out['endpoint_revision_id'] = $endpoint->revisionId;
            }
        }

        $bindings = $this->bindings($input['bindings'] ?? null, $endpoint?->params, $same, $errors);

        if ($bindings !== []) {
            $out['bindings'] = $bindings;
        }

        $interval = $this->interval($input['refresh_interval'] ?? null, $live || ($same && (($stored['refresh_interval'] ?? null) === self::LIVE)), $stored['refresh_interval'] ?? null, $errors);

        if ($interval !== null) {
            $out['refresh_interval'] = $interval;
        }

        $range = $this->range($input['default_date_range'] ?? null, $errors);

        if ($range !== null) {
            $out['default_date_range'] = $range;
        }

        if (! array_key_exists('sample_out_of_date', $input) && is_bool($stored['sample_out_of_date'] ?? null)) {
            $out['sample_out_of_date'] = $stored['sample_out_of_date'];
        }

        if (isset($input['sample_out_of_date'])) {
            if (is_bool($input['sample_out_of_date'])) {
                $out['sample_out_of_date'] = $input['sample_out_of_date'];
            } else {
                $errors['config.source.sample_out_of_date'] = ['Choose on or off.'];
            }
        }

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $input
     * @param  array<string, list<string>>  $errors
     */
    private function id(array $input, string $key, array &$errors): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && Str::isUuid($value)) {
            return strtolower($value);
        }

        $errors["config.source.{$key}"] = [$key === 'data_source_id' ? 'Choose a data source that exists.' : 'Choose an endpoint of the data source.'];

        return null;
    }

    /**
     * @param  list<array{name: string, binding: string, value: string|null, kind: string}>|null  $params  the Endpoint's parameters; null when none is chosen
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>
     */
    private function bindings(mixed $input, ?array $params, bool $lenient, array &$errors): array
    {
        if ($input === null || $input === []) {
            return [];
        }

        if (! is_array($input) || count($input) > self::MAX_BINDINGS) {
            $errors['config.source.bindings'] = ['The parameter values must be an object.'];

            return [];
        }

        $fixed = [];

        foreach ($params ?? [] as $param) {
            if ($param['binding'] === 'fixed') {
                $fixed[$param['name']] = true;
            }
        }

        $out = [];

        foreach ($input as $name => $value) {
            $name = (string) $name;

            // The request's own middleware turns a blank string into null: a blank override is no override.
            if ($value === null) {
                continue;
            }

            if (! is_string($value) || mb_strlen($value) > self::VALUE_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                $errors["config.source.bindings.{$name}"] = ['Use up to '.self::VALUE_MAX.' characters, with no control characters.'];

                continue;
            }

            if (! isset($fixed[$name])) {
                // An unchanged selection drops a key the Endpoint no longer has as a Fixed parameter, instead of refusing the save.
                if ($lenient) {
                    continue;
                }

                $errors["config.source.bindings.{$name}"] = ['Only a fixed parameter of the chosen endpoint holds a value here.'];

                continue;
            }

            // A blank override is no override: the Endpoint's own fixed value applies.
            if (trim($value) !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function interval(mixed $value, bool $liveCapable, mixed $stored, array &$errors): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value === $stored && (is_int($value) || $value === self::LIVE)) {
            // What the Draft already has may stay even when the setting changed since (as an archived category may).
            if ($value !== self::LIVE || $liveCapable) {
                return $value;
            }
        }

        if ($value === self::LIVE) {
            if ($liveCapable) {
                return self::LIVE;
            }

            $errors['config.source.refresh_interval'] = ['This data source does not support Live refresh.'];

            return null;
        }

        $offered = $this->intervals->refreshIntervals();

        if (is_int($value) && $offered !== null && in_array($value, $offered, true)) {
            return $value;
        }

        $errors['config.source.refresh_interval'] = [$offered === null ? 'No refresh intervals are set up in System settings.' : 'Choose one of the refresh intervals in System settings.'];

        return null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>|null
     */
    private function range(mixed $value, array &$errors): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        $preset = is_array($value) ? ($value['preset'] ?? null) : null;

        if (! is_string($preset) || ! in_array($preset, self::PRESETS, true)) {
            $errors['config.source.default_date_range'] = ['Choose a default date range from the list.'];

            return null;
        }

        $out = ['preset' => $preset];

        if ($preset !== 'custom') {
            return $out;
        }

        foreach (['from', 'to'] as $edge) {
            $date = $value[$edge] ?? null;

            if ($date === null || $date === '') {
                continue;
            }

            if (! is_string($date) || ! self::isDate($date)) {
                $errors["config.source.default_date_range.{$edge}"] = ['Enter a real date as YYYY-MM-DD.'];

                continue;
            }

            $out[$edge] = $date;
        }

        if (isset($out['from'], $out['to']) && $out['from'] > $out['to']) {
            $errors['config.source.default_date_range.from'] = ['The start date cannot be after the end date.'];
        }

        return $out;
    }

    private static function isDate(string $date): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
