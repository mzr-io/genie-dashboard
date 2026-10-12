<?php

namespace App\Modules\Blocks\Contracts;

/**
 * A Fetch as user result the wizard asks to read or preview from (Story 3.17): the `fetch_as_user` Operation and the Endpoint it fetched.
 * Nothing about the member is carried: the Operation is read only by the Admin that started it, for the Endpoint at the revision it fetched.
 */
final readonly class UserSampleRef
{
    public function __construct(
        public string $operationId,
        public string $dataSourceId,
        public string $endpointId,
        /** Whether the reader holds `data.preview_as_user` (the route has already refused, and audited, a caller that does not). */
        public bool $mayPreviewAsUser,
    ) {}
}
