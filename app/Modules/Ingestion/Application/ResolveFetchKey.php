<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\ContextDigest;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\FetchKeyResult;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The fetch key (Story 2.14; AD-7): `fk1:` + hex(sha256(JCS({v:1, workspace_id, endpoint_revision_id, data_source_revision, params, ctx}))).
 * Nothing else in the system builds one. Everything hashed is a string, an object or an integer, so no float can reach the hash.
 *
 * `params` is `{name: {t, v}}`; an absent binding is omitted. A parameter that is not well formed for its type is a programming error
 * ({@see InvalidArgumentException}), not user input. `ctx` is `"shared"` or, for user-bound data, hex(HMAC-SHA256(digest_key_ws_v,
 * "bound|" + JCS(attrs))), where `attrs` maps each user-binding reference to its value and gains `membership_id` when the Endpoint is scoped by
 * the caller. A missing, null, empty or non-string value, or an unusable digest key, gives no key and `access.context_missing`; the result
 * never holds a value, and a value is wiped from nothing here because none outlives the call.
 */
final class ResolveFetchKey implements FetchKeyResolver
{
    public const PREFIX = 'fk1:';

    private const NUMBER = '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D';

    private const DATE = '/\A(\d{4})-(\d{2})-(\d{2})\z/D';

    private const DATETIME = '/\A(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,9})?Z\z/D';

    public function __construct(private readonly ContextDigest $digest) {}

    public function resolve(FetchKeyInput $input): FetchKeyResult
    {
        $context = $this->context($input);

        if ($context === null) {
            return FetchKeyResult::missing();
        }

        $params = [];

        foreach ($input->params as $name => $param) {
            if ($param === null) {
                continue;
            }

            $params[(string) $name] = (object) ['t' => $param['t'], 'v' => $this->typed((string) $name, $param)];
        }

        if (! Str::isUuid($input->workspaceId) || ! Str::isUuid($input->endpointRevisionId) || $input->dataSourceRevision < 1) {
            throw new InvalidArgumentException('A fetch key needs a Workspace ID, an Endpoint revision ID and a Data Source revision.');
        }

        $canonical = Jcs::encode((object) [
            'v' => 1,
            'workspace_id' => strtolower($input->workspaceId),
            'endpoint_revision_id' => strtolower($input->endpointRevisionId),
            'data_source_revision' => $input->dataSourceRevision,
            'params' => (object) $params,
            'ctx' => $context,
        ]);

        return FetchKeyResult::key(self::PREFIX.hash('sha256', $canonical));
    }

    /** `"shared"`, the keyed digest of the bound attributes, or null when a bound value or the key is missing. */
    private function context(FetchKeyInput $input): ?string
    {
        if ($input->bound === null) {
            return 'shared';
        }

        $attrs = [];

        foreach ($input->bound as $reference => $value) {
            if (! is_string($value) || $value === '') {
                return null;
            }

            $attrs[(string) $reference] = $value;
        }

        if ($input->scopeByCaller) {
            if ($input->membershipId === null || ! Str::isUuid($input->membershipId)) {
                return null;
            }

            $attrs['membership_id'] = strtolower($input->membershipId);
        }

        $key = $this->digest->key(strtolower($input->workspaceId));

        if ($key === null || $key === '') {
            return null;
        }

        try {
            return hash_hmac('sha256', 'bound|'.Jcs::encode((object) $attrs), $key);
        } catch (InvalidArgumentException) {
            // A value that is not UTF-8 is not a usable value.
            return null;
        } finally {
            sodium_memzero($key);
        }
    }

    /** @param  array{t: string, v: string}  $param */
    private function typed(string $name, array $param): string
    {
        $type = $param['t'];
        $value = $param['v'];

        $valid = match ($type) {
            'string' => mb_check_encoding($value, 'UTF-8'),
            'number' => preg_match(self::NUMBER, $value) === 1,
            'bool' => $value === 'true' || $value === 'false',
            'date' => preg_match(self::DATE, $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            'datetime' => preg_match(self::DATETIME, $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException("The parameter {$name} is not a well-formed {$type}.");
        }

        return $value;
    }
}
