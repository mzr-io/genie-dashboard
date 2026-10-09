<?php

namespace App\Modules\Connector\Contracts;

/** Where each token request is recorded (Story 2.7): one `sync_runs` row of kind `oauth_token`. Never a token, secret or body. */
interface TokenRequestLog
{
    public const KIND = 'oauth_token';

    /** @param  string|null  $code  the user code of a failure (`auth-failed`, `host-not-allowlisted`, ...), null on success */
    public function record(string $workspaceId, ?string $dataSourceId, string $tokenUrl, ?int $httpStatus, int $latencyMs, int $bytes, ?string $code, \DateTimeInterface $startedAt): void;
}
