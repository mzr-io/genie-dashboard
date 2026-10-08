<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The only owner of fetch keys (Story 2.14; AD-7): `fk1:` + hex(sha256(JCS({v:1, workspace_id, endpoint_revision_id, data_source_revision,
 * params, ctx}))), RFC 8785 over strings, objects and the integers `v` and `data_source_revision`, so no float is ever hashed. `ctx` is
 * `"shared"`, or hex(HMAC-SHA256(digest_key_ws_v, "bound|" + JCS(attrs))) for user-bound data.
 *
 * A missing, null, empty or non-string bound value, or a digest key that cannot be used, produces no key and the reason
 * {@see FetchKeyResult::CONTEXT_MISSING}: the caller sends nothing.
 */
interface FetchKeyResolver
{
    public function resolve(FetchKeyInput $input): FetchKeyResult;
}
