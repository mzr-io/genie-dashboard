<?php

namespace App\Modules\Connector\Contracts;

/**
 * The Workspace's Data Sources (Story 2.3), in the caller's Workspace transaction. A change checks the Base URL's
 * scheme, host and port against the host allowlist ({@see HostAllowlist::isAllowed}) and the `require_https` rule,
 * writes `connector.data_source.created` or `.updated` in the same transaction, and an update compares the caller's
 * `revision` under a row lock and bumps it. Nothing here calls, resolves or connects to any host.
 */
interface DataSources
{
    /** The resource type a Data Source's soft edit lock is kept under (Platform\EditLock, Story 2.8). */
    public const LOCK_TYPE = 'data_source';

    public function list(string $workspaceId, DataSourceQuery $query): DataSourcePage;

    /** @throws DataSourceNotFound */
    public function find(string $workspaceId, string $id): DataSource;

    /**
     * Whether the Base URL may be used: the allowlist and `require_https` only, so no DNS lookup and no request.
     *
     * @param  string  $field  the field the refusal is reported on: `base_url`, or `oauth_token_url` for an OAuth2 token URL (Story 2.7)
     *
     * @throws InvalidDataSource on `$field`
     */
    public function checkUrl(string $workspaceId, DataSourceUrl $url, string $field = 'base_url'): void;

    /**
     * @param  (\Closure(): bool)|null  $confirm  asked when the change needs the Admin's password (a secret value, or a credential type other than `none`); false refuses it
     *
     * @throws InvalidDataSource
     * @throws ConfirmationRefused
     * @throws SecretsNotConfigured when a secret is to be sealed and the platform key is not set
     */
    public function register(DataSourceActor $actor, #[\SensitiveParameter] DataSourceInput $input, ?\Closure $confirm = null): DataSource;

    /**
     * @param  (\Closure(): bool)|null  $confirm  asked when a secret value is set, replaced or removed, or the auth type changes
     * @param  int|null  $lockEpoch  the soft lock's epoch the caller was granted (Story 2.8): compared with the row's under the same row lock as `$revision`
     * @param  (\Closure(): bool)|null  $holdsLock  asked after the epoch matches: whether the caller's lock token still holds the lock
     *
     * @throws DataSourceLockLost when the epoch is stale or the lock is no longer held; nothing is sealed or written
     * @throws ConfirmationRefused
     * @throws SecretsNotConfigured
     * @throws DataSourceNotFound
     * @throws DataSourceRevisionConflict
     * @throws InvalidDataSource
     */
    public function update(DataSourceActor $actor, string $id, #[\SensitiveParameter] DataSourceInput $input, int $revision, ?\Closure $confirm = null, ?int $lockEpoch = null, ?\Closure $holdsLock = null): DataSource;

    /** The platform ceilings the limits are checked against. */
    public function ceilings(): DataSourceCeilings;

    /** The deployment's maximum for a retention window, in days; null when it is unset or malformed (then `window` is refused). */
    public function maxRetentionWindowDays(): ?int;
}
