<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Operations\Operation;

/**
 * Starts a connection test (Story 2.5): an Operation of kind `connection_test` that `worker-connector` runs on queue
 * `fetch-interactive` as the requester's Workspace. The test is a GET to the Base URL with the form's default headers and
 * credentials; success is HTTP 2xx with a body that is JSON and within the size limit (Story 2.6). Nothing is called on the web tier.
 */
interface ConnectionTests
{
    public const KIND = 'connection_test';

    public const QUEUE = 'fetch-interactive';

    /**
     * Tests what the form holds now. With a saved `$dataSourceId` the stored secrets are used for any slot the form does not
     * supply; a typed secret is sealed into a transient row owned by the Operation and deleted when it ends. Nothing is
     * saved.
     *
     * @throws InvalidDataSource when the form cannot be tested (a credential the form needs has no value, https is required)
     * @throws DataSourceNotFound when `$dataSourceId` is not a Data Source of the Workspace
     * @throws ConnectionTestThrottled when the person or the Workspace is over its limit
     * @throws SecretsNotConfigured when a typed secret cannot be sealed
     */
    public function start(DataSourceActor $actor, #[\SensitiveParameter] DataSourceInput $input, ?string $dataSourceId = null): Operation;
}
